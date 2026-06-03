<?php

namespace App\Modules\QualityControl\Services;

use App\Models\User;
use App\Modules\LabOrder\Interfaces\LabOrderRepositoryInterface;
use App\Modules\LabOrder\Models\Attachment;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Services\AttachmentService;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\LabOrder\Services\StatusLogService;
use App\Modules\QualityControl\Models\QualityControl;
use App\Modules\QualityControl\Models\RemakeRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns QC decision transitions and database transactions
 * (pass → QC_PASSED, reject/revision → REMAKE).
 */
class QualityWorkflowService
{
    public function __construct(
        private readonly QualityControlService $qualityControl,
        private readonly ChecklistService $checklists,
        private readonly RemakeService $remakes,
        private readonly LabOrderRepositoryInterface $labOrders,
        private readonly StatusLogService $statusLogs,
        private readonly AuditLogService $auditLogs,
        private readonly AttachmentService $attachments,
    ) {}

    public function pass(LabOrder $order, ?string $notes, ?User $actor = null): QualityControl
    {
        $actor = $actor ?? auth()->user();
        $this->assertQcPending($order);

        $review = $this->qualityControl->ensureActiveReview($order, $actor);

        if ($this->checklists->count($review->id) === 0) {
            throw ValidationException::withMessages(['checklist' => 'Minimal satu item checklist diperlukan sebelum QC selesai.']);
        }

        if ($this->checklists->hasFailedItem($review->id)) {
            throw ValidationException::withMessages(['checklist' => 'QC tidak dapat PASSED selama masih ada item checklist yang FAIL.']);
        }

        return DB::transaction(function () use ($order, $review, $notes, $actor) {
            $this->qualityControl->complete($review, [
                'result' => QualityControl::RESULT_PASSED,
                'notes' => $notes,
                'completed_at' => now(),
            ]);

            $this->transitionOrder($order, LabOrder::STATUS_QC_PASSED, $notes, $actor);
            $this->audit($order, AuditLog::ACTION_PASS_QC, 'QC_PENDING', 'QC_PASSED', $actor);

            return $review->refresh();
        });
    }

    public function reject(LabOrder $order, string $result, string $reason, ?string $notes, ?User $actor = null): QualityControl
    {
        $actor = $actor ?? auth()->user();
        $this->assertQcPending($order);

        $review = $this->qualityControl->ensureActiveReview($order, $actor);

        return DB::transaction(function () use ($order, $review, $result, $reason, $notes, $actor) {
            $this->qualityControl->complete($review, [
                'result' => $result,
                'notes' => $notes,
                'completed_at' => now(),
            ]);

            $this->transitionOrder($order, LabOrder::STATUS_REMAKE, $notes, $actor);
            $this->remakes->create($order, $review, $reason, $notes, $actor);
            $this->audit($order, AuditLog::ACTION_REJECT_QC, 'QC_PENDING', 'REMAKE', $actor);

            return $review->refresh();
        });
    }

    /**
     * Create an additional remake request for an order already in REMAKE.
     */
    public function requestRemake(LabOrder $order, string $reason, ?string $notes, ?User $actor = null): RemakeRequest
    {
        $actor = $actor ?? auth()->user();

        if ($order->status !== LabOrder::STATUS_REMAKE) {
            throw ValidationException::withMessages([
                'status' => 'Remake request hanya dapat dibuat untuk order berstatus REMAKE.',
            ]);
        }

        $review = $this->qualityControl->latest($order->id);

        if (! $review) {
            throw ValidationException::withMessages([
                'quality_control' => 'Tidak ada QC review terkait untuk remake request.',
            ]);
        }

        return $this->remakes->create($order, $review, $reason, $notes, $actor);
    }

    public function uploadEvidence(LabOrder $order, UploadedFile $file, string $category, ?User $actor = null): Attachment
    {
        $actor = $actor ?? auth()->user();

        return $this->attachments->upload($order, $file, $category, $actor, AuditLog::ACTION_UPLOAD_QC_EVIDENCE);
    }

    private function assertQcPending(LabOrder $order): void
    {
        if ($order->status !== LabOrder::STATUS_QC_PENDING) {
            throw ValidationException::withMessages([
                'status' => 'Aksi QC hanya valid untuk order berstatus QC_PENDING.',
            ]);
        }
    }

    private function transitionOrder(LabOrder $order, string $newStatus, ?string $notes, ?User $actor): void
    {
        $oldStatus = $order->status;
        $this->labOrders->update($order, ['status' => $newStatus, 'updated_by' => $actor?->id]);
        $this->statusLogs->log($order->id, $oldStatus, $newStatus, $notes, $actor);
    }

    private function audit(LabOrder $order, string $action, string $oldStatus, string $newStatus, ?User $actor): void
    {
        $this->auditLogs->log(
            LabOrder::ENTITY_TYPE,
            $order->id,
            $action,
            ['status' => $oldStatus],
            ['status' => $newStatus],
            $actor,
        );
    }
}
