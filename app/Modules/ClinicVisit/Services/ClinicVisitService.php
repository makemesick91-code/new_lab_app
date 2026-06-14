<?php

namespace App\Modules\ClinicVisit\Services;

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Interfaces\ClinicVisitRepositoryInterface;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Patient\Services\PatientService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ClinicVisitService
{
    public function __construct(
        private readonly ClinicVisitRepositoryInterface $visits,
        private readonly BranchContext $branchContext,
        private readonly PatientService $patients,
        private readonly BranchService $branches,
    ) {}

    /**
     * Daftar Kunjungan RME lists visits across the operational "Cabang RME" set
     * (active RME-enabled branches), NOT a single BranchContext fallback branch.
     * MAIN is excluded because it is not RME-enabled. An optional `branch_id`
     * filter narrows the scope to a single RME branch; any value outside the
     * RME set is ignored and the full RME scope is used (Sprint 23 Phase 23.9.3).
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->visits->paginateForBranches(
            $this->scopeBranchIds($filters['branch_id'] ?? null),
            $filters,
            $perPage,
        );
    }

    /**
     * Resolve the branch IDs a visit list / count should be scoped to.
     * Returns the single requested branch when it is a valid RME branch,
     * otherwise the full active RME-enabled set.
     *
     * @return array<int, int>
     */
    private function scopeBranchIds(?int $branchFilter): array
    {
        $allowed = $this->branches->rmeEnabledIds();

        if ($branchFilter !== null && in_array($branchFilter, $allowed, true)) {
            return [$branchFilter];
        }

        return $allowed;
    }

    public function find(int $id): ?ClinicVisit
    {
        return $this->visits->findInBranch($this->branchContext->requireId(), $id);
    }

    public function create(array $data): ClinicVisit
    {
        return DB::transaction(function () use ($data) {
            // RME "Klinik" = "Cabang RME". The visit branch follows the selected
            // RME-enabled branch (Sprint 23 Phase 23.9.1). For new patients the
            // patient and the visit share the same branch. The BranchContext
            // fallback only applies to legacy callers that submit no branch.
            $branchId = $this->resolveBranchId($data);
            $data['branch_id'] = $branchId;

            $data = $this->resolvePatient($data);

            unset($data['branch_id']);

            $visitDate = Carbon::today();

            $queueNumber = $this->visits->nextQueueNumber($branchId, $visitDate);
            $visitNumber = $this->generateUniqueVisitNumber($branchId, $visitDate);

            return $this->visits->create(array_merge($data, [
                'branch_id' => $branchId,
                'visit_date' => $visitDate->toDateString(),
                'queue_number' => $queueNumber,
                'visit_number' => $visitNumber,
                'status' => ClinicVisit::STATUS_REGISTERED,
                'created_by' => Auth::id(),
            ]));
        });
    }

    /**
     * Generate a globally unique visit number for the visit date and branch.
     *
     * Format:
     *   VIS-{BRANCHCODE}-{YYYYMMDD}-{NNN}
     *
     * Queue numbers remain branch/date scoped, while visit_number has a global
     * unique constraint. Including the branch code prevents cross-branch
     * collisions such as multiple branches generating VIS-YYYYMMDD-001.
     */
    private function generateUniqueVisitNumber(int $branchId, Carbon $visitDate): string
    {
        $branchCode = $this->resolveVisitBranchCode($branchId);
        $prefix = 'VIS-'.$branchCode.'-'.$visitDate->format('Ymd').'-';

        $maxSequence = ClinicVisit::query()
            ->where('visit_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->pluck('visit_number')
            ->map(function (string $visitNumber) use ($prefix): int {
                $suffix = str_replace($prefix, '', $visitNumber);

                return ctype_digit($suffix) ? (int) $suffix : 0;
            })
            ->max() ?? 0;

        $sequence = $maxSequence + 1;

        do {
            $candidate = $prefix.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
            $sequence++;
        } while (ClinicVisit::query()->where('visit_number', $candidate)->exists());

        return $candidate;
    }

    /**
     * Resolve a safe branch code for visit_number.
     *
     * Prefer explicit code fields when available. If the branch table does not
     * have a code column yet, fall back to the branch name, then BR{id}.
     */
    private function resolveVisitBranchCode(int $branchId): string
    {
        $branch = Branch::query()->find($branchId);

        $rawCode = $branch?->code
            ?? $branch?->branch_code
            ?? $branch?->slug
            ?? $branch?->name
            ?? 'BR'.$branchId;

        $code = preg_replace('/[^A-Z0-9]/', '', Str::upper((string) $rawCode));

        if ($code === null || $code === '') {
            $code = 'BR'.$branchId;
        }

        return Str::limit($code, 8, '');
    }

    /**
     * Resolve the branch the visit belongs to ("Klinik" = "Cabang RME").
     *
     * Precedence:
     *   1. New-patient mode: the branch chosen for the new patient, so the
     *      patient and the visit always share one RME branch.
     *   2. Existing-patient mode: an explicitly selected RME branch.
     *
     * Never falls back to BranchContext/MAIN — RME visits must belong to an
     * active RME-enabled branch (Sprint 23 Phase 23.10 hardening).
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveBranchId(array $data): int
    {
        $branchId = null;

        if (($data['patient_mode'] ?? 'existing') === 'new') {
            $branchId = $data['new_patient']['branch_id'] ?? null;
        } else {
            $branchId = $data['branch_id'] ?? null;
        }

        if (! $branchId) {
            throw ValidationException::withMessages([
                'branch_id' => 'Cabang RME wajib dipilih untuk kunjungan RME.',
            ]);
        }

        $branchId = (int) $branchId;

        if (! in_array($branchId, $this->branches->rmeEnabledIds(), true)) {
            throw ValidationException::withMessages([
                'branch_id' => 'Klinik/Cabang yang dipilih harus cabang RME aktif.',
            ]);
        }

        return $branchId;
    }

    /**
     * Resolve the visit's patient_id. In "new" mode a patient is created first
     * with the finalized RM number, then the visit is attached to it. In
     * "existing" mode the supplied patient_id is used unchanged. The transient
     * registration keys are stripped before the visit is persisted.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolvePatient(array $data): array
    {
        $mode = $data['patient_mode'] ?? 'existing';
        $newPatient = $data['new_patient'] ?? null;

        unset($data['patient_mode'], $data['new_patient']);

        if ($mode === 'new' && is_array($newPatient)) {
            $patient = $this->patients->create([
                // RME patients are branch-scoped, not clinic-scoped. clinic_id is
                // intentionally left null (legacy column, now nullable).
                'clinic_id' => $data['clinic_id'] ?? null,
                'doctor_id' => $data['doctor_id'],
                'branch_id' => $newPatient['branch_id'] ?? $data['branch_id'] ?? null,
                'registered_at' => $newPatient['registered_at'] ?? null,
                'manual_rm_number' => $newPatient['manual_rm_number'] ?? null,
                'name' => $newPatient['name'] ?? null,
                'gender' => $newPatient['gender'] ?? null,
                'date_of_birth' => $newPatient['date_of_birth'] ?? null,
                'phone' => $newPatient['phone'] ?? null,
                'whatsapp_number' => $newPatient['whatsapp_number'] ?? null,
                'ktp_number' => $newPatient['ktp_number'] ?? null,
                'address' => $newPatient['address'] ?? null,
                'is_active' => true,
            ]);

            $data['patient_id'] = $patient->id;
        }

        return $data;
    }

    public function update(ClinicVisit $visit, array $data): ClinicVisit
    {
        unset($data['status']);

        return DB::transaction(fn () => $this->visits->update($visit, $data));
    }

    public function visitsTodayCount(?int $branchId = null): int
    {
        return $this->visits->countTodayByBranches(
            $this->scopeBranchIds($branchId),
            Carbon::today()->toDateString(),
        );
    }

    public function waitingCount(?int $branchId = null): int
    {
        return $this->visits->countByBranchesStatus(
            $this->scopeBranchIds($branchId),
            ClinicVisit::STATUS_WAITING,
        );
    }

    public function inProgressCount(?int $branchId = null): int
    {
        return $this->visits->countByBranchesStatus(
            $this->scopeBranchIds($branchId),
            ClinicVisit::STATUS_IN_PROGRESS,
        );
    }

    public function transitionStatus(ClinicVisit $visit, string $newStatus): ClinicVisit
    {
        $allowed = ClinicVisit::VALID_TRANSITIONS[$visit->status] ?? [];

        if (! in_array($newStatus, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "Transisi status dari '{$visit->status}' ke '{$newStatus}' tidak diizinkan.",
            ]);
        }

        return DB::transaction(function () use ($visit, $newStatus) {
            $timestamps = [];

            if ($newStatus === ClinicVisit::STATUS_WAITING && $visit->check_in_at === null) {
                $timestamps['check_in_at'] = now();
            }

            if ($newStatus === ClinicVisit::STATUS_IN_PROGRESS && $visit->started_at === null) {
                $timestamps['started_at'] = now();
            }

            if ($newStatus === ClinicVisit::STATUS_COMPLETED && $visit->completed_at === null) {
                $timestamps['completed_at'] = now();
            }

            if ($newStatus === ClinicVisit::STATUS_CANCELLED && $visit->cancelled_at === null) {
                $timestamps['cancelled_at'] = now();
            }

            return $this->visits->update($visit, array_merge(['status' => $newStatus], $timestamps));
        });
    }
}
