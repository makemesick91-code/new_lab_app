<?php

namespace App\Modules\Production\Services;

use App\Models\User;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\Production\Interfaces\ProductionStepRepositoryInterface;
use App\Modules\Production\Models\ProductionStep;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Generates and updates operational production steps (no QC behaviour).
 */
class ProductionStepService
{
    public function __construct(
        private readonly ProductionStepRepositoryInterface $steps,
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * Create the default production steps for an order once.
     */
    public function createDefaults(LabOrder $labOrder, ?User $actor = null): Collection
    {
        if ($this->steps->countForLabOrder($labOrder->id) > 0) {
            return $this->steps->forLabOrder($labOrder->id);
        }

        return $this->steps->createMany($labOrder, ProductionStep::DEFAULT_STEPS);
    }

    public function listForLabOrder(int $labOrderId): Collection
    {
        return $this->steps->forLabOrder($labOrderId);
    }

    /**
     * @param  array<string, mixed>  $data  validated: status, notes
     */
    public function update(ProductionStep $step, array $data, ?User $actor = null): ProductionStep
    {
        $actor = $actor ?? auth()->user();

        return DB::transaction(function () use ($step, $data, $actor) {
            $old = ['status' => $step->status, 'notes' => $step->notes];

            $payload = [
                'status' => $data['status'],
                'notes' => $data['notes'] ?? $step->notes,
            ];

            if ($data['status'] === ProductionStep::STATUS_IN_PROGRESS && ! $step->started_at) {
                $payload['started_at'] = now();
            }

            if ($data['status'] === ProductionStep::STATUS_COMPLETED) {
                $payload['completed_at'] = now();
            }

            $step = $this->steps->update($step, $payload);

            $this->auditLogs->log(
                LabOrder::ENTITY_TYPE,
                $step->lab_order_id,
                AuditLog::ACTION_UPDATE_PRODUCTION_STEP,
                $old,
                ['step_name' => $step->step_name, 'status' => $step->status, 'notes' => $step->notes],
                $actor,
            );

            return $step;
        });
    }
}
