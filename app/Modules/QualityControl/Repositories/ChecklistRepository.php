<?php

namespace App\Modules\QualityControl\Repositories;

use App\Modules\QualityControl\Interfaces\ChecklistRepositoryInterface;
use App\Modules\QualityControl\Models\QualityControl;
use App\Modules\QualityControl\Models\QualityControlChecklist;
use Illuminate\Support\Collection;

class ChecklistRepository implements ChecklistRepositoryInterface
{
    public function forQualityControl(int $qualityControlId): Collection
    {
        return QualityControlChecklist::query()
            ->where('quality_control_id', $qualityControlId)
            ->orderBy('id')
            ->get();
    }

    public function createMany(QualityControl $qualityControl, array $items): Collection
    {
        return collect($items)->map(fn (string $item) => $qualityControl->checklists()->create([
            'checklist_item' => $item,
            'result' => QualityControlChecklist::RESULT_NA,
        ]));
    }

    public function findById(int $id): ?QualityControlChecklist
    {
        return QualityControlChecklist::find($id);
    }

    public function update(QualityControlChecklist $checklist, array $data): QualityControlChecklist
    {
        $checklist->update($data);

        return $checklist->refresh();
    }

    public function countForQualityControl(int $qualityControlId): int
    {
        return QualityControlChecklist::where('quality_control_id', $qualityControlId)->count();
    }

    public function hasFailedItem(int $qualityControlId): bool
    {
        return QualityControlChecklist::query()
            ->where('quality_control_id', $qualityControlId)
            ->where('result', QualityControlChecklist::RESULT_FAIL)
            ->exists();
    }
}
