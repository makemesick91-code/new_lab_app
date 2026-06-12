<?php

namespace App\Modules\ClinicVisit\Services;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\ClinicVisit\Interfaces\ClinicVisitRepositoryInterface;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Patient\Services\PatientService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClinicVisitService
{
    public function __construct(
        private readonly ClinicVisitRepositoryInterface $visits,
        private readonly BranchContext $branchContext,
        private readonly PatientService $patients,
    ) {}

    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->visits->paginate($this->branchContext->requireId(), $filters, $perPage);
    }

    public function find(int $id): ?ClinicVisit
    {
        return $this->visits->findInBranch($this->branchContext->requireId(), $id);
    }

    public function create(array $data): ClinicVisit
    {
        return DB::transaction(function () use ($data) {
            $data = $this->resolvePatient($data);

            $branchId = $this->branchContext->requireId();
            $visitDate = Carbon::today();

            $queueNumber = $this->visits->nextQueueNumber($branchId, $visitDate);
            $visitNumber = 'VIS-'.$visitDate->format('Ymd').'-'.str_pad((string) $queueNumber, 3, '0', STR_PAD_LEFT);

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
                'clinic_id' => $data['clinic_id'],
                'doctor_id' => $data['doctor_id'],
                'branch_id' => $newPatient['branch_id'] ?? null,
                'registered_at' => $newPatient['registered_at'] ?? null,
                'manual_rm_number' => $newPatient['manual_rm_number'] ?? null,
                'name' => $newPatient['name'] ?? null,
                'gender' => $newPatient['gender'] ?? null,
                'date_of_birth' => $newPatient['date_of_birth'] ?? null,
                'phone' => $newPatient['phone'] ?? null,
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

    public function visitsTodayCount(): int
    {
        return $this->visits->countTodayByBranch(
            $this->branchContext->requireId(),
            Carbon::today()->toDateString(),
        );
    }

    public function waitingCount(): int
    {
        return $this->visits->countByBranchStatus(
            $this->branchContext->requireId(),
            ClinicVisit::STATUS_WAITING,
        );
    }

    public function inProgressCount(): int
    {
        return $this->visits->countByBranchStatus(
            $this->branchContext->requireId(),
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
