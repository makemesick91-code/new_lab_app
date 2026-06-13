<?php

namespace App\Modules\Branch\Services;

use App\Modules\Branch\Interfaces\BranchRepositoryInterface;
use App\Modules\Branch\Models\Branch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Branch administration business logic. Sprint 9 ships CRUD only; branch-aware
 * data scoping is deferred to a later sprint (see module docs).
 */
class BranchService
{
    public function __construct(
        private readonly BranchRepositoryInterface $branches,
    ) {}

    public function list(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        return $this->branches->paginate($filters, $perPage);
    }

    public function listActive(): Collection
    {
        return $this->branches->listActive();
    }

    /**
     * Active branches enabled for RME — the only branches eligible for patient
     * registration / final RM number composition.
     */
    public function listRmeEnabled(): Collection
    {
        return $this->branches->listRmeEnabled();
    }

    /**
     * IDs of the active RME-enabled branches. Used to scope RME visit listing
     * and dashboard counts to the operational "Cabang RME" set (MAIN, which is
     * not RME-enabled, is excluded by definition).
     *
     * @return array<int, int>
     */
    public function rmeEnabledIds(): array
    {
        return $this->branches->listRmeEnabled()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function find(int $id): ?Branch
    {
        return $this->branches->findById($id);
    }

    /**
     * The default head-office branch used as the anchor for unscoped records.
     */
    public function defaultBranch(): ?Branch
    {
        return $this->branches->defaultBranch();
    }

    public function create(array $data): Branch
    {
        return DB::transaction(fn () => $this->branches->create($data));
    }

    public function update(Branch $branch, array $data): Branch
    {
        return DB::transaction(fn () => $this->branches->update($branch, $data));
    }

    public function delete(Branch $branch): bool
    {
        return DB::transaction(fn () => $this->branches->delete($branch));
    }
}
