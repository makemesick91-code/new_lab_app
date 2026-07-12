<?php

namespace App\Modules\LabOrder\Services;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Branch\Services\BranchService;
use App\Modules\LabOrder\Interfaces\LabOperationalAnalyticsRepositoryInterface;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use App\Modules\LabService\Models\LabService;
use App\Modules\Technician\Models\Technician;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * LAB-PROD-2 — Operational Analytics & KPI service.
 *
 * The single source of truth for every Lab Workflow V2 KPI formula and its
 * denominator. Business logic ONLY (no query strings — those live in the
 * repository; no HTML — that lives in the view). Reuses
 * LabWorkflowSlaBaselineService for canonical per-stage cycle-time baselines so
 * cycle-time is never re-derived in two places.
 *
 * Authorization tiers (resolved server-side, IDOR-safe):
 *  - full : management/owner — all RME branches, branch filter honoured.
 *  - own  : linked technician — forced to their own technician_id.
 *  - denied: no access (controller aborts 403).
 *
 * Rules: no fabricated metrics; missing data surfaced as excluded/coverage,
 * never a fake 0; no PII in any figure; historical logs never mutated.
 */
class LabOperationalAnalyticsService
{
    public function __construct(
        private readonly LabOperationalAnalyticsRepositoryInterface $repository,
        private readonly LabWorkflowSlaBaselineService $slaBaseline,
        private readonly BranchContext $branchContext,
        private readonly BranchService $branches,
    ) {}

    /**
     * Resolve the caller's analytics scope.
     *
     * @return array{tier: string, sees_all: bool, branch_id: int|null, technician_id: int|null, technician_name: string|null}
     */
    public function resolveScope(User $user, ?int $requestedBranchId, ?int $requestedTechnicianId): array
    {
        $perms = config('lab_operational_analytics.permissions');

        if ($user->can($perms['full']) || $user->can($perms['manage'])) {
            $branchId = $this->validateBranch($requestedBranchId);
            $technicianId = $requestedTechnicianId && $requestedTechnicianId > 0 ? $requestedTechnicianId : null;

            return [
                'tier' => 'full',
                'sees_all' => $branchId === null,
                'branch_id' => $branchId,
                'technician_id' => $technicianId,
                'technician_name' => null,
            ];
        }

        if ($user->can($perms['own'])) {
            $technician = Technician::query()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->first(['id', 'name']);

            if ($technician === null) {
                // Own-permission holder not linked to an active technician record.
                return ['tier' => 'denied', 'sees_all' => false, 'branch_id' => null, 'technician_id' => null, 'technician_name' => null];
            }

            return [
                'tier' => 'own',
                'sees_all' => false,
                'branch_id' => null, // technicians operate cross-branch
                'technician_id' => (int) $technician->id, // forced — request id ignored
                'technician_name' => (string) $technician->name,
            ];
        }

        return ['tier' => 'denied', 'sees_all' => false, 'branch_id' => null, 'technician_id' => null, 'technician_name' => null];
    }

    /**
     * Full analytics payload for the resolved scope + filters.
     *
     * @param  array{tier: string, branch_id: int|null, technician_id: int|null, sees_all: bool, technician_name: string|null}  $scope
     * @param  array<string, mixed>  $filters  period, custom from/to, lab_service_id, status, sourcing, sla_state, technician_id
     * @return array<string, mixed>
     */
    public function analytics(array $scope, array $filters): array
    {
        $branchId = $scope['branch_id'];
        $technicianId = $scope['technician_id'];
        $period = $this->resolvePeriod($filters['period'] ?? config('lab_operational_analytics.default_period'), $filters['from'] ?? null, $filters['to'] ?? null);
        [$from, $to] = [$period['from'], $period['to']];

        // Shared filters passed to every repository call.
        $repoFilters = [
            'lab_service_id' => $filters['lab_service_id'] ?? null,
            'status' => $filters['status'] ?? null,
            'sourcing' => $filters['sourcing'] ?? null,
            'sla_state' => $filters['sla_state'] ?? null,
        ];

        $statusCounts = $this->repository->currentStatusCounts($branchId, $technicianId, $repoFilters);
        $delivered = $this->repository->deliveredTransitions($branchId, $technicianId, $from, $to, $repoFilters);
        $deliveredPrev = $this->repository->deliveredTransitions($branchId, $technicianId, $period['prev_from'], $period['prev_to'], $repoFilters);

        $kpi = [
            'orders_received' => $this->repository->ordersReceivedCount($branchId, $technicianId, $from, $to, $repoFilters),
            'open_wip' => $this->sumNonTerminal($statusCounts),
            'rework_active' => (int) ($statusCounts[LabWorkflowState::QC_FAILED] ?? 0) + (int) ($statusCounts[LabWorkflowState::REWORK_REQUIRED] ?? 0),
            'open_overdue' => $this->repository->openOverdueCount($branchId, $technicianId, $repoFilters),
            'throughput' => $delivered->count(),
            'throughput_prev' => $deliveredPrev->count(),
            'throughput_delta' => $delivered->count() - $deliveredPrev->count(),
            'sla' => $this->slaCompliance($branchId, $technicianId, $from, $to, $repoFilters),
            'qc' => $this->qcQuality($branchId, $technicianId, $from, $to, $repoFilters),
            'internal_vs_external' => $this->internalVsExternal($branchId, $technicianId, $from, $to, $repoFilters),
            'external_turnaround' => $this->externalTurnaround($branchId, $technicianId, $from, $to, $repoFilters),
        ];

        return [
            'scope' => $this->scopeMeta($scope),
            'period' => $period,
            'kpi' => $kpi,
            'wip_by_stage' => $this->wipByStage($statusCounts),
            'throughput_trend' => $this->trend($delivered, $from, $to),
            // Cycle-time stage baseline (single source of truth) — full tier only.
            'cycle_time' => $scope['tier'] === 'full'
                ? $this->slaBaseline->baseline($branchId, $from, $to)
                : null,
            'technicians' => $this->technicianKpis($branchId, $technicianId, $from, $to, $repoFilters),
            'data_quality' => $this->dataQuality($branchId, $technicianId, $from, $to, $repoFilters, $delivered->count(), $kpi['sla']['eligible']),
            'note' => 'Angka operasional dari data kanonik Lab Workflow V2. Data tidak lengkap ditandai sebagai dikecualikan, bukan nol.',
            'generated_at' => now(),
        ];
    }

    // ---- KPI formulas -------------------------------------------------------

    /**
     * SLA compliance vs the ONLY canonical deadline (trx_lab_orders.due_date).
     * Orders without a due_date are never counted here — they surface in
     * data-quality. On-time = delivered date <= due_date (end of due day).
     *
     * @param  array<string, mixed>  $filters
     * @return array{eligible: int, on_time: int, late: int, compliance_pct: float|null, median_lateness_days: float|null, open_overdue: int}
     */
    private function slaCompliance(?int $branchId, ?int $technicianId, string $from, string $to, array $filters): array
    {
        $cases = $this->repository->slaCompletedCases($branchId, $technicianId, $from, $to, $filters);

        $onTime = 0;
        $late = 0;
        $lateness = [];
        foreach ($cases as $case) {
            $due = Carbon::parse($case->due_date)->endOfDay();
            $delivered = Carbon::parse($case->delivered_at);
            if ($delivered->lessThanOrEqualTo($due)) {
                $onTime++;
            } else {
                $late++;
                $lateness[] = round($due->diffInMinutes($delivered) / 1440, 2); // days late
            }
        }

        $eligible = $cases->count();

        return [
            'eligible' => $eligible,
            'on_time' => $onTime,
            'late' => $late,
            'compliance_pct' => $eligible > 0 ? round($onTime / $eligible * 100, 1) : null,
            'median_lateness_days' => $lateness === [] ? null : $this->median($lateness),
            'open_overdue' => $this->repository->openOverdueCount($branchId, $technicianId, $filters),
        ];
    }

    /**
     * QC first-pass yield + rework rate from the append-only QC transitions.
     * Denominator = orders with at least one QC attempt in the period.
     *
     * @param  array<string, mixed>  $filters
     * @return array{attempts: int, first_pass: int, first_pass_yield_pct: float|null, rework_orders: int, rework_rate_pct: float|null}
     */
    private function qcQuality(?int $branchId, ?int $technicianId, string $from, string $to, array $filters): array
    {
        $sequences = $this->repository->qcAttemptSequences($branchId, $technicianId, $from, $to, $filters);

        $withAttempt = 0;
        $firstPass = 0;
        $reworkOrders = 0;
        foreach ($sequences as $seq) {
            $results = $seq['results'];
            if ($results === []) {
                continue;
            }
            $withAttempt++;
            if ($results[0] === LabWorkflowState::QC_PASSED) {
                $firstPass++;
            }
            if (in_array(LabWorkflowState::QC_FAILED, $results, true)) {
                $reworkOrders++;
            }
        }

        return [
            'attempts' => $withAttempt,
            'first_pass' => $firstPass,
            'first_pass_yield_pct' => $withAttempt > 0 ? round($firstPass / $withAttempt * 100, 1) : null,
            'rework_orders' => $reworkOrders,
            'rework_rate_pct' => $withAttempt > 0 ? round($reworkOrders / $withAttempt * 100, 1) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{internal: int, external: int, total: int}
     */
    private function internalVsExternal(?int $branchId, ?int $technicianId, string $from, string $to, array $filters): array
    {
        $counts = $this->repository->analysisDecisionCounts($branchId, $technicianId, $from, $to, $filters);

        return [
            'internal' => $counts['internal'],
            'external' => $counts['external'],
            'total' => $counts['internal'] + $counts['external'],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{count: int, median_days: float|null}
     */
    private function externalTurnaround(?int $branchId, ?int $technicianId, string $from, string $to, array $filters): array
    {
        $rows = $this->repository->externalTurnarounds($branchId, $technicianId, $from, $to, $filters);

        $days = [];
        foreach ($rows as $row) {
            $sent = Carbon::parse($row->sent_at);
            $returned = Carbon::parse($row->returned_at);
            if ($returned->greaterThanOrEqualTo($sent)) {
                $days[] = round($sent->diffInMinutes($returned) / 1440, 2);
            }
        }

        return [
            'count' => count($days),
            'median_days' => $days === [] ? null : $this->median($days),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{technician_id: int, name: string, active_wip: int, assigned: int, completed: int, median_minutes: float|null, sample: int}>
     */
    private function technicianKpis(?int $branchId, ?int $technicianId, string $from, string $to, array $filters): array
    {
        return $this->repository->technicianAssignmentStats($branchId, $technicianId, $from, $to, $filters)
            ->map(fn (array $row): array => [
                'technician_id' => $row['technician_id'],
                'name' => $row['name'],
                'active_wip' => $row['active_wip'],
                'assigned' => $row['assigned'],
                'completed' => $row['completed'],
                'median_minutes' => $row['completion_minutes'] === [] ? null : $this->median($row['completion_minutes']),
                'sample' => count($row['completion_minutes']),
            ])
            ->sortByDesc('completed')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{total: int, with_due_date: int, without_due_date: int, delivered_in_period: int, sla_eligible: int, stuck: int, due_coverage_pct: float|null}
     */
    private function dataQuality(?int $branchId, ?int $technicianId, string $from, string $to, array $filters, int $deliveredInPeriod, int $slaEligible): array
    {
        $counts = $this->repository->dataQualityCounts($branchId, $technicianId, $from, $to, $filters);

        return [
            'total' => $counts['total'],
            'with_due_date' => $counts['with_due_date'],
            'without_due_date' => $counts['without_due_date'],
            'delivered_in_period' => $deliveredInPeriod,
            'sla_eligible' => $slaEligible,
            'stuck' => $counts['stuck'],
            'due_coverage_pct' => $counts['total'] > 0 ? round($counts['with_due_date'] / $counts['total'] * 100, 1) : null,
        ];
    }

    // ---- Presentation groupings --------------------------------------------

    /**
     * @param  array<string, int>  $statusCounts
     * @return list<array{key: string, label: string, count: int}>
     */
    private function wipByStage(array $statusCounts): array
    {
        $groups = [
            ['pickup', 'Pickup & Transit', [LabWorkflowState::WAITING_PICKUP, LabWorkflowState::PICKUP_ACCEPTED, LabWorkflowState::PICKED_UP, LabWorkflowState::IN_TRANSIT_TO_LAB]],
            ['received', 'Diterima & Analisa', [LabWorkflowState::RECEIVED_AT_LAB, LabWorkflowState::MODEL_REGISTERED, LabWorkflowState::MODEL_ANALYSIS_PENDING, LabWorkflowState::INTERNAL_APPROVED, LabWorkflowState::EXTERNAL_LAB_REQUIRED]],
            ['production', 'Produksi Internal', array_merge([LabWorkflowState::TECHNICIAN_ASSIGNMENT_PENDING, LabWorkflowState::TECHNICIAN_ASSIGNED], array_keys(LabWorkflowState::V2_PRODUCTION_STEPS), array_values(LabWorkflowState::V2_PRODUCTION_STEPS))],
            ['qc', 'QC & Rework', [LabWorkflowState::QC_PENDING, LabWorkflowState::QC_PASSED, LabWorkflowState::QC_FAILED, LabWorkflowState::REWORK_REQUIRED]],
            ['external', 'Lab Eksternal', [LabWorkflowState::EXTERNAL_LAB_PREPARATION, LabWorkflowState::EXTERNAL_LAB_SENT, LabWorkflowState::EXTERNAL_LAB_IN_PROGRESS, LabWorkflowState::EXTERNAL_LAB_RETURNED, LabWorkflowState::EXTERNAL_LAB_RESULT_REVIEW]],
            ['delivery', 'Pengiriman', [LabWorkflowState::MODEL_DONE, LabWorkflowState::DELIVERY_PENDING, LabWorkflowState::COURIER_NOTIFIED, LabWorkflowState::DELIVERY_ACCEPTED, LabWorkflowState::LAB_HANDOVER_PENDING, LabWorkflowState::PRE_DELIVERY_PHOTO_CAPTURED, LabWorkflowState::COURIER_SIGNATURE_CAPTURED, LabWorkflowState::READY_FOR_TRANSIT_TO_BRANCH, LabWorkflowState::IN_TRANSIT_TO_BRANCH, LabWorkflowState::ARRIVED_AT_BRANCH, LabWorkflowState::RECIPIENT_SIGNATURE_CAPTURED, LabWorkflowState::DELIVERY_LOCATION_PHOTO_CAPTURED]],
        ];

        $rows = [];
        foreach ($groups as [$key, $label, $states]) {
            $count = 0;
            foreach ($states as $s) {
                $count += (int) ($statusCounts[$s] ?? 0);
            }
            $rows[] = ['key' => $key, 'label' => $label, 'count' => $count];
        }

        return $rows;
    }

    /**
     * Daily throughput trend, grouped in PHP for PG/SQLite portability.
     *
     * @param  Collection<int, object>  $delivered
     * @return list<array{date: string, count: int}>
     */
    private function trend($delivered, string $from, string $to): array
    {
        $byDay = [];
        foreach ($delivered as $row) {
            $day = Carbon::parse($row->delivered_at)->toDateString();
            $byDay[$day] = ($byDay[$day] ?? 0) + 1;
        }

        $rows = [];
        $cursor = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        // Bound the axis to a sane number of buckets.
        $guard = 0;
        while ($cursor->lessThanOrEqualTo($end) && $guard < 400) {
            $key = $cursor->toDateString();
            $rows[] = ['date' => $key, 'count' => (int) ($byDay[$key] ?? 0)];
            $cursor->addDay();
            $guard++;
        }

        return $rows;
    }

    // ---- Scope / period helpers --------------------------------------------

    /**
     * @param  array{tier: string, branch_id: int|null, technician_id: int|null, sees_all: bool, technician_name: string|null}  $scope
     * @return array<string, mixed>
     */
    private function scopeMeta(array $scope): array
    {
        return [
            'tier' => $scope['tier'],
            'sees_all' => $scope['sees_all'],
            'branch_id' => $scope['branch_id'],
            'technician_id' => $scope['technician_id'],
            'technician_name' => $scope['technician_name'],
            'branch_options' => $scope['tier'] === 'full' ? $this->branchOptions() : [],
            'technician_options' => $scope['tier'] === 'full' ? $this->technicianOptions() : [],
            'lab_service_options' => $this->labServiceOptions(),
        ];
    }

    private function validateBranch(?int $requestedBranchId): ?int
    {
        if ($requestedBranchId === null || $requestedBranchId <= 0) {
            return null;
        }

        return in_array($requestedBranchId, $this->branches->rmeEnabledIds(), true) ? $requestedBranchId : null;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function branchOptions(): array
    {
        return Branch::query()
            ->whereIn('id', $this->branches->rmeEnabledIds())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Branch $b): array => ['id' => (int) $b->id, 'name' => (string) $b->name])
            ->all();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function technicianOptions(): array
    {
        return Technician::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name'])
            ->map(fn (Technician $t): array => ['id' => (int) $t->id, 'name' => (string) $t->name])
            ->all();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function labServiceOptions(): array
    {
        return LabService::query()
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name'])
            ->map(fn (LabService $s): array => ['id' => (int) $s->id, 'name' => (string) $s->name])
            ->all();
    }

    /**
     * Resolve the selected period + the equal-length previous window.
     *
     * @return array{key: string, label: string, from: string, to: string, prev_from: string, prev_to: string, days: int}
     */
    public function resolvePeriod(?string $key, ?string $customFrom, ?string $customTo): array
    {
        $key = in_array($key, config('lab_operational_analytics.periods', []), true)
            ? $key
            : config('lab_operational_analytics.default_period', 'month');

        $today = today();
        [$from, $to, $label] = match ($key) {
            'today' => [$today->copy(), $today->copy(), 'Hari Ini'],
            '7d' => [$today->copy()->subDays(6), $today->copy(), '7 Hari'],
            '30d' => [$today->copy()->subDays(29), $today->copy(), '30 Hari'],
            'custom' => $this->resolveCustom($customFrom, $customTo, $today),
            default => [$today->copy()->startOfMonth(), $today->copy(), 'Bulan Ini'],
        };

        $days = $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1);

        return [
            'key' => $key,
            'label' => $label,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'prev_from' => $prevFrom->toDateString(),
            'prev_to' => $prevTo->toDateString(),
            'days' => $days,
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function resolveCustom(?string $customFrom, ?string $customTo, Carbon $today): array
    {
        $max = (int) config('lab_operational_analytics.max_custom_range_days', 366);
        $from = $customFrom ? Carbon::parse($customFrom)->startOfDay() : $today->copy()->subDays(29);
        $to = $customTo ? Carbon::parse($customTo)->startOfDay() : $today->copy();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }
        // Clamp the range length to the safe maximum.
        if ($from->diffInDays($to) + 1 > $max) {
            $from = $to->copy()->subDays($max - 1);
        }

        return [$from, $to, 'Rentang Kustom'];
    }

    // ---- math ---------------------------------------------------------------

    /**
     * @param  array<string, int>  $statusCounts
     */
    private function sumNonTerminal(array $statusCounts): int
    {
        $sum = 0;
        foreach ($statusCounts as $status => $count) {
            if (! LabWorkflowState::isTerminal((string) $status)) {
                $sum += (int) $count;
            }
        }

        return $sum;
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return round($values[$middle], 2);
        }

        return round(($values[$middle - 1] + $values[$middle]) / 2, 2);
    }
}
