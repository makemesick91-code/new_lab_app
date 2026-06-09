<?php

namespace App\Modules\ClinicVisit\Services;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\ClinicVisit\Interfaces\ClinicVisitRepositoryInterface;
use App\Modules\ClinicVisit\Models\ClinicVisit;
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

    public function update(ClinicVisit $visit, array $data): ClinicVisit
    {
        unset($data['status']);

        return DB::transaction(fn () => $this->visits->update($visit, $data));
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

            return $this->visits->update($visit, array_merge(['status' => $newStatus], $timestamps));
        });
    }
}
