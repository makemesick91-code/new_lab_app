<?php

namespace App\Modules\Branch\Repositories;

use App\Modules\Branch\Interfaces\BranchRepositoryInterface;
use App\Modules\Branch\Models\Branch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Persistence + query composition only. No business rules (PROJECT_RULES §9).
 */
class BranchRepository implements BranchRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;

        return Branch::query()
            ->when($search, function ($query, $search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(code) LIKE ?', [$term]);
                });
            })
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function listActive(): Collection
    {
        return Branch::where('is_active', true)->orderBy('name')->get();
    }

    public function findById(int $id): ?Branch
    {
        return Branch::find($id);
    }

    public function findByCode(string $code): ?Branch
    {
        return Branch::where('code', $code)->first();
    }

    public function defaultBranch(): ?Branch
    {
        return $this->findByCode(Branch::MAIN_CODE);
    }

    public function create(array $data): Branch
    {
        return Branch::create($data);
    }

    public function update(Branch $branch, array $data): Branch
    {
        $branch->update($data);

        return $branch->refresh();
    }

    public function delete(Branch $branch): bool
    {
        return (bool) $branch->delete();
    }
}
