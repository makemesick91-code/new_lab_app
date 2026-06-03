<?php

namespace App\Modules\QualityControl\Services;

use App\Models\User;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\QualityControl\Interfaces\QualityControlRepositoryInterface;
use App\Modules\QualityControl\Models\QualityControl;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * QC review lifecycle (start / complete) and queue/detail/history reads.
 */
class QualityControlService
{
    public function __construct(
        private readonly QualityControlRepositoryInterface $reviews,
        private readonly ChecklistService $checklistService,
        private readonly AuditLogService $auditLogs,
    ) {}

    public function queue(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        return $this->reviews->paginateQueue($filters, $perPage);
    }

    public function findActive(int $labOrderId): ?QualityControl
    {
        return $this->reviews->findActiveByLabOrder($labOrderId);
    }

    public function latest(int $labOrderId): ?QualityControl
    {
        return $this->reviews->latestForLabOrder($labOrderId);
    }

    public function history(int $labOrderId): Collection
    {
        return $this->reviews->historyForLabOrder($labOrderId);
    }

    public function start(LabOrder $order, ?string $notes, ?User $actor = null): QualityControl
    {
        $actor = $actor ?? auth()->user();

        if ($order->status !== LabOrder::STATUS_QC_PENDING) {
            throw ValidationException::withMessages([
                'status' => 'QC review hanya dapat dimulai saat order berstatus QC_PENDING.',
            ]);
        }

        if ($existing = $this->reviews->findActiveByLabOrder($order->id)) {
            return $existing;
        }

        return DB::transaction(function () use ($order, $notes, $actor) {
            $review = $this->reviews->create([
                'lab_order_id' => $order->id,
                'inspected_by' => $actor?->id,
                'notes' => $notes,
                'started_at' => now(),
            ]);

            $this->checklistService->createDefaults($review);

            $this->auditLogs->log(
                LabOrder::ENTITY_TYPE,
                $order->id,
                AuditLog::ACTION_START_QC,
                null,
                ['quality_control_id' => $review->id, 'inspected_by' => $actor?->id],
                $actor,
            );

            return $review;
        });
    }

    /**
     * Return the active QC review or start one (with default checklist).
     */
    public function ensureActiveReview(LabOrder $order, ?User $actor = null): QualityControl
    {
        return $this->reviews->findActiveByLabOrder($order->id) ?? $this->start($order, null, $actor);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function complete(QualityControl $review, array $data): QualityControl
    {
        return $this->reviews->update($review, $data);
    }
}
