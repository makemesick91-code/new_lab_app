<?php

namespace App\Modules\ClinicVisit\Services;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\ClinicVisit\Interfaces\ClinicVisitRepositoryInterface;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
        return DB::transaction(fn () => $this->visits->update($visit, $data));
    }
}
