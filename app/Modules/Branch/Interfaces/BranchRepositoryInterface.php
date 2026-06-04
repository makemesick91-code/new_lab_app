<?php

namespace App\Modules\Branch\Interfaces;

use App\Modules\Branch\Models\Branch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface BranchRepositoryInterface
{
    /**
     * @param  array{search?: string|null}  $filters
     */
    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator;

    public function listActive(): Collection;

    public function findById(int $id): ?Branch;

    public function findByCode(string $code): ?Branch;

    /**
     * Resolve the default head-office branch (code MAIN), the anchor for
     * unscoped/legacy records. Returns null if it has not been seeded.
     */
    public function defaultBranch(): ?Branch;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Branch;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Branch $branch, array $data): Branch;

    public function delete(Branch $branch): bool;
}
