<?php

namespace App\Modules\Inventory\Interfaces;

use App\Modules\Inventory\Models\ProductUnit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ProductUnitRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator;

    public function listActive(): Collection;

    public function find(int $id): ?ProductUnit;

    public function create(array $data): ProductUnit;

    public function update(ProductUnit $unit, array $data): ProductUnit;

    public function deactivate(ProductUnit $unit): ProductUnit;
}
