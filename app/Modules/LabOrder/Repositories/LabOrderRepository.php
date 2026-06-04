<?php

namespace App\Modules\LabOrder\Repositories;

use App\Modules\LabOrder\Interfaces\LabOrderRepositoryInterface;
use App\Modules\LabOrder\Models\LabOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Persistence + query composition only. No business rules (PROJECT_RULES §9).
 */
class LabOrderRepository implements LabOrderRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        return LabOrder::query()
            ->with(['clinic', 'doctor', 'patient'])
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(order_number) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(medical_record_number) LIKE ?', [$term])
                        ->orWhereHas('clinic', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [$term]))
                        ->orWhereHas('doctor', fn ($d) => $d->whereRaw('LOWER(name) LIKE ?', [$term]))
                        ->orWhereHas('patient', fn ($p) => $p->whereRaw('LOWER(name) LIKE ?', [$term]));
                });
            })
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['priority'] ?? null, fn ($q, $v) => $q->where('priority', $v))
            ->when($filters['clinic_id'] ?? null, fn ($q, $v) => $q->where('clinic_id', $v))
            ->when($filters['doctor_id'] ?? null, fn ($q, $v) => $q->where('doctor_id', $v))
            ->when($filters['patient_id'] ?? null, fn ($q, $v) => $q->where('patient_id', $v))
            // Sprint 9 — Multi Branch Foundation: opt-in branch scope.
            // Applies ONLY when a caller explicitly passes branch_id; no caller does
            // today, so behavior is unchanged (filtering is NOT enforced yet).
            // TODO(branch-scope): once branch context is resolved from the
            // authenticated user, default this to the user's branch_id (with a
            // Super-Admin "all branches" bypass) to enforce multi-branch isolation.
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findById(int $id): ?LabOrder
    {
        return LabOrder::find($id);
    }

    public function findDetailById(int $id): ?LabOrder
    {
        return LabOrder::query()
            ->with([
                'clinic',
                'doctor',
                'patient',
                'creator',
                'updater',
                'items.labService',
                'statusLogs.changedBy',
                'attachments.uploader',
            ])
            ->find($id);
    }

    public function create(array $data): LabOrder
    {
        return LabOrder::create($data);
    }

    public function update(LabOrder $order, array $data): LabOrder
    {
        $order->update($data);

        return $order->refresh();
    }

    public function syncItems(LabOrder $order, array $items): Collection
    {
        $keptIds = [];

        foreach ($items as $item) {
            $id = $item['id'] ?? null;
            unset($item['id']); // never persist id as an attribute (Postgres serial PK)

            if ($id) {
                $model = $order->items()->whereKey($id)->first();
                if ($model) {
                    $model->update($item);
                    $keptIds[] = $model->id;

                    continue;
                }
            }

            $created = $order->items()->create($item);
            $keptIds[] = $created->id;
        }

        // Soft-delete items that were removed from the submitted set.
        $order->items()->whereNotIn('id', $keptIds)->get()->each->delete();

        return $order->items()->get();
    }

    public function softDelete(LabOrder $order): bool
    {
        return (bool) $order->delete();
    }

    public function existsOrderNumber(string $orderNumber): bool
    {
        return LabOrder::where('order_number', $orderNumber)->exists();
    }

    public function latestOrderNumberForYear(string $year): ?string
    {
        return LabOrder::query()
            ->where('order_number', 'like', "ADL-{$year}-%")
            ->orderByDesc('order_number')
            ->value('order_number');
    }
}
