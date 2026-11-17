<?php

namespace App\Modules\Patient\Services;

use App\Models\User;
use App\Modules\Branch\Interfaces\BranchRepositoryInterface;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Support\BranchCodeAlias;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\Patient\Exceptions\PatientRmNumberUnavailableException;
use App\Modules\Patient\Interfaces\PatientRepositoryInterface;
use App\Modules\Patient\Interfaces\PatientRmPaperCardRegistrationRepositoryInterface;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Models\PatientRmPaperCardRegistration;
use App\Modules\PatientMerge\Services\PatientMergeGuard;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Support\Clinical\ClinicalClock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PatientService
{
    /**
     * Rule 244 §2.4 — how many times a registration RETRIES with the next
     * automatic number after the unique index refused a Nomor RM collision: one
     * attempt plus three retries (owner wording "retried up to 3 times"), then 422.
     */
    public const UNIQUE_RETRIES = 3;

    /**
     * Rule 244 §2.1/§2.3 — identity fields only the server writes: dropped from a
     * registration payload and from an edit. An edit also keeps `branch_id`.
     */
    private const SERVER_OWNED_IDENTITY_FIELDS = ['medical_record_number', 'manual_rm_number', 'registered_at'];

    /**
     * Rule 244 §4 (plan defaults 1 and 4) — the one permission that may store a
     * typed Nomor RM: an old paper-card number at registration, and the legacy
     * patient CSV commit. Supervisor RME; Super Admin through '*'.
     */
    public const PAPER_CARD_PERMISSION = 'register_patient_paper_card_rm';

    /** Rule 244 §4 — the paper-card request fields; never read on the automatic path. */
    public const PAPER_CARD_FIELDS = ['paper_card_year', 'paper_card_sequence', 'paper_card_reason_code', 'paper_card_reason_note'];

    /** Rule 244 §4.3 (PR-3b) — a note's bounds when one is given, enforced here for every actor. */
    public const PAPER_CARD_REASON_MIN = 10;

    public const PAPER_CARD_REASON_MAX = 500;

    /** Rule 244 §4.3 — the reason code whose note is required. */
    public const PAPER_CARD_REASON_OTHER = 'LAINNYA';

    /**
     * Rule 244 §4.3 (PR-3b review B1) — a note is valid UTF-8 without control
     * characters (tab, line feed and carriage return allowed). With `u`, invalid
     * UTF-8 fails the match too.
     */
    public const PAPER_CARD_NOTE_PATTERN = '/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*$/u';

    /** Rule 244 §4.3 (PR-3b review B8) — a read of the stored notes; ids only. */
    public const AUDIT_PAPER_CARD_REASON_VIEWED = 'PATIENT_RM_PAPER_CARD_REASON_VIEWED';

    /**
     * Rule 244 §4.3 (owner decision 2026-11-17, "PAPER-CARD REASON: store text,
     * restricted") — the one permission that READS a stored paper-card reason:
     * Supervisor RME and Owner; Super Admin through Gate::before.
     */
    public const PAPER_CARD_REASON_READ_PERMISSION = 'view_patient_rm_paper_card_reasons';

    /** Rule 244 §4 — the oldest year a paper card may carry. */
    public const PAPER_CARD_MIN_YEAR = 1900;

    /** Rule 244 §4 — the audit action; its payload carries ids and the reason length only. */
    public const AUDIT_PAPER_CARD_REGISTERED = 'PATIENT_RM_PAPER_CARD_REGISTERED';

    /** Rule 244 §4 — no number in the message: the operator typed it, nobody else needs it. */
    private const PAPER_CARD_TAKEN_MESSAGE = 'Nomor RM kartu lama ini sudah terdaftar (termasuk penulisan lain, pasien terhapus, atau alias penggabungan).';

    public function __construct(
        private readonly PatientRepositoryInterface $patients,
        // DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §2) — the only
        // issuer of a new patient's Nomor RM.
        private readonly PatientRmNumberAllocator $rmAllocator,
        // Rule 244 §4 — composes the paper-card number from its parts.
        private readonly PatientMedicalRecordNumberService $rmNumbers,
        private readonly AuditLogService $audit,
        private readonly BranchRepositoryInterface $branches,
        // FIX-03 — the clinical calendar authority for the registration day.
        private readonly ClinicalClock $clock,
        // FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — a merged patient is
        // read-only; identity edits go to the canonical patient.
        private readonly PatientMergeGuard $mergeGuard,
        // PR-2 review F2 (CG-05) — a new patient's branch must be one the actor
        // is working: a request branch may only narrow.
        private readonly RmeWorkingBranchScope $workingBranchScope,
        // Rule 244 §4.3 (PR-3b) — the immutable paper-card reason rows.
        private readonly PatientRmPaperCardRegistrationRepositoryInterface $paperCardRegistrations,
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
     *  - A branch is required (active, RME-enabled; the allocator refuses MAIN)
     *    and must be inside `$actor`'s working-branch scope
     *    ({@see RmeWorkingBranchScope::allows()}; CG-05, 403 otherwise). The
     *    master form passes its actor. Kunjungan → Pasien Baru passes none: its
     *    branch was already pinned by StoreClinicVisitRequest and re-asserted by
     *    ClinicVisitService::resolveBranchId() for the registrar, and a null
     *    actor here means "every active RME branch" (the scope's own answer).
     *    The Sprint 23.3 UTC fallback generator and the free override are retired.
     *  - The allocator locks the branch-year counter row in THIS transaction; the
     *    insert runs in a savepoint, so a unique violation (a writer that bypassed
     *    the lock) rolls back the insert only and the next number is tried under
     *    the same held lock, up to {@see self::UNIQUE_RETRIES} retries; then 422.
     *    Only a Nomor RM collision is retried; a KTP collision is a 422 at once;
     *    any other unique violation is rethrown untouched.
     *    Any refusal is a ValidationException (422), never a 500, and the whole
     *    transaction — counter included — rolls back.
     */
    public function create(array $data, ?User $actor = null): Patient
    {
        foreach ([...self::SERVER_OWNED_IDENTITY_FIELDS, ...self::PAPER_CARD_FIELDS] as $field) {
            unset($data[$field]);
        }

        $branch = $this->registrationBranch($data['branch_id'] ?? null);
        $data['branch_id'] = (int) $branch->getKey();

        if (! $this->workingBranchScope->allows($actor, (int) $branch->getKey())) {
            throw new AuthorizationException('Pasien baru hanya dapat didaftarkan di cabang tempat Anda bertugas.');
        }

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
     * DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §4; plan default 1,
     * owner 2026-11-17) — register a patient under the Nomor RM printed on an
     * old paper card, the only exception to the automatic number.
     *
     *  - Only an actor holding {@see self::PAPER_CARD_PERMISSION} (Supervisor
     *    RME; Super Admin through '*') and `manage patients`, re-asserted here
     *    whatever the route or form said (CG-06).
     *  - A structured reason code from config `patient.paper_card_reasons` and
     *    an optional note of {@see self::PAPER_CARD_REASON_MIN}..
     *    {@see self::PAPER_CARD_REASON_MAX} characters, required for
     *    {@see self::PAPER_CARD_REASON_OTHER} (rule 244 §4.3), a year from
     *    {@see self::PAPER_CARD_MIN_YEAR} to the clinical year, a sequence of one
     *    to eight digits that is not zero — enforced HERE, so they bind Super
     *    Admin too (Gate::before only answers the permission question).
     *  - The number is DG-{branch's canonical code}-{year}-{sequence as printed};
     *    it is stored only after {@see PatientRmNumberAllocator::claimExisting()}
     *    has locked that branch-year's counter and found it free in every
     *    spelling (alias codes, padding, case, soft-deleted, merge aliases); the
     *    claim raises the counter to it (continue after the highest), within the
     *    claim cap (rule 244 §2.6: a current-year jump past +1000 or 9999 is a
     *    422 that sends the case to Supervisor RME). The branch must be inside
     *    the actor's working-branch scope, as on the automatic path.
     *  - The code and the note are stored in their own immutable row
     *    ({@see PatientRmPaperCardRegistrationRepositoryInterface}) in the SAME
     *    transaction as the claim, so a refused claim stores no reason.
     *  - Audited without the number and without the note: patient id, branch
     *    id, actor id, the registration id and the reason code.
     *  - `registered_at` is the clinical day the patient enters the system; the
     *    card's own date is not invented.
     */
    public function createWithPaperCardNumber(
        array $data,
        User $actor,
        #[\SensitiveParameter] int $year,
        #[\SensitiveParameter] string $sequence,
        string $reasonCode,
        // Re-review F1 — never in a logged stack trace, whatever zend.exception_ignore_args says.
        #[\SensitiveParameter] ?string $reasonNote,
    ): Patient {
        if (! $actor->can(self::PAPER_CARD_PERMISSION) || ! $actor->can('manage patients')) {
            throw new AuthorizationException('Nomor RM kartu lama hanya dapat didaftarkan oleh Supervisor RME.');
        }

        $reasonCode = trim($reasonCode);
        $reasonNote = trim((string) $reasonNote);
        $reasonNote = $reasonNote === '' ? null : $reasonNote;
        $sequence = trim($sequence);
        $today = $this->clock->today();

        if (! array_key_exists($reasonCode, $this->paperCardReasons())) {
            throw ValidationException::withMessages(['paper_card_reason_code' => 'Pilih alasan memakai Nomor RM kartu lama dari daftar.']);
        }

        if ($reasonNote === null && $reasonCode === self::PAPER_CARD_REASON_OTHER) {
            throw ValidationException::withMessages(['paper_card_reason_note' => 'Catatan wajib diisi untuk alasan Lainnya.']);
        }

        // PR-3b review B1 — checked here too, so it binds Super Admin and any
        // caller: invalid UTF-8 or a control character would make the database
        // refuse the insert with an error that carries the note.
        if ($reasonNote !== null && (! mb_check_encoding($reasonNote, 'UTF-8') || preg_match(self::PAPER_CARD_NOTE_PATTERN, $reasonNote) !== 1)) {
            throw ValidationException::withMessages(['paper_card_reason_note' => 'Catatan berisi karakter yang tidak valid.']);
        }

        if ($reasonNote !== null && (mb_strlen($reasonNote) < self::PAPER_CARD_REASON_MIN || mb_strlen($reasonNote) > self::PAPER_CARD_REASON_MAX)) {
            throw ValidationException::withMessages([
                'paper_card_reason_note' => sprintf('Catatan %d sampai %d karakter.', self::PAPER_CARD_REASON_MIN, self::PAPER_CARD_REASON_MAX),
            ]);
        }

        if ($year < self::PAPER_CARD_MIN_YEAR || $year > (int) $today->format('Y')) {
            throw ValidationException::withMessages(['paper_card_year' => 'Tahun pada kartu lama tidak valid.']);
        }

        if (preg_match('/^[0-9]{1,'.PatientRmNumberAllocator::MAX_DIGITS.'}$/', $sequence) !== 1 || (int) $sequence < 1) {
            throw ValidationException::withMessages(['paper_card_sequence' => 'Nomor urut kartu lama hanya angka (1–8 digit) dan bukan nol.']);
        }

        foreach ([...self::SERVER_OWNED_IDENTITY_FIELDS, ...self::PAPER_CARD_FIELDS] as $field) {
            unset($data[$field]);
        }

        $branch = $this->registrationBranch($data['branch_id'] ?? null);
        $data['branch_id'] = (int) $branch->getKey();

        // Rule 244 §2.1 (CG-05) — the same working-branch scope as the automatic path.
        if (! $this->workingBranchScope->allows($actor, (int) $branch->getKey())) {
            throw new AuthorizationException('Pasien baru hanya dapat didaftarkan di cabang tempat Anda bertugas.');
        }

        try {
            return DB::transaction(function () use ($data, $branch, $actor, $year, $sequence, $reasonCode, $reasonNote, $today): Patient {
                $number = $this->rmNumbers->compose((string) BranchCodeAlias::canonicalize($branch->code), $year, $sequence);

                if (! $this->rmAllocator->claimExisting($branch, $number)) {
                    throw ValidationException::withMessages(['paper_card_sequence' => self::PAPER_CARD_TAKEN_MESSAGE]);
                }

                $data['registered_at'] = $today->toDateString();
                $data['manual_rm_number'] = $sequence;
                $data['medical_record_number'] = $number;

                try {
                    $patient = DB::transaction(fn (): Patient => $this->patients->create($data));
                } catch (UniqueConstraintViolationException $e) {
                    // Review I3 — as on the automatic path (§2.4): KTP is its own
                    // 422, only a Nomor RM violation is "taken" (no retry: the
                    // number is the card's), anything else is rethrown untouched.
                    $driverMessage = (string) ($e->errorInfo[2] ?? '');

                    if (str_contains($driverMessage, 'ktp_number')) {
                        throw ValidationException::withMessages(['ktp_number' => 'Nomor KTP sudah terdaftar pada pasien lain.']);
                    }

                    if (! str_contains($driverMessage, 'medical_record_number')) {
                        throw $e;
                    }

                    throw ValidationException::withMessages(['paper_card_sequence' => self::PAPER_CARD_TAKEN_MESSAGE]);
                }

                // Rule 244 §4.3 (PR-3b) — the reason, immutable, in this transaction.
                $registration = $this->paperCardRegistrations->record([
                    'patient_id' => (int) $patient->getKey(),
                    'branch_id' => (int) $branch->getKey(),
                    'year' => $year,
                    'reason_code' => $reasonCode,
                    'reason_note' => $reasonNote,
                    'actor_user_id' => (int) $actor->getKey(),
                ]);

                // Ids and the code only: never the note, never the number.
                $this->audit->log('mst_patients', (int) $patient->getKey(), self::AUDIT_PAPER_CARD_REGISTERED, null, [
                    'patient_id' => (int) $patient->getKey(),
                    'branch_id' => (int) $branch->getKey(),
                    'actor_user_id' => (int) $actor->getKey(),
                    'registration_id' => (int) $registration->getKey(),
                    'reason_code' => $reasonCode,
                ], $actor);

                return $patient;
            });
        } catch (PatientRmNumberUnavailableException $e) {
            throw ValidationException::withMessages(['paper_card_sequence' => $e->getMessage()]);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'paper_card_sequence' => 'Nomor RM kartu lama tidak dapat dibentuk untuk cabang ini. Hubungi Super Admin.',
            ]);
        }
    }

    /**
     * Rule 244 §4.3 — the configured paper-card reasons, code => Indonesian label.
     *
     * @return array<string, string>
     */
    public function paperCardReasons(): array
    {
        $reasons = config('patient.paper_card_reasons', []);

        return is_array($reasons) ? array_map('strval', $reasons) : [];
    }

    /**
     * Rule 244 §4.3 (owner decision 2026-11-17, "PAPER-CARD REASON: store text,
     * restricted") — a patient's stored paper-card reasons, notes included.
     * Re-asserts {@see self::PAPER_CARD_REASON_READ_PERMISSION} (403; Super Admin
     * passes through Gate::before) and the actor's working-branch scope (CG-05):
     * a patient outside it is 404, so the reader does not even learn a reason
     * exists. Only rows inside the scope are returned: the patient's own and,
     * read only, those of patients merged INTO it (PR-3b review B4). A read that
     * returns rows is audited with ids only (review B8).
     *
     * @return Collection<int, PatientRmPaperCardRegistration>
     */
    public function paperCardRegistrationsFor(Patient $patient, User $actor): Collection
    {
        $this->assertMayReadPaperCardReasons($actor);

        if (! $this->workingBranchScope->allows($actor, $patient->branch_id !== null ? (int) $patient->branch_id : null)) {
            throw new NotFoundHttpException;
        }

        $registrations = $this->paperCardRegistrations->forPatient((int) $patient->getKey(), $this->workingBranchScope->branchIdsFor($actor));

        // PR-3b review B8 — who read the notes, and which rows: ids only.
        if ($registrations->isNotEmpty()) {
            $this->audit->log('mst_patients', (int) $patient->getKey(), self::AUDIT_PAPER_CARD_REASON_VIEWED, null, [
                'patient_id' => (int) $patient->getKey(),
                'actor_user_id' => (int) $actor->getKey(),
                'registration_ids' => $registrations->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            ], $actor);
        }

        return $registrations;
    }

    /**
     * Rule 244 §4.3 — every stored paper-card registration in the actor's
     * working-branch scope, newest first, WITHOUT the note (it is shown only on
     * the patient's own page). Same permission re-assertion.
     */
    public function paperCardRegistrationList(User $actor, int $perPage = 25): LengthAwarePaginator
    {
        $this->assertMayReadPaperCardReasons($actor);

        return $this->paperCardRegistrations->paginateForBranches($this->workingBranchScope->branchIdsFor($actor), $perPage);
    }

    private function assertMayReadPaperCardReasons(User $actor): void
    {
        if (! $actor->can(self::PAPER_CARD_REASON_READ_PERMISSION)) {
            throw new AuthorizationException('Alasan Nomor RM kartu lama hanya dapat dibaca Supervisor RME dan Owner.');
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
     * the next number until the retries run out; any other unique violation is
     * not ours to interpret and is rethrown (review F4). Only the driver's own
     * message is read, for the column name: the exception message also carries
     * the SQL and its bindings, so it is never shown or logged here.
     */
    private function refuseUniqueViolation(UniqueConstraintViolationException $e, int $attempt): void
    {
        $driverMessage = (string) ($e->errorInfo[2] ?? '');

        if (str_contains($driverMessage, 'ktp_number')) {
            throw ValidationException::withMessages(['ktp_number' => 'Nomor KTP sudah terdaftar pada pasien lain.']);
        }

        if (! str_contains($driverMessage, 'medical_record_number')) {
            throw $e;
        }

        if ($attempt > self::UNIQUE_RETRIES) {
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
