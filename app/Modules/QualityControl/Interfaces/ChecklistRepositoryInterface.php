<?php

namespace App\Modules\QualityControl\Interfaces;

use App\Modules\QualityControl\Models\QualityControl;
use App\Modules\QualityControl\Models\QualityControlChecklist;
use Illuminate\Support\Collection;

interface ChecklistRepositoryInterface
{
    public function forQualityControl(int $qualityControlId): Collection;

    /**
     * @param  array<int, string>  $items
     */
    public function createMany(QualityControl $qualityControl, array $items): Collection;

    public function findById(int $id): ?QualityControlChecklist;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(QualityControlChecklist $checklist, array $data): QualityControlChecklist;

    public function countForQualityControl(int $qualityControlId): int;

    public function hasFailedItem(int $qualityControlId): bool;
}
