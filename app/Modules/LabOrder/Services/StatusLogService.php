<?php

namespace App\Modules\LabOrder\Services;

use App\Models\User;
use App\Modules\LabOrder\Interfaces\StatusLogRepositoryInterface;
use App\Modules\LabOrder\Models\LabOrderStatusLog;

/**
 * Centralized status timeline creation. Append-only.
 */
class StatusLogService
{
    public function __construct(
        private readonly StatusLogRepositoryInterface $statusLogs,
    ) {}

    public function log(
        int $labOrderId,
        ?string $oldStatus,
        string $newStatus,
        ?string $notes = null,
        ?User $actor = null,
    ): LabOrderStatusLog {
        $actor = $actor ?? auth()->user();

        return $this->statusLogs->create([
            'lab_order_id' => $labOrderId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'notes' => $notes,
            'changed_by' => $actor?->id,
            'changed_at' => now(),
        ]);
    }
}
