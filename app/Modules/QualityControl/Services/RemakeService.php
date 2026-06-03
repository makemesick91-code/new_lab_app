<?php

namespace App\Modules\QualityControl\Services;

use App\Models\User;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\QualityControl\Interfaces\RemakeRequestRepositoryInterface;
use App\Modules\QualityControl\Models\QualityControl;
use App\Modules\QualityControl\Models\RemakeRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Remake request business rules. Remake never creates a new Lab Order and
 * always preserves QC + production history.
 */
class RemakeService
{
    public function __construct(
        private readonly RemakeRequestRepositoryInterface $remakeRequests,
        private readonly AuditLogService $auditLogs,
    ) {}

    public function create(
        LabOrder $order,
        QualityControl $review,
        string $reason,
        ?string $notes,
        ?User $actor = null,
    ): RemakeRequest {
        $actor = $actor ?? auth()->user();

        return DB::transaction(function () use ($order, $review, $reason, $notes, $actor) {
            $remake = $this->remakeRequests->create([
                'lab_order_id' => $order->id,
                'quality_control_id' => $review->id,
                'requested_by' => $actor?->id,
                'reason' => $reason,
                'notes' => $notes,
                'status' => RemakeRequest::STATUS_OPEN,
                'requested_at' => now(),
            ]);

            $this->auditLogs->log(
                LabOrder::ENTITY_TYPE,
                $order->id,
                AuditLog::ACTION_REQUEST_REMAKE,
                null,
                ['remake_request_id' => $remake->id, 'quality_control_id' => $review->id, 'reason' => $reason],
                $actor,
            );

            return $remake;
        });
    }

    public function forLabOrder(int $labOrderId): Collection
    {
        return $this->remakeRequests->forLabOrder($labOrderId);
    }

    public function latestForLabOrder(int $labOrderId): ?RemakeRequest
    {
        return $this->remakeRequests->latestForLabOrder($labOrderId);
    }
}
