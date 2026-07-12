<?php

namespace App\Modules\LabOrder\Services;

use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use Illuminate\Support\Carbon;

/**
 * LAB-WORKFLOW-V2 (Phase 9) — SLA / cycle-time BASELINE metrics.
 *
 * Pure, read-only reporting service. Computes per-stage cycle-time baselines
 * from the append-only trx_lab_order_status_logs timeline (changed_at only —
 * never updated_at, which does not exist on the log). NO writes, NO workflow
 * state mutation, NO PII (patient identity is never touched here).
 *
 * These are pilot BASELINE figures, not a final benchmark: durations are the
 * diff between the FIRST time an order entered a stage's from-state and the
 * first time it entered the corresponding to-state (rework re-entries are not
 * separately timed in the baseline). Queries are bounded (V2 orders only, an
 * optional date window, and a hard order cap) and eager-load statusLogs to
 * avoid N+1.
 */
class LabWorkflowSlaBaselineService
{
    /** Hard cap on the number of orders scanned for the baseline. */
    private const MAX_ORDERS = 2000;

    /** An active (non-terminal) order idle longer than this is "overdue". */
    private const OVERDUE_DAYS = 3;

    public const NOTE = 'Baseline pilot — bukan benchmark final';

    /**
     * Stage definitions: key => [label, from-state, [candidate to-states]].
     * The duration for a stage is measured to the first candidate to-state the
     * order reached at/after entering the from-state.
     *
     * @return array<string, array{0: string, 1: string, 2: list<string>}>
     */
    private function stageDefinitions(): array
    {
        return [
            'request_to_pickup' => ['Permintaan → Pickup diterima', LabWorkflowState::WAITING_PICKUP, [LabWorkflowState::PICKUP_ACCEPTED]],
            'pickup_to_received' => ['Pickup → Diterima lab', LabWorkflowState::PICKED_UP, [LabWorkflowState::RECEIVED_AT_LAB]],
            'received_to_analysis' => ['Diterima → Keputusan analisa', LabWorkflowState::RECEIVED_AT_LAB, [LabWorkflowState::INTERNAL_APPROVED, LabWorkflowState::EXTERNAL_LAB_REQUIRED]],
            'assignment_wait' => ['Menunggu penugasan teknisi', LabWorkflowState::TECHNICIAN_ASSIGNMENT_PENDING, [LabWorkflowState::TECHNICIAN_ASSIGNED]],
            'step_1' => ['Step 1 — Blockout / Duplicate', LabWorkflowState::STEP_1_BLOCKOUT_DUPLICATE, [LabWorkflowState::STEP_1_COMPLETED]],
            'step_2' => ['Step 2 — Teeth setup', LabWorkflowState::STEP_2_TEETH_SETUP, [LabWorkflowState::STEP_2_COMPLETED]],
            'step_3' => ['Step 3 — Processing', LabWorkflowState::STEP_3_PROCESSING, [LabWorkflowState::STEP_3_COMPLETED]],
            'step_4' => ['Step 4 — Fitting / Polish', LabWorkflowState::STEP_4_FITTING_POLISH, [LabWorkflowState::STEP_4_COMPLETED]],
            'qc_wait' => ['Menunggu keputusan QC', LabWorkflowState::QC_PENDING, [LabWorkflowState::QC_PASSED, LabWorkflowState::QC_FAILED]],
            'external_turnaround' => ['Turnaround lab eksternal', LabWorkflowState::EXTERNAL_LAB_SENT, [LabWorkflowState::EXTERNAL_LAB_RETURNED]],
            'model_done_to_delivery' => ['Model selesai → Mulai kirim', LabWorkflowState::MODEL_DONE, [LabWorkflowState::IN_TRANSIT_TO_BRANCH]],
            'delivery_to_delivered' => ['Mulai kirim → Terkirim', LabWorkflowState::IN_TRANSIT_TO_BRANCH, [LabWorkflowState::DELIVERED]],
            'total_lead_time' => ['Total lead time (permintaan → terkirim)', LabWorkflowState::WAITING_PICKUP, [LabWorkflowState::DELIVERED]],
        ];
    }

    /**
     * Compute the SLA / cycle-time baseline for V2 orders.
     *
     * @return array{
     *     stages: array<int, array{key: string, label: string, count: int, avg_minutes: float|null, median_minutes: float|null, min_minutes: float|null, max_minutes: float|null}>,
     *     rework_count: int,
     *     rework_orders: int,
     *     overdue: int,
     *     orders_analyzed: int,
     *     note: string,
     *     generated_at: Carbon,
     * }
     */
    public function baseline(?int $branchId = null, ?string $from = null, ?string $to = null): array
    {
        $orders = LabOrder::query()
            ->where('workflow_version', LabOrder::WORKFLOW_V2)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when($from !== null, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->with(['statusLogs' => fn ($q) => $q->orderBy('changed_at')->orderBy('id')])
            ->latest('id')
            ->limit(self::MAX_ORDERS)
            ->get(['id', 'status', 'branch_id', 'created_at']);

        $definitions = $this->stageDefinitions();

        /** @var array<string, list<float>> $durations minutes collected per stage key */
        $durations = [];
        foreach ($definitions as $key => $_) {
            $durations[$key] = [];
        }

        $reworkCount = 0;
        $reworkOrders = 0;
        $overdue = 0;
        $overdueThreshold = now()->subDays(self::OVERDUE_DAYS);

        foreach ($orders as $order) {
            $logs = $order->statusLogs;

            // First time the order ENTERED each state (new_status), by changed_at.
            $entered = [];
            $orderRework = 0;
            $latestChange = null;

            foreach ($logs as $log) {
                $state = (string) $log->new_status;
                $changedAt = $log->changed_at ? Carbon::parse($log->changed_at) : null;
                if ($changedAt === null) {
                    continue;
                }

                if (! array_key_exists($state, $entered)) {
                    $entered[$state] = $changedAt;
                }

                if ($state === LabWorkflowState::QC_FAILED) {
                    $orderRework++;
                }

                if ($latestChange === null || $changedAt->greaterThan($latestChange)) {
                    $latestChange = $changedAt;
                }
            }

            $reworkCount += $orderRework;
            if ($orderRework > 0) {
                $reworkOrders++;
            }

            // Overdue: active (non-terminal) order idle past the threshold.
            if (! LabWorkflowState::isTerminal((string) $order->status)
                && $latestChange !== null
                && $latestChange->lessThan($overdueThreshold)) {
                $overdue++;
            }

            foreach ($definitions as $key => [$label, $fromState, $toStates]) {
                if (! isset($entered[$fromState])) {
                    continue;
                }
                $start = $entered[$fromState];

                // First candidate to-state reached at/after the from-state.
                $end = null;
                foreach ($toStates as $toState) {
                    if (isset($entered[$toState]) && $entered[$toState]->greaterThanOrEqualTo($start)) {
                        if ($end === null || $entered[$toState]->lessThan($end)) {
                            $end = $entered[$toState];
                        }
                    }
                }

                if ($end !== null) {
                    $durations[$key][] = max(0.0, round($start->diffInSeconds($end) / 60, 1));
                }
            }
        }

        $stages = [];
        foreach ($definitions as $key => [$label]) {
            $stages[] = $this->summariseStage($key, $label, $durations[$key]);
        }

        return [
            'stages' => $stages,
            'rework_count' => $reworkCount,
            'rework_orders' => $reworkOrders,
            'overdue' => $overdue,
            'orders_analyzed' => $orders->count(),
            'note' => self::NOTE,
            'generated_at' => now(),
        ];
    }

    /**
     * @param  list<float>  $minutes
     * @return array{key: string, label: string, count: int, avg_minutes: float|null, median_minutes: float|null, min_minutes: float|null, max_minutes: float|null}
     */
    private function summariseStage(string $key, string $label, array $minutes): array
    {
        $count = count($minutes);

        if ($count === 0) {
            return [
                'key' => $key,
                'label' => $label,
                'count' => 0,
                'avg_minutes' => null,
                'median_minutes' => null,
                'min_minutes' => null,
                'max_minutes' => null,
            ];
        }

        return [
            'key' => $key,
            'label' => $label,
            'count' => $count,
            'avg_minutes' => round(array_sum($minutes) / $count, 1),
            'median_minutes' => $this->median($minutes),
            'min_minutes' => round(min($minutes), 1),
            'max_minutes' => round(max($minutes), 1),
        ];
    }

    /**
     * Portable median (no external dependency).
     *
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return round($values[$middle], 1);
        }

        return round(($values[$middle - 1] + $values[$middle]) / 2, 1);
    }
}
