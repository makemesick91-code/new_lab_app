<?php

namespace App\Modules\LabOrder\Interfaces;

use App\Modules\LabOrder\Models\LabOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface LabOrderRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator;

    public function findById(int $id): ?LabOrder;

    public function findDetailById(int $id): ?LabOrder;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): LabOrder;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(LabOrder $order, array $data): LabOrder;

    /**
     * Create / update / soft-delete items to match the submitted set.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public function syncItems(LabOrder $order, array $items): Collection;

    public function softDelete(LabOrder $order): bool;

    public function existsOrderNumber(string $orderNumber): bool;

    public function latestOrderNumberForYear(string $year): ?string;

    /**
     * LAB-WORKFLOW-V2 — branch-scoped listing of V2 workflow orders.
     *
     * @param  array<string, mixed>  $filters  search?, status?
     */
    public function paginateV2ForBranch(int $branchId, array $filters = [], int $perPage = 10): LengthAwarePaginator;
}
