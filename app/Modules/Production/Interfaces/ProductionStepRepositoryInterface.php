<?php

namespace App\Modules\Production\Interfaces;

use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\Production\Models\ProductionStep;
use Illuminate\Support\Collection;

interface ProductionStepRepositoryInterface
{
    /**
     * @param  array<int, string>  $stepNames
     */
    public function createMany(LabOrder $labOrder, array $stepNames): Collection;

    public function forLabOrder(int $labOrderId): Collection;

    public function findById(int $id): ?ProductionStep;

    public function countForLabOrder(int $labOrderId): int;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ProductionStep $step, array $data): ProductionStep;
}
