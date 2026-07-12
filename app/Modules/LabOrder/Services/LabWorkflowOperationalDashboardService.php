<?php

namespace App\Modules\LabOrder\Services;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Branch\Services\BranchService;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabOrderStatusLog;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * LAB-WORKFLOW-V2 (Phase 8) — Operational dashboard read model.
 *
 * Pure, read-only aggregation of V2 lab orders into operational status
 * buckets. NO writes, NO workflow state mutation, NO PII beyond a patient
 * display name in the recent-activity feed. Scoping is enforced SERVER-SIDE:
 * lab staff (manage_lab_orders) see every branch (lab operations are
 * cross-branch by design); branch operators are locked to their own
 * BranchContext branch. A requested branch filter is only honoured for lab
 * staff and is validated against the active RME-enabled branch set (a crafted
 * id is dropped). Counts come from a SINGLE GROUP BY query (no N+1).
 */
class LabWorkflowOperationalDashboardService
{
    /** An active (non-terminal) order idle longer than this is "overdue". */
    private const OVERDUE_DAYS = 3;

    public function __construct(
        private readonly BranchContext $branchContext,
        private readonly BranchService $branches,
        private readonly LabWorkflowResolver $resolver,
    ) {}

    /**
     * Operational overview for the given user.
     *
     * @return array<string, mixed>
     */
    public function overview(User $user, ?string $from = null, ?string $to = null, ?int $requestedBranchId = null): array
    {
        $seesAll = $user->can('manage_lab_orders');
        $branchId = $seesAll
            ? $this->resolveRequestedBranch($requestedBranchId)
            : $this->branchContext->forUser($user);

        // For a branch operator whose branch cannot be resolved, force an empty
        // scope (0 matches nothing) rather than leaking cross-branch data.
        $effectiveBranchId = $seesAll ? $branchId : ($branchId ?? 0);
        $branchConstrained = ! $seesAll || $branchId !== null;

        $counts = LabOrder::query()
            ->where('workflow_version', LabOrder::WORKFLOW_V2)
            ->when($branchConstrained, fn ($q) => $q->where('branch_id', $effectiveBranchId))
            ->when($from !== null, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->groupBy('status')
            ->selectRaw('status, count(*) as aggregate')
            ->pluck('aggregate', 'status');

        $buckets = $this->buildBuckets($counts);
        $productionBreakdown = $this->buildProductionBreakdown($counts);

        $activeTotal = 0;
        foreach ($counts as $status => $count) {
            if (! LabWorkflowState::isTerminal((string) $status)) {
                $activeTotal += (int) $count;
            }
        }

        $scopeLabel = $seesAll
            ? ($branchId !== null ? 'branch:'.$branchId : 'all_branches')
            : 'branch:'.($branchId ?? 'none');

        return [
            'buckets' => $buckets,
            'production_breakdown' => $productionBreakdown,
            'delivered_today' => $this->deliveredTodayCount($branchConstrained, $effectiveBranchId, $from, $to),
            'overdue' => $this->overdueCount($branchConstrained, $effectiveBranchId),
            'active_total' => $activeTotal,
            'recent_activity' => $this->recentActivity($branchConstrained, $effectiveBranchId),
            'branch_options' => $seesAll ? $this->branchOptions() : [],
            'branch_id' => $branchId,
            // Fail-closed branch id actually used for filtering. Null ONLY when
            // lab staff view all branches; a branch operator always gets a
            // concrete id (0 = matches nothing) so downstream reports can never
            // be widened to another branch.
            'effective_branch_id' => $branchConstrained ? $effectiveBranchId : null,
            'sees_all' => $seesAll,
            'scope' => $scopeLabel,
            'v2_active' => $this->resolver->isV2Active(),
            'generated_at' => now(),
        ];
    }

    /**
     * Ordered operational buckets. Each bucket sums a set of V2 states.
     *
     * @param  Collection<string, int>  $counts
     * @return array<int, array{key: string, label: string, count: int, tone: string, route: string}>
     */
    private function buildBuckets($counts): array
    {
        $definitions = [
            ['waiting_pickup', 'Menunggu Pickup', 'warning', 'lab-pickup-tasks.index', [LabWorkflowState::WAITING_PICKUP]],
            ['pickup_accepted', 'Pickup Diterima', 'info', 'lab-pickup-tasks.index', [LabWorkflowState::PICKUP_ACCEPTED, LabWorkflowState::PICKED_UP]],
            ['in_transit_to_lab', 'Dalam Perjalanan ke Lab', 'info', 'lab-pickup-tasks.index', [LabWorkflowState::IN_TRANSIT_TO_LAB]],
            ['received_at_lab', 'Diterima di Lab', 'info', 'lab-v2-orders.index', [LabWorkflowState::RECEIVED_AT_LAB, LabWorkflowState::MODEL_REGISTERED]],
            ['analysis_pending', 'Menunggu Analisa', 'warning', 'lab-v2-orders.index', [LabWorkflowState::MODEL_ANALYSIS_PENDING]],
            ['internal_production', 'Produksi Internal', 'info', 'lab-v2-orders.index', $this->internalProductionStates()],
            ['qc_pending', 'Menunggu QC', 'warning', 'lab-v2-orders.index', [LabWorkflowState::QC_PENDING]],
            ['qc_rework', 'QC Gagal / Rework', 'danger', 'lab-v2-orders.index', [LabWorkflowState::QC_FAILED, LabWorkflowState::REWORK_REQUIRED]],
            ['external_outstanding', 'Lab Eksternal Berjalan', 'info', 'lab-v2-orders.index', $this->externalOutstandingStates()],
            ['model_done', 'Model Selesai', 'success', 'lab-v2-orders.index', [LabWorkflowState::MODEL_DONE]],
            ['delivery_pending', 'Menunggu Pengiriman', 'warning', 'lab-delivery-tasks.index', $this->deliveryPendingStates()],
            ['in_transit_to_branch', 'Dalam Perjalanan ke Cabang', 'info', 'lab-delivery-tasks.index', $this->inTransitToBranchStates()],
        ];

        $buckets = [];
        foreach ($definitions as [$key, $label, $tone, $route, $states]) {
            $buckets[] = [
                'key' => $key,
                'label' => $label,
                'count' => $this->sumStates($counts, $states),
                'tone' => $tone,
                'route' => $route,
            ];
        }

        return $buckets;
    }

    /**
     * Per-step internal production breakdown (the STEP_* states).
     *
     * @param  Collection<string, int>  $counts
     * @return array<int, array{state: string, label: string, count: int}>
     */
    private function buildProductionBreakdown($counts): array
    {
        $rows = [];
        foreach (LabWorkflowState::V2_PRODUCTION_STEPS as $startState => $completedState) {
            foreach ([$startState, $completedState] as $state) {
                $rows[] = [
                    'state' => $state,
                    'label' => $this->humanize($state),
                    'count' => (int) ($counts[$state] ?? 0),
                ];
            }
        }

        return $rows;
    }

    /** @return list<string> */
    private function internalProductionStates(): array
    {
        return array_merge(
            [
                LabWorkflowState::INTERNAL_APPROVED,
                LabWorkflowState::TECHNICIAN_ASSIGNMENT_PENDING,
                LabWorkflowState::TECHNICIAN_ASSIGNED,
            ],
            array_keys(LabWorkflowState::V2_PRODUCTION_STEPS),
            array_values(LabWorkflowState::V2_PRODUCTION_STEPS),
        );
    }

    /** @return list<string> */
    private function externalOutstandingStates(): array
    {
        return [
            LabWorkflowState::EXTERNAL_LAB_REQUIRED,
            LabWorkflowState::EXTERNAL_LAB_PREPARATION,
            LabWorkflowState::EXTERNAL_LAB_SENT,
            LabWorkflowState::EXTERNAL_LAB_IN_PROGRESS,
            LabWorkflowState::EXTERNAL_LAB_RETURNED,
            LabWorkflowState::EXTERNAL_LAB_RESULT_REVIEW,
        ];
    }

    /** @return list<string> */
    private function deliveryPendingStates(): array
    {
        return [
            LabWorkflowState::DELIVERY_PENDING,
            LabWorkflowState::COURIER_NOTIFIED,
            LabWorkflowState::DELIVERY_ACCEPTED,
            LabWorkflowState::LAB_HANDOVER_PENDING,
            LabWorkflowState::PRE_DELIVERY_PHOTO_CAPTURED,
            LabWorkflowState::COURIER_SIGNATURE_CAPTURED,
            LabWorkflowState::READY_FOR_TRANSIT_TO_BRANCH,
        ];
    }

    /** @return list<string> */
    private function inTransitToBranchStates(): array
    {
        return [
            LabWorkflowState::IN_TRANSIT_TO_BRANCH,
            LabWorkflowState::ARRIVED_AT_BRANCH,
            LabWorkflowState::RECIPIENT_SIGNATURE_CAPTURED,
            LabWorkflowState::DELIVERY_LOCATION_PHOTO_CAPTURED,
        ];
    }

    /**
     * @param  Collection<string, int>  $counts
     * @param  list<string>  $states
     */
    private function sumStates($counts, array $states): int
    {
        $sum = 0;
        foreach ($states as $state) {
            $sum += (int) ($counts[$state] ?? 0);
        }

        return $sum;
    }

    private function deliveredTodayCount(bool $branchConstrained, ?int $effectiveBranchId, ?string $from, ?string $to): int
    {
        return LabOrder::query()
            ->where('workflow_version', LabOrder::WORKFLOW_V2)
            ->where('status', LabWorkflowState::DELIVERED)
            ->when($branchConstrained, fn ($q) => $q->where('branch_id', $effectiveBranchId))
            ->whereHas('deliveryTask', fn ($q) => $q->whereDate('delivered_at', today()))
            ->count();
    }

    private function overdueCount(bool $branchConstrained, ?int $effectiveBranchId): int
    {
        $threshold = now()->subDays(self::OVERDUE_DAYS);

        return LabOrder::query()
            ->where('workflow_version', LabOrder::WORKFLOW_V2)
            ->whereNotIn('status', LabWorkflowState::TERMINAL)
            ->when($branchConstrained, fn ($q) => $q->where('branch_id', $effectiveBranchId))
            ->withMax('statusLogs as latest_change', 'changed_at')
            ->get(['id', 'status'])
            ->filter(fn (LabOrder $order): bool => $order->latest_change !== null
                && Carbon::parse($order->latest_change)->lessThan($threshold))
            ->count();
    }

    /**
     * Last ~10 status transitions. Privacy-safe: order number + patient display
     * name + new status + time only (NO KTP/NIK, NO clinical data).
     *
     * @return array<int, array{order_number: string|null, patient_name: string, new_status: string, changed_at: Carbon|null}>
     */
    private function recentActivity(bool $branchConstrained, ?int $effectiveBranchId): array
    {
        return LabOrderStatusLog::query()
            ->whereHas('labOrder', fn ($q) => $q
                ->where('workflow_version', LabOrder::WORKFLOW_V2)
                ->when($branchConstrained, fn ($qq) => $qq->where('branch_id', $effectiveBranchId)))
            ->with(['labOrder:id,order_number,patient_id', 'labOrder.patient:id,name'])
            ->latest('changed_at')
            ->latest('id')
            ->limit(10)
            ->get(['id', 'lab_order_id', 'new_status', 'changed_at'])
            ->map(fn (LabOrderStatusLog $log): array => [
                'order_number' => $log->labOrder?->order_number,
                'patient_name' => $log->labOrder?->patient?->name ?? 'Pasien',
                'new_status' => (string) $log->new_status,
                'changed_at' => $log->changed_at ? Carbon::parse($log->changed_at) : null,
            ])
            ->all();
    }

    /**
     * The requested branch filter is only honoured when it is an active
     * RME-enabled branch (the allowed set) — a crafted id resolves to null
     * (all branches). Never trusts a raw request branch id.
     */
    private function resolveRequestedBranch(?int $requestedBranchId): ?int
    {
        if ($requestedBranchId === null || $requestedBranchId <= 0) {
            return null;
        }

        return in_array($requestedBranchId, $this->branches->rmeEnabledIds(), true)
            ? $requestedBranchId
            : null;
    }

    /**
     * Active RME-enabled branches for the lab-staff branch filter dropdown.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function branchOptions(): array
    {
        return Branch::query()
            ->whereIn('id', $this->branches->rmeEnabledIds())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Branch $b): array => ['id' => $b->id, 'name' => $b->name])
            ->all();
    }

    private function humanize(string $state): string
    {
        return ucwords(strtolower(str_replace('_', ' ', $state)));
    }
}
