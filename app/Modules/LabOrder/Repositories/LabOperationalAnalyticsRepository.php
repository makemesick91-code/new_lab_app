<?php

namespace App\Modules\LabOrder\Repositories;

use App\Modules\LabOrder\Interfaces\LabOperationalAnalyticsRepositoryInterface;
use App\Modules\LabOrder\Models\LabExternalDispatch;
use App\Modules\LabOrder\Models\LabModelAnalysis;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use App\Modules\Production\Models\LabOrderAssignment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * LAB-PROD-2 — Operational Analytics repository (PostgreSQL / portable).
 *
 * Read-only, branch/technician scoped, bounded queries over the canonical
 * Lab Workflow V2 tables. Date grouping is done in PHP (never a DB-specific
 * date function) so the same queries run on PostgreSQL (VPS) and SQLite
 * (tests). Every query is V2-only and hard-capped by
 * config('lab_operational_analytics.max_scan_orders').
 */
class LabOperationalAnalyticsRepository implements LabOperationalAnalyticsRepositoryInterface
{
    private function cap(): int
    {
        return (int) config('lab_operational_analytics.max_scan_orders', 5000);
    }

    /**
     * V2 base order query with server-side scope + shared filters applied.
     * NEVER trusts a raw request id — callers pass already-validated scope.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<LabOrder>
     */
    private function scoped(?int $branchId, ?int $technicianId, array $filters): Builder
    {
        return LabOrder::query()
            ->where('workflow_version', LabOrder::WORKFLOW_V2)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when($technicianId !== null, fn ($q) => $q->whereHas(
                'assignments',
                fn ($a) => $a->where('technician_id', $technicianId)
            ))
            ->when(! empty($filters['lab_service_id']), fn ($q) => $q->whereHas(
                'items',
                fn ($i) => $i->where('lab_service_id', (int) $filters['lab_service_id'])
            ))
            ->when(($filters['sourcing'] ?? null) === 'external', fn ($q) => $q->whereHas('externalDispatches'))
            ->when(($filters['sourcing'] ?? null) === 'internal', fn ($q) => $q->whereDoesntHave('externalDispatches'));
    }

    public function currentStatusCounts(?int $branchId, ?int $technicianId, array $filters): array
    {
        return $this->scoped($branchId, $technicianId, $filters)
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->groupBy('status')
            ->selectRaw('status, count(*) as aggregate')
            ->pluck('aggregate', 'status')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    public function openOverdueCount(?int $branchId, ?int $technicianId, array $filters): int
    {
        return $this->scoped($branchId, $technicianId, $filters)
            ->whereNotIn('status', LabWorkflowState::TERMINAL)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', today())
            ->count();
    }

    public function ordersReceivedCount(?int $branchId, ?int $technicianId, string $from, string $to, array $filters): int
    {
        return $this->scoped($branchId, $technicianId, $filters)
            ->whereDate('order_date', '>=', $from)
            ->whereDate('order_date', '<=', $to)
            ->count();
    }

    public function deliveredTransitions(?int $branchId, ?int $technicianId, string $from, string $to, array $filters): Collection
    {
        return $this->scoped($branchId, $technicianId, $filters)
            ->whereHas('statusLogs', fn ($q) => $q->where('new_status', LabWorkflowState::DELIVERED))
            ->withMin(
                ['statusLogs as delivered_at' => fn ($q) => $q->where('new_status', LabWorkflowState::DELIVERED)],
                'changed_at'
            )
            ->latest('id')
            ->limit($this->cap())
            ->get(['id'])
            ->filter(fn ($o) => $o->delivered_at !== null
                && Carbon::parse($o->delivered_at)->betweenIncluded(
                    Carbon::parse($from)->startOfDay(),
                    Carbon::parse($to)->endOfDay()
                ))
            ->map(fn ($o) => (object) ['lab_order_id' => (int) $o->id, 'delivered_at' => (string) $o->delivered_at])
            ->values();
    }

    public function slaCompletedCases(?int $branchId, ?int $technicianId, string $from, string $to, array $filters): Collection
    {
        return $this->scoped($branchId, $technicianId, $filters)
            ->whereNotNull('due_date')
            ->whereHas('statusLogs', fn ($q) => $q->where('new_status', LabWorkflowState::DELIVERED))
            ->withMin(
                ['statusLogs as delivered_at' => fn ($q) => $q->where('new_status', LabWorkflowState::DELIVERED)],
                'changed_at'
            )
            ->latest('id')
            ->limit($this->cap())
            ->get(['id', 'due_date'])
            ->filter(fn ($o) => $o->delivered_at !== null
                && Carbon::parse($o->delivered_at)->betweenIncluded(
                    Carbon::parse($from)->startOfDay(),
                    Carbon::parse($to)->endOfDay()
                ))
            ->map(fn ($o) => (object) [
                'id' => (int) $o->id,
                'due_date' => Carbon::parse($o->due_date)->toDateString(),
                'delivered_at' => (string) $o->delivered_at,
            ])
            ->values();
    }

    public function qcAttemptSequences(?int $branchId, ?int $technicianId, string $from, string $to, array $filters): Collection
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();

        return $this->scoped($branchId, $technicianId, $filters)
            ->whereHas('statusLogs', fn ($q) => $q
                ->whereIn('new_status', [LabWorkflowState::QC_PASSED, LabWorkflowState::QC_FAILED])
                ->whereBetween('changed_at', [$start, $end]))
            ->with(['statusLogs' => fn ($q) => $q
                ->whereIn('new_status', [LabWorkflowState::QC_PASSED, LabWorkflowState::QC_FAILED])
                ->whereBetween('changed_at', [$start, $end])
                ->orderBy('changed_at')->orderBy('id')])
            ->latest('id')
            ->limit($this->cap())
            ->get(['id'])
            ->map(fn ($o) => [
                'order_id' => (int) $o->id,
                'results' => $o->statusLogs->map(fn ($l) => (string) $l->new_status)->all(),
            ])
            ->values();
    }

    public function technicianAssignmentStats(?int $branchId, ?int $technicianId, string $from, string $to, array $filters): Collection
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();

        // Assignments joined to the scoped V2 order set. Aggregation happens in
        // PHP so the technician-level grouping is portable + PII-free.
        $orderIds = $this->scoped($branchId, $technicianId, $filters)
            ->latest('id')
            ->limit($this->cap())
            ->pluck('id');

        $assignments = LabOrderAssignment::query()
            ->whereIn('lab_order_id', $orderIds)
            ->when($technicianId !== null, fn ($q) => $q->where('technician_id', $technicianId))
            ->with('technician:id,name')
            ->get(['id', 'lab_order_id', 'technician_id', 'assigned_at', 'started_at', 'completed_at']);

        $grouped = [];
        foreach ($assignments as $a) {
            $tid = (int) $a->technician_id;
            if (! isset($grouped[$tid])) {
                $grouped[$tid] = [
                    'technician_id' => $tid,
                    'name' => $a->technician?->name ?? 'Teknisi',
                    'active_wip' => 0,
                    'assigned' => 0,
                    'completed' => 0,
                    'completion_minutes' => [],
                ];
            }

            $assignedAt = $a->assigned_at ? Carbon::parse($a->assigned_at) : null;
            $completedAt = $a->completed_at ? Carbon::parse($a->completed_at) : null;
            $startedAt = $a->started_at ? Carbon::parse($a->started_at) : null;

            if ($completedAt === null) {
                $grouped[$tid]['active_wip']++;
            }
            if ($assignedAt !== null && $assignedAt->betweenIncluded($start, $end)) {
                $grouped[$tid]['assigned']++;
            }
            if ($completedAt !== null && $completedAt->betweenIncluded($start, $end)) {
                $grouped[$tid]['completed']++;
                $base = $startedAt ?? $assignedAt;
                if ($base !== null && $completedAt->greaterThanOrEqualTo($base)) {
                    $grouped[$tid]['completion_minutes'][] = round($base->diffInSeconds($completedAt) / 60, 1);
                }
            }
        }

        return collect(array_values($grouped));
    }

    public function analysisDecisionCounts(?int $branchId, ?int $technicianId, string $from, string $to, array $filters): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();

        $orderIds = $this->scoped($branchId, $technicianId, $filters)
            ->latest('id')
            ->limit($this->cap())
            ->pluck('id');

        $rows = LabModelAnalysis::query()
            ->whereIn('lab_order_id', $orderIds)
            ->whereNotNull('analyzed_at')
            ->whereBetween('analyzed_at', [$start, $end])
            ->groupBy('decision')
            ->selectRaw('decision, count(*) as aggregate')
            ->pluck('aggregate', 'decision');

        return [
            'internal' => (int) ($rows['INTERNAL'] ?? 0),
            'external' => (int) ($rows['EXTERNAL'] ?? 0),
        ];
    }

    public function externalTurnarounds(?int $branchId, ?int $technicianId, string $from, string $to, array $filters): Collection
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();

        $orderIds = $this->scoped($branchId, $technicianId, $filters)
            ->latest('id')
            ->limit($this->cap())
            ->pluck('id');

        return LabExternalDispatch::query()
            ->whereIn('lab_order_id', $orderIds)
            ->whereNotNull('sent_at')
            ->whereNotNull('returned_at')
            ->whereBetween('returned_at', [$start, $end])
            ->limit($this->cap())
            ->get(['sent_at', 'returned_at'])
            ->map(fn ($d) => (object) ['sent_at' => (string) $d->sent_at, 'returned_at' => (string) $d->returned_at]);
    }

    public function dataQualityCounts(?int $branchId, ?int $technicianId, string $from, string $to, array $filters): array
    {
        $base = fn () => $this->scoped($branchId, $technicianId, $filters)
            ->whereDate('order_date', '>=', $from)
            ->whereDate('order_date', '<=', $to);

        $total = (clone $base())->count();
        $withDue = (clone $base())->whereNotNull('due_date')->count();
        $stuckThreshold = now()->subDays((int) config('lab_operational_analytics.stuck_idle_days', 3));

        $stuck = (clone $base())
            ->whereNotIn('status', LabWorkflowState::TERMINAL)
            ->withMax('statusLogs as latest_change', 'changed_at')
            ->get(['id'])
            ->filter(fn ($o) => $o->latest_change !== null
                && Carbon::parse($o->latest_change)->lessThan($stuckThreshold))
            ->count();

        return [
            'total' => $total,
            'with_due_date' => $withDue,
            'without_due_date' => $total - $withDue,
            'stuck' => $stuck,
        ];
    }

    public function drilldownOrders(?int $branchId, ?int $technicianId, string $from, string $to, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->scoped($branchId, $technicianId, $filters)
            ->whereDate('order_date', '>=', $from)
            ->whereDate('order_date', '<=', $to)
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(($filters['sla_state'] ?? null) === 'overdue', fn ($q) => $q
                ->whereNotIn('status', LabWorkflowState::TERMINAL)
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', today()))
            ->with(['branch:id,name', 'patient:id,name'])
            ->latest('order_date')
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }
}
