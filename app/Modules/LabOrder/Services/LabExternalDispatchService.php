<?php

namespace App\Modules\LabOrder\Services;

use App\Models\User;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\ExternalLab;
use App\Modules\LabOrder\Models\LabExternalDispatch;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * LAB-WORKFLOW-V2 (Phase 3) — external lab dispatch workflow.
 *
 * Manual, real tracking (no external API): prepare -> sent -> in progress ->
 * returned -> result review -> accepted (MODEL_DONE) or rejected (a NEW
 * dispatch round is opened; history is append-only, never overwritten).
 */
class LabExternalDispatchService
{
    public function __construct(
        private readonly LabWorkflowStateMachine $stateMachine,
        private readonly AuditLogService $auditLogs,
        private readonly LabWorkflowNotificationService $notifications,
    ) {}

    /**
     * Open a dispatch (EXTERNAL_LAB_REQUIRED -> EXTERNAL_LAB_PREPARATION).
     * The destination defaults to the latest analysis' external lab.
     *
     * @param  array{external_lab_id?: int|null, reason?: string|null, expected_return_date?: string|null, notes?: string|null}  $data
     */
    public function createDispatch(LabOrder $order, array $data, ?User $actor = null): LabExternalDispatch
    {
        $actor = $actor ?? auth()->user();

        $externalLabId = (int) ($data['external_lab_id'] ?? 0);
        if ($externalLabId === 0) {
            $order->loadMissing('latestModelAnalysis');
            $externalLabId = (int) ($order->latestModelAnalysis?->external_lab_id ?? 0);
        }

        $externalLab = ExternalLab::query()->find($externalLabId);
        if (! $externalLab || ! $externalLab->is_active) {
            throw ValidationException::withMessages([
                'external_lab_id' => 'Lab eksternal tujuan wajib dipilih dan aktif.',
            ]);
        }

        return DB::transaction(function () use ($order, $data, $externalLab, $actor) {
            $this->stateMachine->transition($order, LabWorkflowState::EXTERNAL_LAB_PREPARATION, $actor, [
                'reason' => $data['reason'] ?? 'Persiapan kirim ke '.$externalLab->name,
            ]);

            $dispatch = LabExternalDispatch::create([
                'lab_order_id' => $order->id,
                'external_lab_id' => $externalLab->id,
                'status' => LabExternalDispatch::STATUS_PREPARATION,
                'reason' => $data['reason'] ?? null,
                'expected_return_date' => $data['expected_return_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->audit($order, $actor, ['dispatch_id' => $dispatch->id, 'external_lab_id' => $externalLab->id, 'event' => 'DISPATCH_CREATED']);

            return $dispatch;
        });
    }

    /**
     * @param  array{shipping_method?: string|null, reference_number?: string|null, expected_return_date?: string|null, cost?: float|null}  $data
     */
    public function markSent(LabOrder $order, array $data, ?User $actor = null): LabExternalDispatch
    {
        $actor = $actor ?? auth()->user();

        return DB::transaction(function () use ($order, $data, $actor) {
            $dispatch = $this->activeDispatchOrFail($order, LabExternalDispatch::STATUS_PREPARATION);

            $this->stateMachine->transition($order, LabWorkflowState::EXTERNAL_LAB_SENT, $actor, [
                'reason' => 'Model dikirim ke lab eksternal',
            ]);

            $dispatch->update([
                'status' => LabExternalDispatch::STATUS_SENT,
                'sent_at' => now(),
                'shipping_method' => $data['shipping_method'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'expected_return_date' => $data['expected_return_date'] ?? $dispatch->expected_return_date,
                'cost' => $data['cost'] ?? $dispatch->cost,
            ]);

            $this->audit($order, $actor, ['dispatch_id' => $dispatch->id, 'event' => 'DISPATCH_SENT']);

            return $dispatch->refresh();
        });
    }

    public function markInProgress(LabOrder $order, ?User $actor = null): LabExternalDispatch
    {
        $actor = $actor ?? auth()->user();

        return DB::transaction(function () use ($order, $actor) {
            $dispatch = $this->activeDispatchOrFail($order, LabExternalDispatch::STATUS_SENT);

            $this->stateMachine->transition($order, LabWorkflowState::EXTERNAL_LAB_IN_PROGRESS, $actor, [
                'reason' => 'Dikerjakan lab eksternal',
            ]);

            $dispatch->update(['status' => LabExternalDispatch::STATUS_IN_PROGRESS]);

            return $dispatch->refresh();
        });
    }

    /** Record the physical return and open result review in one action. */
    public function markReturned(LabOrder $order, ?string $notes = null, ?User $actor = null): LabExternalDispatch
    {
        $actor = $actor ?? auth()->user();

        return DB::transaction(function () use ($order, $notes, $actor) {
            $dispatch = $this->activeDispatchOrFail($order, LabExternalDispatch::STATUS_IN_PROGRESS);

            $this->stateMachine->transition($order, LabWorkflowState::EXTERNAL_LAB_RETURNED, $actor, [
                'reason' => $notes ?: 'Model kembali dari lab eksternal',
            ]);
            $this->stateMachine->transition($order->refresh(), LabWorkflowState::EXTERNAL_LAB_RESULT_REVIEW, $actor, [
                'reason' => 'Review hasil lab eksternal',
            ]);

            $dispatch->update([
                'status' => LabExternalDispatch::STATUS_RETURNED,
                'returned_at' => now(),
                'notes' => $notes ?: $dispatch->notes,
            ]);

            $this->audit($order, $actor, ['dispatch_id' => $dispatch->id, 'event' => 'DISPATCH_RETURNED']);

            return $dispatch->refresh();
        });
    }

    /**
     * Result review. Accepted -> MODEL_DONE. Rejected -> a NEW dispatch round
     * is opened (EXTERNAL_LAB_PREPARATION) for resend; the reviewed dispatch
     * row is closed and never rewritten.
     */
    public function review(LabOrder $order, string $result, ?string $notes = null, ?User $actor = null): LabExternalDispatch
    {
        $actor = $actor ?? auth()->user();

        if (! in_array($result, [LabExternalDispatch::RESULT_ACCEPTED, LabExternalDispatch::RESULT_REJECTED], true)) {
            throw ValidationException::withMessages([
                'result' => 'Hasil review harus diterima atau ditolak.',
            ]);
        }

        if ($result === LabExternalDispatch::RESULT_REJECTED && trim((string) $notes) === '') {
            throw ValidationException::withMessages([
                'notes' => 'Catatan review wajib diisi saat hasil ditolak.',
            ]);
        }

        $dispatch = DB::transaction(function () use ($order, $result, $notes, $actor) {
            $dispatch = $this->activeDispatchOrFail($order, LabExternalDispatch::STATUS_RETURNED);

            $dispatch->update([
                'status' => LabExternalDispatch::STATUS_REVIEWED,
                'review_result' => $result,
                'review_notes' => $notes,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ]);

            if ($result === LabExternalDispatch::RESULT_ACCEPTED) {
                $this->stateMachine->transition($order, LabWorkflowState::MODEL_DONE, $actor, [
                    'reason' => 'Hasil lab eksternal diterima',
                ]);
            } else {
                // Rejected: open a fresh round for resend (append-only history).
                $this->stateMachine->transition($order, LabWorkflowState::EXTERNAL_LAB_PREPARATION, $actor, [
                    'reason' => 'Hasil ditolak — kirim ulang ke lab eksternal',
                ]);

                LabExternalDispatch::create([
                    'lab_order_id' => $order->id,
                    'external_lab_id' => $dispatch->external_lab_id,
                    'status' => LabExternalDispatch::STATUS_PREPARATION,
                    'reason' => 'Kirim ulang setelah hasil ditolak: '.$notes,
                    'created_by' => $actor->id,
                ]);
            }

            $this->audit($order, $actor, [
                'dispatch_id' => $dispatch->id,
                'event' => 'DISPATCH_REVIEWED',
                'result' => $result,
            ]);

            return $dispatch->refresh();
        });

        if ($result === LabExternalDispatch::RESULT_ACCEPTED) {
            $this->notifications->notifyPermissionHolders(
                ['create_delivery', 'manage_lab_orders'],
                'Model selesai (hasil eksternal diterima)',
                "Order {$order->order_number} selesai dan siap dibuatkan tugas pengiriman.",
                route('lab-v2-orders.show', $order),
                $order,
            );
        }

        return $dispatch;
    }

    private function activeDispatchOrFail(LabOrder $order, string $expectedStatus): LabExternalDispatch
    {
        $dispatch = LabExternalDispatch::query()
            ->where('lab_order_id', $order->id)
            ->whereIn('status', LabExternalDispatch::ACTIVE_STATUSES)
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($dispatch === null) {
            throw ValidationException::withMessages([
                'dispatch' => 'Order ini tidak memiliki pengiriman lab eksternal aktif.',
            ]);
        }

        if ($dispatch->status !== $expectedStatus) {
            throw ValidationException::withMessages([
                'dispatch' => "Status pengiriman saat ini ({$dispatch->status}) tidak memungkinkan aksi ini.",
            ]);
        }

        return $dispatch;
    }

    /**
     * @param  array<string, scalar|null>  $metadata
     */
    private function audit(LabOrder $order, User $actor, array $metadata): void
    {
        $this->auditLogs->log(
            LabOrder::ENTITY_TYPE,
            $order->id,
            AuditLog::ACTION_UPDATE,
            null,
            $metadata,
            $actor,
        );
    }
}
