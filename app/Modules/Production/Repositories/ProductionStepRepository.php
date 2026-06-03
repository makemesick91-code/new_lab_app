<?php

namespace App\Modules\Production\Repositories;

use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\Production\Interfaces\ProductionStepRepositoryInterface;
use App\Modules\Production\Models\ProductionStep;
use Illuminate\Support\Collection;

class ProductionStepRepository implements ProductionStepRepositoryInterface
{
    public function createMany(LabOrder $labOrder, array $stepNames): Collection
    {
        return collect($stepNames)->map(fn (string $name) => $labOrder->productionSteps()->create([
            'step_name' => $name,
            'status' => ProductionStep::STATUS_PENDING,
        ]));
    }

    public function forLabOrder(int $labOrderId): Collection
    {
        return ProductionStep::query()
            ->where('lab_order_id', $labOrderId)
            ->orderBy('id')
            ->get();
    }

    public function findById(int $id): ?ProductionStep
    {
        return ProductionStep::find($id);
    }

    public function countForLabOrder(int $labOrderId): int
    {
        return ProductionStep::where('lab_order_id', $labOrderId)->count();
    }

    public function update(ProductionStep $step, array $data): ProductionStep
    {
        $step->update($data);

        return $step->refresh();
    }
}
