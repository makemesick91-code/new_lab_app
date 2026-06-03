<?php

namespace App\Modules\QualityControl\Repositories;

use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\QualityControl\Interfaces\QualityControlRepositoryInterface;
use App\Modules\QualityControl\Models\QualityControl;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class QualityControlRepository implements QualityControlRepositoryInterface
{
    public function paginateQueue(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        return LabOrder::query()
            ->with(['clinic', 'doctor', 'patient', 'activeAssignment.technician'])
            ->where('status', LabOrder::STATUS_QC_PENDING)
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(order_number) LIKE ?', [$term])
                        ->orWhereHas('clinic', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [$term]))
                        ->orWhereHas('doctor', fn ($d) => $d->whereRaw('LOWER(name) LIKE ?', [$term]))
                        ->orWhereHas('patient', fn ($p) => $p->whereRaw('LOWER(name) LIKE ?', [$term]));
                });
            })
            ->when($filters['priority'] ?? null, fn ($q, $v) => $q->where('priority', $v))
            ->when($filters['clinic_id'] ?? null, fn ($q, $v) => $q->where('clinic_id', $v))
            ->when($filters['doctor_id'] ?? null, fn ($q, $v) => $q->where('doctor_id', $v))
            ->when($filters['technician_id'] ?? null, fn ($q, $v) => $q->whereHas('activeAssignment', fn ($a) => $a->where('technician_id', $v)))
            ->orderByRaw("CASE priority WHEN 'SUPER_URGENT' THEN 0 WHEN 'URGENT' THEN 1 ELSE 2 END")
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->paginate(min($perPage, 100))
            ->withQueryString();
    }

    public function findReviewById(int $id): ?QualityControl
    {
        return QualityControl::with(['labOrder', 'inspector', 'checklists', 'remakeRequests'])->find($id);
    }

    public function findActiveByLabOrder(int $labOrderId): ?QualityControl
    {
        return QualityControl::query()
            ->where('lab_order_id', $labOrderId)
            ->whereNull('completed_at')
            ->orderByDesc('id')
            ->first();
    }

    public function latestForLabOrder(int $labOrderId): ?QualityControl
    {
        return QualityControl::query()
            ->where('lab_order_id', $labOrderId)
            ->orderByDesc('id')
            ->first();
    }

    public function historyForLabOrder(int $labOrderId): Collection
    {
        return QualityControl::query()
            ->where('lab_order_id', $labOrderId)
            ->with(['inspector', 'checklists'])
            ->orderByDesc('id')
            ->get();
    }

    public function create(array $data): QualityControl
    {
        return QualityControl::create($data);
    }

    public function update(QualityControl $review, array $data): QualityControl
    {
        $review->update($data);

        return $review->refresh();
    }
}
