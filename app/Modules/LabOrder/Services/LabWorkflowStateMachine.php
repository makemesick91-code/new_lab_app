<?php

namespace App\Modules\LabOrder\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * LAB-WORKFLOW-V2 — canonical transition authority for V2 orders.
 *
 * Every V2 status change MUST go through transition(); no service is allowed to
 * write trx_lab_orders.status directly. Each transition is validated against
 * the LabWorkflowState matrix and guarded for: workflow engine (V2 only),
 * current-state validity, terminal state, legal edge, actor permission, and
 * branch ownership. Applied atomically under a row lock with an append-only
 * status log + audit-log entry. Forbidden transitions raise a clear
 * ValidationException (never a silent no-op), matching the repo convention.
 */
class LabWorkflowStateMachine
{
    public function __construct(
        private readonly StatusLogService $statusLogs,
        private readonly AuditLogService $auditLogs,
        private readonly BranchContext $branchContext,
    ) {}

    /**
     * @return list<string>
     */
    public function states(): array
    {
        return LabWorkflowState::all();
    }

    /**
     * @return list<string>
     */
    public function allowedTransitions(LabOrder $order): array
    {
        return LabWorkflowState::allowedFrom((string) $order->status);
    }

    public function permissionFor(string $targetState): string
    {
        $map = (array) config('lab_workflow.transition_permissions', []);

        return (string) ($map[$targetState] ?? config('lab_workflow.default_permission', 'manage_lab_orders'));
    }

    /** Non-throwing check used by UI/policies to decide whether to offer a CTA. */
    public function canTransition(LabOrder $order, string $targetState, ?User $actor = null): bool
    {
        try {
            $this->assertCanTransition($order, $targetState, $actor ?? auth()->user());

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    /**
     * Server-side guards. Throws ValidationException on any violation.
     */
    public function assertCanTransition(LabOrder $order, string $targetState, ?User $actor = null): void
    {
        $actor = $actor ?? auth()->user();

        if ($actor === null) {
            throw ValidationException::withMessages([
                'workflow' => 'Aksi tidak terautentikasi.',
            ]);
        }

        // 1. Engine: V2 only. Legacy orders never use this machine.
        if (! $order->isV2Workflow()) {
            throw ValidationException::withMessages([
                'workflow' => 'Order ini bukan Lab Workflow V2.',
            ]);
        }

        $current = (string) $order->status;

        // 2. Current state must be a known V2 state.
        if (! LabWorkflowState::isValid($current)) {
            throw ValidationException::withMessages([
                'workflow' => "Status saat ini ({$current}) bukan status Lab Workflow V2 yang valid.",
            ]);
        }

        // 3. Target must be a known V2 state.
        if (! LabWorkflowState::isValid($targetState)) {
            throw ValidationException::withMessages([
                'workflow' => "Status tujuan ({$targetState}) tidak dikenal.",
            ]);
        }

        // 4. No transition out of a terminal state.
        if (LabWorkflowState::isTerminal($current)) {
            throw ValidationException::withMessages([
                'workflow' => "Order sudah pada status final ({$current}) dan tidak dapat diubah.",
            ]);
        }

        // 5. The edge must exist in the canonical matrix.
        if (! LabWorkflowState::canTransition($current, $targetState)) {
            throw ValidationException::withMessages([
                'workflow' => "Transisi {$current} → {$targetState} tidak diizinkan.",
            ]);
        }

        // 6. Actor authorization (Super Admin passes via Gate::before).
        $permission = $this->permissionFor($targetState);
        if (! $actor->can($permission)) {
            throw ValidationException::withMessages([
                'workflow' => 'Anda tidak memiliki izin untuk transisi ini.',
            ]);
        }

        // 7. Branch ownership for branch-actor transitions: never trust a
        //    request branch_id; compare the order's stored branch to the
        //    resolved BranchContext. Courier/lab-side transitions are
        //    inherently cross-branch and are ownership-guarded at the
        //    pickup/delivery task level instead (config branch_scoped_transitions).
        $branchScoped = (array) config('lab_workflow.branch_scoped_transitions', []);
        if (in_array($targetState, $branchScoped, true)) {
            $contextBranchId = $this->branchContext->id();
            if ($order->branch_id !== null && $contextBranchId !== null
                && (int) $order->branch_id !== (int) $contextBranchId) {
                throw ValidationException::withMessages([
                    'workflow' => 'Order milik cabang lain tidak dapat diproses.',
                ]);
            }
        }
    }

    /**
     * Apply a transition atomically under a row lock.
     *
     * @param  array{reason?: string|null, metadata?: array<string, mixed>}  $context
     */
    public function transition(LabOrder $order, string $targetState, ?User $actor = null, array $context = []): LabOrder
    {
        $actor = $actor ?? auth()->user();

        return DB::transaction(function () use ($order, $targetState, $actor, $context) {
            /** @var LabOrder $locked */
            $locked = LabOrder::query()->lockForUpdate()->findOrFail($order->getKey());

            // Idempotent retry / concurrent double-submit: already at target -> no-op,
            // no duplicate status log or audit event.
            if ((string) $locked->status === $targetState) {
                return $locked;
            }

            $this->assertCanTransition($locked, $targetState, $actor);

            $from = (string) $locked->status;
            $reason = $context['reason'] ?? null;

            $locked->forceFill([
                'status' => $targetState,
                'updated_by' => $actor?->id,
            ])->save();

            $this->statusLogs->log($locked->id, $from, $targetState, $reason, $actor);

            $this->auditLogs->log(
                LabOrder::ENTITY_TYPE,
                $locked->id,
                AuditLog::ACTION_STATUS_CHANGE,
                ['status' => $from],
                array_filter([
                    'status' => $targetState,
                    'reason' => $reason,
                    'metadata' => $this->safeMetadata($context['metadata'] ?? []),
                ], fn ($v) => $v !== null && $v !== []),
                $actor,
            );

            return $locked->refresh();
        });
    }

    /**
     * Only scalar, non-sensitive metadata is persisted to the audit trail —
     * never binary, base64 signatures, raw notes, or personal data.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, scalar>
     */
    private function safeMetadata(array $metadata): array
    {
        $safe = [];
        foreach ($metadata as $key => $value) {
            if (is_scalar($value)) {
                $safe[(string) $key] = is_string($value) ? mb_substr($value, 0, 255) : $value;
            }
        }

        return $safe;
    }
}
