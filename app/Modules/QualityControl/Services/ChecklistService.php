<?php

namespace App\Modules\QualityControl\Services;

use App\Models\User;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\QualityControl\Interfaces\ChecklistRepositoryInterface;
use App\Modules\QualityControl\Models\QualityControl;
use App\Modules\QualityControl\Models\QualityControlChecklist;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * QC checklist business rules. Checklist history is preserved.
 */
class ChecklistService
{
    public function __construct(
        private readonly ChecklistRepositoryInterface $checklists,
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * Create the default checklist items for a QC review (once).
     */
    public function createDefaults(QualityControl $review): Collection
    {
        if ($this->checklists->countForQualityControl($review->id) > 0) {
            return $this->checklists->forQualityControl($review->id);
        }

        return $this->checklists->createMany($review, QualityControlChecklist::ITEMS);
    }

    public function listFor(int $qualityControlId): Collection
    {
        return $this->checklists->forQualityControl($qualityControlId);
    }

    public function hasFailedItem(int $qualityControlId): bool
    {
        return $this->checklists->hasFailedItem($qualityControlId);
    }

    public function count(int $qualityControlId): int
    {
        return $this->checklists->countForQualityControl($qualityControlId);
    }

    /**
     * @param  array<string, mixed>  $data  validated: result, notes (+ optional checklist_item)
     */
    public function update(QualityControlChecklist $checklist, array $data, ?User $actor = null): QualityControlChecklist
    {
        $actor = $actor ?? auth()->user();

        return DB::transaction(function () use ($checklist, $data, $actor) {
            $old = ['checklist_item' => $checklist->checklist_item, 'result' => $checklist->result];

            $checklist = $this->checklists->update($checklist, [
                'result' => $data['result'],
                'notes' => $data['notes'] ?? $checklist->notes,
            ]);

            $labOrderId = $checklist->qualityControl?->lab_order_id;

            $this->auditLogs->log(
                LabOrder::ENTITY_TYPE,
                $labOrderId,
                AuditLog::ACTION_UPDATE_QC_CHECKLIST,
                $old,
                ['checklist_item' => $checklist->checklist_item, 'result' => $checklist->result, 'notes' => $checklist->notes],
                $actor,
            );

            return $checklist;
        });
    }
}
