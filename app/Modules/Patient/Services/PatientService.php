<?php

namespace App\Modules\Patient\Services;

use App\Modules\Branch\Interfaces\BranchRepositoryInterface;
use App\Modules\Branch\Models\Branch;
use App\Modules\Patient\Exceptions\PatientRmNumberUnavailableException;
use App\Modules\Patient\Interfaces\PatientRepositoryInterface;
use App\Modules\Patient\Models\Patient;
use App\Modules\PatientMerge\Services\PatientMergeGuard;
use App\Support\Clinical\ClinicalClock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PatientService
{
    /**
     * Rule 244 §2.4 — how many automatic numbers one registration tries when the
     * unique index refuses the insert, before it answers 422.
     */
    public const UNIQUE_RETRY_ATTEMPTS = 3;

    /**
     * Rule 244 §2.1/§2.3 — identity fields only the server writes: dropped from a
     * registration payload and from an edit. An edit also keeps `branch_id`.
     */
    private const SERVER_OWNED_IDENTITY_FIELDS = ['medical_record_number', 'manual_rm_number', 'registered_at'];

    public function __construct(
        private readonly PatientRepositoryInterface $patients,
        // DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §2) — the only
        // issuer of a new patient's Nomor RM.
        private readonly PatientRmNumberAllocator $rmAllocator,
        private readonly BranchRepositoryInterface $branches,
        // FIX-03 — the clinical calendar authority for the registration day.
        private readonly ClinicalClock $clock,
        // FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — a merged patient is
        // read-only; identity edits go to the canonical patient.
        private readonly PatientMergeGuard $mergeGuard,
    ) {}

    public function list(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        return $this->patients->paginate($filters, $perPage);
    }

    public function listAll(): Collection
    {
        return $this->patients->listAll();
    }

    public function find(int $id): ?Patient
    {
        return $this->patients->findById($id);
    }

    /**
     * Read-only preview of legacy patients without a Cabang RME (branch_id null).
     * Never mutates data — Sprint 23 Phase 23.10 explicitly defers any RM/branch
     * backfill to a future controlled migration phase.
     *
     * @return Collection<int, Patient>
     */
    public function legacyWithoutBranch(): Collection
    {
        return $this->patients->legacyWithoutBranch();
    }

    /**
     * DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (owner decision 2026-11-17,
     * "Auto, read-only"; rule 244 §2) — register a new patient with an AUTOMATIC
     * Nomor RM. Both registration forms (Master Data → Tambah Pasien and Kunjungan
     * → Pasien Baru, through ClinicVisitService) end here.
     *
     *  - The number, the manual sequence and the registration date are never
     *    taken from `$data`: `registered_at` is the clinic's calendar day
     *    ({@see ClinicalClock}, so 00:30 WITA on 1 January is the new year while
     *    UTC is still 31 December) and the number comes from
     *    {@see PatientRmNumberAllocator::allocate()} for that branch and year.
     *  - A branch is required (active, RME-enabled; the allocator refuses MAIN).
     *    The Sprint 23.3 UTC fallback generator and the free override are retired.
     *  - The allocator locks the branch-year counter row in THIS transaction; the
     *    insert runs in a savepoint, so a unique violation (a writer that bypassed
     *    the lock) rolls back the insert only and the next number is tried under
     *    the same held lock, up to {@see self::UNIQUE_RETRY_ATTEMPTS}; then 422.
     *    Any refusal is a ValidationException (422), never a 500, and the whole
     *    transaction — counter included — rolls back.
     */
    public function create(array $data): Patient
    {
        foreach (self::SERVER_OWNED_IDENTITY_FIELDS as $field) {
            unset($data[$field]);
        }

        $branch = $this->registrationBranch($data['branch_id'] ?? null);
        $data['branch_id'] = (int) $branch->getKey();

        try {
            return DB::transaction(function () use ($data, $branch): Patient {
                $today = $this->clock->today();
                $year = (int) $today->format('Y');
                $data['registered_at'] = $today->toDateString();

                for ($attempt = 1; ; $attempt++) {
                    $data['medical_record_number'] = $this->rmAllocator->allocate($branch, $year);

                    try {
                        return DB::transaction(fn (): Patient => $this->patients->create($data));
                    } catch (UniqueConstraintViolationException $e) {
                        $this->refuseUniqueViolation($e, $attempt);
                    }
                }
            });
        } catch (PatientRmNumberUnavailableException $e) {
            throw ValidationException::withMessages(['medical_record_number' => $e->getMessage()]);
        }
    }

    /**
     * Rule 244 §2.3 (owner default 5) — an edit never changes the Nomor RM, the
     * branch it was issued under or the registration date; there is no
     * correction tool for existing numbers.
     */
    public function update(Patient $patient, array $data): Patient
    {
        $this->mergeGuard->assertNotMerged($patient, 'name');

        foreach ([...self::SERVER_OWNED_IDENTITY_FIELDS, 'branch_id'] as $field) {
            unset($data[$field]);
        }

        return DB::transaction(function () use ($patient, $data) {
            // Re-checked under the row lock: the model above may predate a
            // merge that committed while this request was in flight.
            $this->mergeGuard->assertNotMergedLocked((int) $patient->id, 'name');

            return $this->patients->update($patient, $data);
        });
    }

    /** The active, RME-enabled branch a new patient is registered under. */
    private function registrationBranch(mixed $branchId): Branch
    {
        $branch = filled($branchId) ? $this->branches->findById((int) $branchId) : null;

        if ($branch === null || ! $branch->is_active || ! $branch->is_rme_enabled) {
            throw ValidationException::withMessages([
                'branch_id' => 'Pilih cabang RME yang aktif. Nomor RM pasien baru dibuat otomatis untuk cabang tersebut.',
            ]);
        }

        return $branch;
    }

    /**
     * Rule 244 §2.4 — a refused insert. A KTP/NIK collision is reported at once
     * (another number would not help); a Nomor RM collision lets the caller try
     * the next number until the attempts run out. Only the driver's own message is
     * read, for the column name: the exception message also carries the SQL and
     * its bindings, so it is never shown or logged here.
     */
    private function refuseUniqueViolation(UniqueConstraintViolationException $e, int $attempt): void
    {
        if (str_contains((string) ($e->errorInfo[2] ?? ''), 'ktp_number')) {
            throw ValidationException::withMessages(['ktp_number' => 'Nomor KTP sudah terdaftar pada pasien lain.']);
        }

        if ($attempt >= self::UNIQUE_RETRY_ATTEMPTS) {
            throw ValidationException::withMessages([
                'medical_record_number' => 'Nomor RM otomatis bentrok dengan pendaftaran lain. Muat ulang halaman lalu simpan kembali.',
            ]);
        }
    }

    public function delete(Patient $patient): bool
    {
        // A merged source patient is evidence of the merge and keeps its
        // Nomor RM reserved; it is never deleted.
        $this->mergeGuard->assertNotMerged($patient, 'patient');

        return DB::transaction(function () use ($patient): bool {
            $this->mergeGuard->assertNotMergedLocked((int) $patient->id, 'patient');

            return $this->patients->delete($patient);
        });
    }

    public function activate(Patient $patient): Patient
    {
        $this->mergeGuard->assertNotMerged($patient, 'patient');

        return DB::transaction(function () use ($patient): Patient {
            $this->mergeGuard->assertNotMergedLocked((int) $patient->id, 'patient');

            return $this->patients->setActiveStatus($patient, true);
        });
    }

    public function deactivate(Patient $patient): Patient
    {
        return $this->patients->setActiveStatus($patient, false);
    }
}
