<?php

namespace App\Modules\LabOrder\Services;

use App\Models\User;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\ExternalLab;
use App\Modules\LabOrder\Models\LabModelAnalysis;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * LAB-WORKFLOW-V2 (Phase 3) — model registration + analysis decision.
 *
 * RECEIVED_AT_LAB -> MODEL_REGISTERED -> MODEL_ANALYSIS_PENDING, then a
 * mandatory analysis decision routes the order to the internal production path
 * (INTERNAL_APPROVED) or the external lab path (EXTERNAL_LAB_REQUIRED).
 * Decisions are append-only rows with actor + reason (owner rule).
 */
class LabModelAnalysisService
{
    public function __construct(
        private readonly LabWorkflowStateMachine $stateMachine,
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * Register the received model and open analysis in one operator action
     * (two audited state-machine transitions).
     */
    public function registerModel(LabOrder $order, ?User $actor = null): LabOrder
    {
        $actor = $actor ?? auth()->user();

        $this->stateMachine->transition($order, LabWorkflowState::MODEL_REGISTERED, $actor, [
            'reason' => 'Model divalidasi dan didaftarkan',
        ]);

        return $this->stateMachine->transition(
            $order->refresh(),
            LabWorkflowState::MODEL_ANALYSIS_PENDING,
            $actor,
            ['reason' => 'Menunggu analisa model'],
        );
    }

    /**
     * Record the analysis decision and route the workflow.
     *
     * @param  array{decision: string, reason: string, notes?: string|null, external_lab_id?: int|null}  $data
     */
    public function decide(LabOrder $order, array $data, ?User $actor = null): LabModelAnalysis
    {
        $actor = $actor ?? auth()->user();
        $decision = (string) ($data['decision'] ?? '');

        if (! in_array($decision, LabModelAnalysis::DECISIONS, true)) {
            throw ValidationException::withMessages([
                'decision' => 'Keputusan analisa harus Internal atau External.',
            ]);
        }

        if ((string) $order->status !== LabWorkflowState::MODEL_ANALYSIS_PENDING) {
            throw ValidationException::withMessages([
                'status' => 'Analisa hanya dapat dilakukan pada model yang menunggu analisa.',
            ]);
        }

        $externalLab = null;
        if ($decision === LabModelAnalysis::DECISION_EXTERNAL) {
            $externalLab = ExternalLab::query()->find((int) ($data['external_lab_id'] ?? 0));

            if (! $externalLab || ! $externalLab->is_active) {
                throw ValidationException::withMessages([
                    'external_lab_id' => 'Lab eksternal tujuan wajib dipilih dan aktif.',
                ]);
            }
        }

        return DB::transaction(function () use ($order, $data, $decision, $externalLab, $actor) {
            $target = $decision === LabModelAnalysis::DECISION_INTERNAL
                ? LabWorkflowState::INTERNAL_APPROVED
                : LabWorkflowState::EXTERNAL_LAB_REQUIRED;

            // Re-validates edge/permission under row lock.
            $this->stateMachine->transition($order, $target, $actor, [
                'reason' => $data['reason'],
            ]);

            $analysis = LabModelAnalysis::create([
                'lab_order_id' => $order->id,
                'decision' => $decision,
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
                'external_lab_id' => $externalLab?->id,
                'analyzed_by' => $actor->id,
                'analyzed_at' => now(),
            ]);

            $this->auditLogs->log(
                LabOrder::ENTITY_TYPE,
                $order->id,
                AuditLog::ACTION_UPDATE,
                null,
                [
                    'analysis_id' => $analysis->id,
                    'decision' => $decision,
                    'external_lab_id' => $externalLab?->id,
                ],
                $actor,
            );

            return $analysis;
        });
    }
}
