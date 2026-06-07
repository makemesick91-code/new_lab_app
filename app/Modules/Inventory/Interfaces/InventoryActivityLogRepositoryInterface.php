<?php

namespace App\Modules\Inventory\Interfaces;

use App\Modules\Inventory\Models\InventoryActivityLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface InventoryActivityLogRepositoryInterface
{
    public function create(array $data): InventoryActivityLog;

    public function paginate(int $branchId, array $filters = [], int $perPage = 25): LengthAwarePaginator;

    public function findInBranch(int $branchId, int $id): ?InventoryActivityLog;

    public function forSubject(int $branchId, string $subjectType, int $subjectId, int $perPage = 15): LengthAwarePaginator;
}
