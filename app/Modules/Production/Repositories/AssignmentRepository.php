<?php

namespace App\Modules\Production\Repositories;

use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\Production\Interfaces\AssignmentRepositoryInterface;
use App\Modules\Production\Models\LabOrderAssignment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class AssignmentRepository implements AssignmentRepositoryInterface
{
    /** Lab Order statuses that are relevant to the production board. */
    private const BOARD_STATUSES = ['RECEIVED', 'ASSIGNED', 'IN_PRODUCTION', 'ON_HOLD', 'QC_PENDING'];

    public function paginateBoard(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        return LabOrder::query()
            ->with(['clinic', 'doctor', 'patient', 'activeAssignment.technician'])
            ->whereIn('status', self::BOARD_STATUSES)
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(order_number) LIKE ?', [$term])
                        ->orWhereHas('clinic', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [$term]))
                        ->orWhereHas('doctor', fn ($d) => $d->whereRaw('LOWER(name) LIKE ?', [$term]))
                        ->orWhereHas('patient', fn ($p) => $p->whereRaw('LOWER(name) LIKE ?', [$term]));
                });
            })
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['priority'] ?? null, fn ($q, $v) => $q->where('priority', $v))
            ->when($filters['clinic_id'] ?? null, fn ($q, $v) => $q->where('clinic_id', $v))
            ->when($filters['technician_id'] ?? null, fn ($q, $v) => $q->whereHas('activeAssignment', fn ($a) => $a->where('technician_id', $v)))
            ->orderByDesc('id')
            ->paginate(min($perPage, 100))
            ->withQueryString();
    }

    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        return LabOrderAssignment::query()
            ->with(['labOrder', 'technician', 'assignedBy'])
            ->when($filters['lab_order_id'] ?? null, fn ($q, $v) => $q->where('lab_order_id', $v))
            ->when($filters['technician_id'] ?? null, fn ($q, $v) => $q->where('technician_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('id')
            ->paginate(min($perPage, 100))
            ->withQueryString();
    }

    public function findById(int $id): ?LabOrderAssignment
    {
        return LabOrderAssignment::with(['labOrder', 'technician', 'assignedBy', 'workLogs'])->find($id);
    }

    public function findActiveByLabOrder(int $labOrderId): ?LabOrderAssignment
    {
        return LabOrderAssignment::query()
            ->where('lab_order_id', $labOrderId)
            ->whereIn('status', LabOrderAssignment::ACTIVE_STATUSES)
            ->with('technician')
            ->orderByDesc('id')
            ->first();
    }

    public function forLabOrder(int $labOrderId): Collection
    {
        return LabOrderAssignment::query()
            ->where('lab_order_id', $labOrderId)
            ->with(['technician', 'assignedBy'])
            ->orderByDesc('id')
            ->get();
    }

    public function create(array $data): LabOrderAssignment
    {
        return LabOrderAssignment::create($data);
    }

    public function update(LabOrderAssignment $assignment, array $data): LabOrderAssignment
    {
        $assignment->update($data);

        return $assignment->refresh();
    }
}
