<?php

namespace App\Modules\Inventory\Repositories;

use App\Modules\Inventory\Interfaces\InventoryActivityLogRepositoryInterface;
use App\Modules\Inventory\Models\InventoryActivityLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Persistence + query composition only — append-only, branch-scoped reads.
 */
class InventoryActivityLogRepository implements InventoryActivityLogRepositoryInterface
{
    public function create(array $data): InventoryActivityLog
    {
        return InventoryActivityLog::create($data);
    }

    public function paginate(int $branchId, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->branchScopedQuery($branchId)
            ->with(['user', 'branch'])
            ->tap(fn ($query) => $this->applyFilters($query, $filters))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findInBranch(int $branchId, int $id): ?InventoryActivityLog
    {
        return $this->branchScopedQuery($branchId)
            ->with(['user', 'branch'])
            ->find($id);
    }

    public function forSubject(int $branchId, string $subjectType, int $subjectId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->branchScopedQuery($branchId)
            ->with(['user'])
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    private function branchScopedQuery(int $branchId)
    {
        return InventoryActivityLog::query()->where('branch_id', $branchId);
    }

    private function applyFilters($query, array $filters): void
    {
        $query
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['subject_type'] ?? null, fn ($q, $v) => $q->where('subject_type', $v))
            ->when($filters['subject_id'] ?? null, fn ($q, $v) => $q->where('subject_id', $v))
            ->when($filters['correlation_id'] ?? null, fn ($q, $v) => $q->where('correlation_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $q->whereRaw('LOWER(description) LIKE ?', [$term]);
            });

        $action = $filters['action'] ?? null;

        if (is_array($action)) {
            $query->whereIn('action', $action);
        } elseif (is_string($action) && $action !== '') {
            $query->where('action', $action);
        }
    }
}
