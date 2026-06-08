<?php

namespace App\Modules\Tariff\Interfaces;

use App\Modules\Tariff\Models\Tariff;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface TariffRepositoryInterface
{
    /**
     * @param  array{search?: string|null, treatment_id?: int|null, is_active?: bool|null, requires_lab?: bool|null}  $filters
     */
    public function paginate(int $branchId, array $filters = [], int $perPage = 10): LengthAwarePaginator;

    public function findInBranch(int $branchId, int $id): ?Tariff;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Tariff;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Tariff $tariff, array $data): Tariff;

    public function delete(Tariff $tariff): bool;
}
