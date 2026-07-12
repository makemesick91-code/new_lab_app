<?php

namespace App\Modules\LabCapacity\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\LabCapacity\Interfaces\LabTechnicianCapacityRepositoryInterface;
use App\Modules\LabOrder\Interfaces\LabOperationalAnalyticsRepositoryInterface;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use App\Modules\Technician\Models\Technician;
use App\Modules\Technician\Services\TechnicianAssignmentEligibility;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * LAB-PROD-3 — Technician Capacity Planning engine (read-only decision-support).
 *
 * Deterministic, branch-safe, PII-free, idempotent for the same inputs. REUSES
 * LAB-PROD-2 (LabOperationalAnalyticsRepositoryInterface::technicianAssignmentStats
 * for historical completion confidence) — it does NOT re-implement any KPI
 * calculator. NEVER auto-assigns, NEVER ranks employees, NEVER fabricates
 * capacity: missing profiles => UNCONFIGURED / UNPLANNABLE, never a fake zero.
 */
class LabTechnicianCapacityPlanningService
{
    public function __construct(
        private LabTechnicianCapacityRepositoryInterface $repository,
        private TechnicianAssignmentEligibility $eligibility,
        private LabOperationalAnalyticsRepositoryInterface $analyticsRepository,
        private BranchService $branches,
    ) {}

    public function featureEnabled(): bool
    {
        return (bool) config('lab_technician_capacity.enabled', true);
    }

    /** Resolve the server-side authorization scope (IDOR-safe). */
    public function resolveScope(User $user, ?int $requestedBranchId, ?int $requestedTechnicianId): array
    {
        $perms = config('lab_technician_capacity.permissions');
        $canManage = (bool) $user->can($perms['manage']);
        $canView = (bool) $user->can($perms['view']);

        if ($canView || $canManage) {
            return [
                'tier' => 'full',
                'sees_all' => true,
                'branch_id' => $this->validateBranch($requestedBranchId),
                'technician_id' => ($requestedTechnicianId && $requestedTechnicianId > 0) ? $requestedTechnicianId : null,
                'technician_name' => null,
                'can_manage' => $canManage,
                'can_export' => (bool) ($user->can($perms['export']) || $canManage),
            ];
        }

        if ($user->can($perms['view_own'])) {
            // Viewing OWN capacity only needs a linked active technician record
            // (mirrors the LAB-PROD-2 own tier); the Technician role governs
            // assignability, not the right to view one's own load.
            $technician = Technician::query()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->first();
            if ($technician) {
                return [
                    'tier' => 'own',
                    'sees_all' => false,
                    'branch_id' => null, // technicians are lab-wide
                    'technician_id' => (int) $technician->id,
                    'technician_name' => $technician->name,
                    'can_manage' => false,
                    'can_export' => (bool) $user->can($perms['export']),
                ];
            }
        }

        return [
            'tier' => 'denied', 'sees_all' => false, 'branch_id' => null,
            'technician_id' => null, 'technician_name' => null,
            'can_manage' => false, 'can_export' => false,
        ];
    }

    private function validateBranch(?int $branchId): ?int
    {
        if (! $branchId) {
            return null;
        }

        return in_array($branchId, $this->branches->rmeEnabledIds(), true) ? $branchId : null;
    }

    /** Build the full capacity plan payload. */
    public function plan(array $scope, array $filters): array
    {
        $cfg = config('lab_technician_capacity');
        $planUnit = $this->resolvePlanUnit($filters, $cfg);
        $period = $this->resolvePeriod($filters, $cfg);
        $from = $period['from'];
        $to = $period['to'];

        // Technician set: own tier = the caller's own record (view right, any
        // role); full tier = eligible (assignable) technicians, lab-wide.
        if ($scope['tier'] === 'own' && $scope['technician_id']) {
            $technicians = Technician::query()
                ->where('id', $scope['technician_id'])
                ->where('is_active', true)
                ->get(['id', 'name']);
        } else {
            $technicians = $this->eligibility->listForAssignment();
            if (! empty($filters['technician_id'])) {
                $technicians = $technicians->where('id', (int) $filters['technician_id'])->values();
            }
        }
        $technicianIds = $technicians->pluck('id')->all();

        // Configuration inputs.
        $capacityProfiles = $this->repository->capacityProfiles($technicianIds)->groupBy('technician_id');
        $overrides = $this->repository->availabilityOverrides($technicianIds, $from->toDateString(), $to->toDateString())
            ->groupBy(fn ($o) => $o->technician_id.'|'.Carbon::parse($o->override_date)->toDateString());
        $capabilities = $this->repository->capabilities($technicianIds);
        $workloadByService = $this->resolveWorkloadByService($from->toDateString(), $planUnit);

        // Demand: open V2 orders (branch-scoped), classified.
        $repoFilters = [
            'status' => $filters['status'] ?? null,
            'lab_service_id' => $filters['lab_service_id'] ?? null,
            'technician_id' => ($scope['tier'] === 'own') ? $scope['technician_id'] : ($filters['technician_id'] ?? null),
        ];
        $orders = $this->repository->openOrders($scope['branch_id'], $repoFilters);
        $sourcing = $filters['sourcing'] ?? null;

        // Per-technician available capacity over the horizon.
        $available = [];
        $configured = [];
        $unitMismatch = [];
        foreach ($technicians as $t) {
            $result = $this->availableCapacity($capacityProfiles->get($t->id) ?? collect(), $overrides, $from, $to, $planUnit, $cfg);
            $available[$t->id] = $result['available'];
            $configured[$t->id] = $result['configured'];
            $unitMismatch[$t->id] = $result['unit_mismatch'];
        }

        // Classify each order → assigned/unassigned/external/unplannable + remaining.
        $assignedLoad = [];       // technician_id => remaining sum
        $assignedCount = [];      // technician_id => order count
        $unassignedOrders = [];   // list of plannable internal unassigned orders
        $plannableInternal = collect(); // for the due-risk pool simulation
        $totalAssigned = 0.0;
        $totalUnassigned = 0.0;
        $externalDemand = 0.0;
        $externalCount = 0;
        $unplannableCount = 0;
        $assumedFullCount = 0;
        $serviceAgg = []; // service_id => [open, assigned, unassigned, missing_profile]

        foreach ($orders as $order) {
            $classified = $this->classifyOrder($order, $workloadByService, $cfg);

            if ($sourcing === 'internal' && $classified['sourcing'] === 'external') {
                continue;
            }
            if ($sourcing === 'external' && $classified['sourcing'] === 'internal') {
                continue;
            }

            foreach ($classified['service_ids'] as $sid) {
                $serviceAgg[$sid] ??= ['open' => 0, 'assigned' => 0, 'unassigned' => 0, 'missing_profile' => 0];
                $serviceAgg[$sid]['open']++;
                if ($classified['missing_profile']) {
                    $serviceAgg[$sid]['missing_profile']++;
                }
            }

            if ($classified['sourcing'] === 'external') {
                $externalCount++;
                $externalDemand += $classified['external_workload'];

                continue;
            }

            if ($classified['assumed_full']) {
                $assumedFullCount++;
            }

            if ($classified['unplannable']) {
                $unplannableCount++;

                continue;
            }

            $technicianId = $classified['technician_id'];
            if ($technicianId) {
                $assignedLoad[$technicianId] = ($assignedLoad[$technicianId] ?? 0) + $classified['remaining'];
                $assignedCount[$technicianId] = ($assignedCount[$technicianId] ?? 0) + 1;
                $totalAssigned += $classified['remaining'];
                foreach ($classified['service_ids'] as $sid) {
                    $serviceAgg[$sid]['assigned']++;
                }
            } else {
                $totalUnassigned += $classified['remaining'];
                $unassignedOrders[] = $classified;
                foreach ($classified['service_ids'] as $sid) {
                    $serviceAgg[$sid]['unassigned']++;
                }
            }
            $plannableInternal->push($classified);
        }

        // Historical completion confidence (LAB-PROD-2 reuse; degrade gracefully).
        $historical = $this->historicalMedians($scope);

        // Due-risk pool simulation (deterministic).
        $dailyCapacity = $this->dailyCapacitySeries($technicians, $capacityProfiles, $overrides, $from, $to, $planUnit, $cfg);
        $simulation = $this->simulateDueRisk($plannableInternal, $dailyCapacity, $cfg);

        // Technician rows.
        $technicianRows = $this->buildTechnicianRows(
            $technicians, $available, $configured, $unitMismatch,
            $assignedLoad, $assignedCount, $simulation, $historical, $cfg
        );

        // Recommendations for unassigned plannable orders.
        $capabilityMap = $this->buildCapabilityMap($capabilities, $from->toDateString());
        $unassignedRows = $this->buildUnassignedRows(
            $unassignedOrders, $technicianRows, $capabilityMap, $workloadByService, $simulation, $cfg
        );

        // Service demand rows.
        $serviceRows = $this->buildServiceRows($serviceAgg, $capabilityMap, $technicianRows, $workloadByService);

        // Totals + bands.
        $totalAvailable = 0.0;
        $configuredTechnicians = 0;
        $overloadCount = 0;
        foreach ($technicianRows as $row) {
            if ($row['available'] !== null) {
                $totalAvailable += $row['available'];
                $configuredTechnicians++;
            }
            if ($row['band'] === $cfg['bands']['over_capacity']) {
                $overloadCount++;
            }
        }

        $summary = [
            'active_technicians' => $technicians->count(),
            'configured_technicians' => $configuredTechnicians,
            'available_capacity' => round($totalAvailable, 2),
            'assigned_load' => round($totalAssigned, 2),
            'unassigned_demand' => round($totalUnassigned, 2),
            'external_demand' => round($externalDemand, 2),
            'external_orders' => $externalCount,
            'capacity_gap' => round($totalAvailable - $totalAssigned, 2),
            'utilization' => $this->utilization($totalAssigned, $totalAvailable),
            'band' => $this->band($this->utilization($totalAssigned, $totalAvailable), $totalAvailable, $cfg),
            'overload_count' => $overloadCount,
            'projected_late_count' => $simulation['projected_late_count'],
            'overdue_count' => $simulation['overdue_count'],
            'at_risk_count' => $simulation['at_risk_count'],
            'unplannable_count' => $unplannableCount,
            'backlog_coverage_days' => $this->backlogDays($totalAssigned + $totalUnassigned, $dailyCapacity),
            'assigned_backlog_days' => $this->backlogDays($totalAssigned, $dailyCapacity),
            'unassigned_backlog_days' => $this->backlogDays($totalUnassigned, $dailyCapacity),
        ];

        return [
            'scope' => $scope,
            'planning_unit' => $planUnit,
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => $period['days'],
                'label' => $from->toDateString().' → '.$to->toDateString(),
            ],
            'summary' => $summary,
            'technicians' => $technicianRows,
            'services' => $serviceRows,
            'daily' => $this->buildDailyRows($dailyCapacity, $orders, $cfg),
            'unassigned_orders' => $unassignedRows,
            'data_quality' => [
                'total_open_orders' => $orders->count(),
                'unplannable_orders' => $unplannableCount,
                'assumed_full_orders' => $assumedFullCount,
                'technicians_configured' => $configuredTechnicians,
                'technicians_unconfigured' => $technicians->count() - $configuredTechnicians,
                'unit_mismatch_technicians' => count(array_filter($unitMismatch)),
                'services_with_workload_profile' => $workloadByService->filter()->count(),
                'services_total' => $this->repository->activeLabServices()->count(),
                'historical_available' => $historical->isNotEmpty(),
            ],
            'generated_at' => now(),
        ];
    }

    /** Filter option lists for the planning UI (scope-aware, PII-free). */
    public function filterOptions(array $scope): array
    {
        $services = $this->repository->activeLabServices()
            ->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name])->values()->all();

        $technicians = $this->eligibility->listForAssignment()
            ->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name]);
        if ($scope['tier'] === 'own' && $scope['technician_id']) {
            $technicians = $technicians->where('id', $scope['technician_id']);
        }

        $branches = [];
        if ($scope['tier'] === 'full') {
            $branches = $this->branches->listRmeEnabled()
                ->map(fn ($b) => ['id' => (int) $b->id, 'name' => $b->name])->values()->all();
        }

        return [
            'branches' => $branches,
            'technicians' => $technicians->values()->all(),
            'services' => $services,
            'horizons' => config('lab_technician_capacity.horizons'),
            'statuses' => LabWorkflowState::all(),
            'sourcing' => ['internal', 'external'],
        ];
    }

    /** Setup/empty state descriptor for the UI when nothing is configured. */
    public function isConfigured(): bool
    {
        $hasCapacity = $this->repository->capacityProfiles(
            $this->eligibility->listForAssignment()->pluck('id')->all()
        )->isNotEmpty();
        $hasWorkload = $this->repository->workloadProfiles()->isNotEmpty();

        return $hasCapacity && $hasWorkload;
    }

    // ---- helpers -----------------------------------------------------------

    private function resolvePlanUnit(array $filters, array $cfg): string
    {
        $unit = $filters['planning_unit'] ?? $cfg['planning_unit'];

        return in_array($unit, $cfg['allowed_planning_units'], true) ? $unit : $cfg['planning_unit'];
    }

    private function resolvePeriod(array $filters, array $cfg): array
    {
        $from = today();
        $horizon = $filters['horizon'] ?? null;

        if ($horizon === 'custom' && ! empty($filters['from']) && ! empty($filters['to'])) {
            $from = Carbon::parse($filters['from'])->startOfDay();
            $to = Carbon::parse($filters['to'])->startOfDay();
        } else {
            $days = in_array((int) $horizon, $cfg['horizons'], true) ? (int) $horizon : $cfg['default_horizon'];
            $to = $from->copy()->addDays($days - 1);
        }

        if ($to->lt($from)) {
            $to = $from->copy();
        }
        $maxTo = $from->copy()->addDays($cfg['max_horizon_days'] - 1);
        if ($to->gt($maxTo)) {
            $to = $maxTo;
        }

        return ['from' => $from, 'to' => $to, 'days' => $from->diffInDays($to) + 1];
    }

    private function resolveWorkloadByService(string $date, string $planUnit): Collection
    {
        $profiles = $this->repository->workloadProfiles()
            ->filter(fn ($p) => $p->planning_unit === $planUnit
                && Carbon::parse($p->effective_from)->lte($date)
                && ($p->effective_until === null || Carbon::parse($p->effective_until)->gte($date)))
            ->groupBy('lab_service_id')
            ->map(fn ($group) => $group->sortByDesc('effective_from')->first());

        return $this->repository->activeLabServices()
            ->mapWithKeys(fn ($s) => [$s->id => $profiles->get($s->id)?->planned_workload !== null
                ? (float) $profiles->get($s->id)->planned_workload
                : null]);
    }

    private function availableCapacity(Collection $profiles, Collection $overrides, Carbon $from, Carbon $to, string $planUnit, array $cfg): array
    {
        $total = 0.0;
        $matched = false;
        $hasAnyProfile = $profiles->isNotEmpty();

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $profile = $profiles->first(fn ($p) => $p->planning_unit === $planUnit
                && Carbon::parse($p->effective_from)->lte($day)
                && ($p->effective_until === null || Carbon::parse($p->effective_until)->gte($day)));
            if (! $profile) {
                continue;
            }
            $matched = true;

            $workingDays = ! empty($profile->working_days) ? $profile->working_days : $cfg['default_working_days'];
            if (! in_array($day->isoWeekday(), array_map('intval', $workingDays), true)) {
                continue;
            }

            $base = (float) $profile->daily_capacity;
            $override = ($overrides->get($profile->technician_id.'|'.$day->toDateString()) ?? collect())->first();
            if ($override) {
                if ($override->capacity_override !== null) {
                    $base = (float) $override->capacity_override;
                } elseif ($override->capacity_reduction !== null) {
                    $base -= (float) $override->capacity_reduction;
                }
            }

            $total += max(0.0, $base);
        }

        return [
            'available' => $matched ? round($total, 2) : null,
            'configured' => $matched,
            'unit_mismatch' => (! $matched && $hasAnyProfile),
        ];
    }

    private function dailyCapacitySeries(Collection $technicians, Collection $capacityProfiles, Collection $overrides, Carbon $from, Carbon $to, string $planUnit, array $cfg): array
    {
        $series = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $key = $day->toDateString();
            $series[$key] = 0.0;
            foreach ($technicians as $t) {
                $profiles = $capacityProfiles->get($t->id) ?? collect();
                $profile = $profiles->first(fn ($p) => $p->planning_unit === $planUnit
                    && Carbon::parse($p->effective_from)->lte($day)
                    && ($p->effective_until === null || Carbon::parse($p->effective_until)->gte($day)));
                if (! $profile) {
                    continue;
                }
                $workingDays = ! empty($profile->working_days) ? $profile->working_days : $cfg['default_working_days'];
                if (! in_array($day->isoWeekday(), array_map('intval', $workingDays), true)) {
                    continue;
                }
                $base = (float) $profile->daily_capacity;
                $override = ($overrides->get($t->id.'|'.$key) ?? collect())->first();
                if ($override) {
                    if ($override->capacity_override !== null) {
                        $base = (float) $override->capacity_override;
                    } elseif ($override->capacity_reduction !== null) {
                        $base -= (float) $override->capacity_reduction;
                    }
                }
                $series[$key] += max(0.0, $base);
            }
            $series[$key] = round($series[$key], 2);
        }

        return $series;
    }

    private function classifyOrder($order, Collection $workloadByService, array $cfg): array
    {
        $status = (string) $order->status;
        $sourcing = $this->isExternalStatus($status) ? 'external' : 'internal';
        [$fraction, $assumedFull] = $this->bandFraction($status, $cfg);

        $serviceIds = [];
        $remaining = 0.0;
        $externalWorkload = 0.0;
        $missingProfile = false;

        $items = $order->items ?? collect();
        if ($items->isEmpty()) {
            $missingProfile = true;
        }
        foreach ($items as $item) {
            $sid = (int) $item->lab_service_id;
            $serviceIds[$sid] = $sid;
            $qty = (float) ($item->quantity ?: 1);
            $workload = $workloadByService->get($sid);
            if ($workload === null) {
                $missingProfile = true;

                continue;
            }
            $remaining += $workload * $qty * $fraction;
            $externalWorkload += $workload * $qty; // full workload as external volume indicator
        }

        $technicianId = $order->activeAssignment?->technician_id
            ? (int) $order->activeAssignment->technician_id
            : null;

        return [
            'order_id' => (int) $order->id,
            'order_number' => (string) $order->order_number,
            'branch_id' => $order->branch_id,
            'branch_name' => $order->branch?->name,
            'status' => $status,
            'priority' => (string) $order->priority,
            'due_date' => $order->due_date ? Carbon::parse($order->due_date)->toDateString() : null,
            'received_at' => $order->received_at ? Carbon::parse($order->received_at)->toDateString() : null,
            'sourcing' => $sourcing,
            'technician_id' => $technicianId,
            'service_ids' => array_values($serviceIds),
            'remaining' => round($remaining, 2),
            'external_workload' => round($externalWorkload, 2),
            'unplannable' => $missingProfile,
            'unplannable_reason' => $missingProfile ? $cfg['unplannable_reasons']['missing_workload_profile'] : null,
            'missing_profile' => $missingProfile,
            'assumed_full' => $assumedFull && $sourcing === 'internal' && ! $missingProfile,
        ];
    }

    private function isExternalStatus(string $status): bool
    {
        return in_array($status, [
            LabWorkflowState::EXTERNAL_LAB_REQUIRED,
            LabWorkflowState::EXTERNAL_LAB_PREPARATION,
            LabWorkflowState::EXTERNAL_LAB_SENT,
            LabWorkflowState::EXTERNAL_LAB_IN_PROGRESS,
            LabWorkflowState::EXTERNAL_LAB_RETURNED,
            LabWorkflowState::EXTERNAL_LAB_RESULT_REVIEW,
        ], true);
    }

    /** @return array{0: float, 1: bool} [fraction, assumedFull] */
    private function bandFraction(string $status, array $cfg): array
    {
        $f = $cfg['remaining_workload_band_fraction'];
        $map = [
            LabWorkflowState::STEP_1_BLOCKOUT_DUPLICATE => $f['step_1'],
            LabWorkflowState::STEP_1_COMPLETED => $f['step_1'],
            LabWorkflowState::STEP_2_TEETH_SETUP => $f['step_2'],
            LabWorkflowState::STEP_2_COMPLETED => $f['step_2'],
            LabWorkflowState::STEP_3_PROCESSING => $f['step_3'],
            LabWorkflowState::STEP_3_COMPLETED => $f['step_3'],
            LabWorkflowState::STEP_4_FITTING_POLISH => $f['step_4'],
            LabWorkflowState::STEP_4_COMPLETED => $f['step_4'],
            LabWorkflowState::QC_PENDING => $f['qc'],
            LabWorkflowState::QC_FAILED => $f['rework'],
            LabWorkflowState::REWORK_REQUIRED => $f['rework'],
            LabWorkflowState::QC_PASSED => $f['near_done'],
            LabWorkflowState::MODEL_DONE => $f['post_production'],
        ];

        // MODEL_DONE + all delivery states => post_production (technician done).
        $postProduction = [
            LabWorkflowState::MODEL_DONE, LabWorkflowState::DELIVERY_PENDING, LabWorkflowState::COURIER_NOTIFIED,
            LabWorkflowState::DELIVERY_ACCEPTED, LabWorkflowState::LAB_HANDOVER_PENDING,
            LabWorkflowState::PRE_DELIVERY_PHOTO_CAPTURED, LabWorkflowState::COURIER_SIGNATURE_CAPTURED,
            LabWorkflowState::READY_FOR_TRANSIT_TO_BRANCH, LabWorkflowState::IN_TRANSIT_TO_BRANCH,
            LabWorkflowState::ARRIVED_AT_BRANCH, LabWorkflowState::RECIPIENT_SIGNATURE_CAPTURED,
            LabWorkflowState::DELIVERY_LOCATION_PHOTO_CAPTURED,
        ];
        if (in_array($status, $postProduction, true)) {
            return [$f['post_production'], false];
        }
        if (array_key_exists($status, $map)) {
            return [$map[$status], false];
        }

        // Non-terminal, unlisted (pre-production band) => full, flagged assumed.
        return [$cfg['default_band_fraction'], true];
    }

    private function historicalMedians(array $scope): Collection
    {
        try {
            $to = today()->toDateString();
            $fromDate = today()->copy()->subDays(30)->toDateString();
            $technicianId = $scope['tier'] === 'own' ? $scope['technician_id'] : null;

            return $this->analyticsRepository
                ->technicianAssignmentStats($scope['branch_id'], $technicianId, $fromDate, $to, [])
                ->keyBy('technician_id');
        } catch (\Throwable) {
            return collect();
        }
    }

    private function utilization(float $assigned, ?float $available): ?float
    {
        if ($available === null || $available <= 0.0) {
            return null;
        }

        return round(($assigned / $available) * 100, 1);
    }

    private function band(?float $utilization, ?float $available, array $cfg): string
    {
        $bands = $cfg['bands'];
        if ($available === null) {
            return $bands['unconfigured'];
        }
        if ($available <= 0.0) {
            return $bands['unavailable'];
        }
        if ($utilization === null) {
            return $bands['unconfigured'];
        }
        if ($utilization > $cfg['utilization']['over_at']) {
            return $bands['over_capacity'];
        }
        if ($utilization >= $cfg['utilization']['watch_at']) {
            return $bands['watch'];
        }

        return $bands['normal'];
    }

    private function backlogDays(float $load, array $dailyCapacity): ?float
    {
        $days = count($dailyCapacity);
        $totalCapacity = array_sum($dailyCapacity);
        if ($days === 0 || $totalCapacity <= 0.0) {
            return null;
        }
        $perDay = $totalCapacity / $days;
        if ($perDay <= 0.0) {
            return null;
        }

        return round($load / $perDay, 1);
    }

    private function buildTechnicianRows(Collection $technicians, array $available, array $configured, array $unitMismatch, array $assignedLoad, array $assignedCount, array $simulation, Collection $historical, array $cfg): array
    {
        $rows = [];
        foreach ($technicians as $t) {
            $avail = $available[$t->id] ?? null;
            $assigned = round($assignedLoad[$t->id] ?? 0.0, 2);
            $util = $this->utilization($assigned, $avail);
            $hist = $historical->get($t->id);
            $completion = null;
            if (is_array($hist)) {
                $completion = $hist['completion_minutes'] ?? null;
            } elseif (is_object($hist)) {
                $completion = $hist->completion_minutes ?? null;
            }

            $rows[$t->id] = [
                'technician_id' => (int) $t->id,
                'name' => $t->name,
                'available' => $avail,
                'assigned_load' => $assigned,
                'capacity_gap' => $avail === null ? null : round($avail - $assigned, 2),
                'utilization' => $util,
                'active_orders' => $assignedCount[$t->id] ?? 0,
                'due_risk_count' => $simulation['tech_due_risk'][$t->id] ?? 0,
                'band' => $this->band($util, $avail, $cfg),
                'coverage' => $configured[$t->id] ? 'configured' : (($unitMismatch[$t->id] ?? false) ? 'unit_mismatch' : 'unconfigured'),
                'historical_median_minutes' => $this->median(is_array($completion) ? $completion : []),
                'historical_sample' => is_array($completion) ? count($completion) : 0,
            ];
        }

        return $rows;
    }

    private function median(array $values): ?float
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null));
        $n = count($values);
        if ($n === 0) {
            return null;
        }
        sort($values);
        $mid = intdiv($n, 2);

        return round($n % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2, 1);
    }

    private function buildCapabilityMap(Collection $capabilities, string $date): array
    {
        $map = [];
        foreach ($capabilities as $c) {
            if (! Carbon::parse($c->effective_from)->lte($date)) {
                continue;
            }
            if ($c->effective_until !== null && ! Carbon::parse($c->effective_until)->gte($date)) {
                continue;
            }
            $map[$c->technician_id][$c->lab_service_id] = true;
        }

        return $map;
    }

    private function simulateDueRisk(Collection $orders, array $dailyCapacity, array $cfg): array
    {
        $states = $cfg['due_risk_states'];
        $today = today();

        // Working-capacity slots in chronological order.
        $slots = [];
        foreach ($dailyCapacity as $date => $cap) {
            if ($cap > 0) {
                $slots[] = ['date' => $date, 'remaining' => (float) $cap];
            }
        }

        $priorityRank = ['URGENT' => 0, 'HIGH' => 1, 'NORMAL' => 2, 'LOW' => 3];
        $sorted = $orders->sort(function ($a, $b) use ($today, $priorityRank) {
            $ao = ($a['due_date'] && Carbon::parse($a['due_date'])->lt($today)) ? 0 : 1;
            $bo = ($b['due_date'] && Carbon::parse($b['due_date'])->lt($today)) ? 0 : 1;
            if ($ao !== $bo) {
                return $ao <=> $bo;
            }
            $ad = $a['due_date'] ? Carbon::parse($a['due_date'])->timestamp : PHP_INT_MAX;
            $bd = $b['due_date'] ? Carbon::parse($b['due_date'])->timestamp : PHP_INT_MAX;
            if ($ad !== $bd) {
                return $ad <=> $bd;
            }
            $ap = $priorityRank[$a['priority']] ?? 2;
            $bp = $priorityRank[$b['priority']] ?? 2;
            if ($ap !== $bp) {
                return $ap <=> $bp;
            }
            $ar = $a['received_at'] ? Carbon::parse($a['received_at'])->timestamp : PHP_INT_MAX;
            $br = $b['received_at'] ? Carbon::parse($b['received_at'])->timestamp : PHP_INT_MAX;
            if ($ar !== $br) {
                return $ar <=> $br;
            }

            return $a['order_id'] <=> $b['order_id'];
        })->values();

        $slotIdx = 0;
        $risk = [];
        $techDueRisk = [];
        $late = 0;
        $overdue = 0;
        $atRisk = 0;

        foreach ($sorted as $order) {
            $need = $order['remaining'];
            $projected = null;
            while ($need > 0.0001 && $slotIdx < count($slots)) {
                $take = min($need, $slots[$slotIdx]['remaining']);
                $need -= $take;
                $slots[$slotIdx]['remaining'] -= $take;
                $projected = $slots[$slotIdx]['date'];
                if ($slots[$slotIdx]['remaining'] <= 0.0001) {
                    $slotIdx++;
                }
            }
            if ($need > 0.0001) {
                $projected = null; // could not finish within horizon
            }

            $state = $this->dueRiskState($order, $projected, $today, $states, $cfg);
            $risk[$order['order_id']] = ['state' => $state, 'projected' => $projected];
            if ($state === $states['overdue']) {
                $overdue++;
            } elseif ($state === $states['projected_late']) {
                $late++;
            } elseif ($state === $states['at_risk']) {
                $atRisk++;
            }
            if ($order['technician_id']) {
                if (in_array($state, [$states['overdue'], $states['projected_late'], $states['at_risk']], true)) {
                    $techDueRisk[$order['technician_id']] = ($techDueRisk[$order['technician_id']] ?? 0) + 1;
                }
            }
        }

        return [
            'risk' => $risk,
            'tech_due_risk' => $techDueRisk,
            'projected_late_count' => $late,
            'overdue_count' => $overdue,
            'at_risk_count' => $atRisk,
        ];
    }

    private function dueRiskState(array $order, ?string $projected, Carbon $today, array $states, array $cfg): string
    {
        if (! $order['due_date']) {
            return $projected === null ? $states['projected_late'] : $states['no_due_date'];
        }
        $due = Carbon::parse($order['due_date']);
        if ($due->lt($today)) {
            return $states['overdue'];
        }
        if ($projected === null) {
            return $states['projected_late'];
        }
        $projectedDate = Carbon::parse($projected);
        if ($projectedDate->gt($due)) {
            return $states['projected_late'];
        }
        if ($projectedDate->copy()->addDays((int) $cfg['at_risk_buffer_days'])->gte($due)) {
            return $states['at_risk'];
        }

        return $states['on_track'];
    }

    private function buildUnassignedRows(array $orders, array $technicianRows, array $capabilityMap, Collection $workloadByService, array $simulation, array $cfg): array
    {
        $limit = (int) $cfg['recommendation_candidate_limit'];
        $reasons = $cfg['recommendation_reason_codes'];
        $rows = [];

        foreach ($orders as $order) {
            $serviceIds = $order['service_ids'];
            $candidates = [];
            $reasonCodes = [];

            $anyCapableExists = false;
            foreach ($technicianRows as $techId => $row) {
                $capableAll = ! empty($serviceIds);
                foreach ($serviceIds as $sid) {
                    if (empty($capabilityMap[$techId][$sid])) {
                        $capableAll = false;
                        break;
                    }
                }
                if ($capableAll) {
                    $anyCapableExists = true;
                }
                if (! $capableAll) {
                    continue;
                }
                if ($row['available'] === null) {
                    continue; // unconfigured — no capacity basis
                }
                if ($row['available'] - $row['assigned_load'] <= 0) {
                    continue; // no remaining capacity
                }
                $projectedUtil = $this->utilization($row['assigned_load'] + $order['remaining'], $row['available']);
                $candidates[] = [
                    'technician_id' => $techId,
                    'name' => $row['name'],
                    'current_load' => $row['assigned_load'],
                    'available' => $row['available'],
                    'projected_utilization' => $projectedUtil,
                    'capacity_gap' => round($row['available'] - $row['assigned_load'], 2),
                    'capability_match' => true,
                    'historical_sample' => $row['historical_sample'],
                ];
            }

            usort($candidates, function ($a, $b) {
                $au = $a['projected_utilization'] ?? PHP_FLOAT_MAX;
                $bu = $b['projected_utilization'] ?? PHP_FLOAT_MAX;
                if ($au !== $bu) {
                    return $au <=> $bu;
                }
                if ($a['capacity_gap'] !== $b['capacity_gap']) {
                    return $b['capacity_gap'] <=> $a['capacity_gap'];
                }

                return $a['technician_id'] <=> $b['technician_id'];
            });

            if (empty($candidates)) {
                if (! $anyCapableExists) {
                    $reasonCodes[] = $reasons['service_unsupported'];
                } else {
                    $reasonCodes[] = $reasons['no_capacity'];
                }
            }

            $rows[] = [
                'order_id' => $order['order_id'],
                'order_number' => $order['order_number'],
                'branch_name' => $order['branch_name'],
                'status' => $order['status'],
                'due_date' => $order['due_date'],
                'remaining' => $order['remaining'],
                'due_risk' => $simulation['risk'][$order['order_id']]['state'] ?? null,
                'candidates' => array_slice($candidates, 0, $limit),
                'reason_codes' => $reasonCodes,
            ];
        }

        return $rows;
    }

    private function buildServiceRows(array $serviceAgg, array $capabilityMap, array $technicianRows, Collection $workloadByService): array
    {
        $rows = [];
        $services = $this->repository->activeLabServices()->keyBy('id');
        foreach ($serviceAgg as $sid => $agg) {
            $eligibleCapacity = 0.0;
            $hasEligible = false;
            foreach ($technicianRows as $techId => $row) {
                if (! empty($capabilityMap[$techId][$sid]) && $row['available'] !== null) {
                    $eligibleCapacity += max(0.0, $row['available'] - $row['assigned_load']);
                    $hasEligible = true;
                }
            }
            $rows[] = [
                'lab_service_id' => $sid,
                'name' => $services->get($sid)?->name ?? ('#'.$sid),
                'open' => $agg['open'],
                'assigned' => $agg['assigned'],
                'unassigned' => $agg['unassigned'],
                'missing_profile' => $agg['missing_profile'],
                'has_workload_profile' => $workloadByService->get($sid) !== null,
                'eligible_capacity' => $hasEligible ? round($eligibleCapacity, 2) : null,
            ];
        }
        usort($rows, fn ($a, $b) => $b['open'] <=> $a['open'] ?: $a['lab_service_id'] <=> $b['lab_service_id']);

        return $rows;
    }

    private function buildDailyRows(array $dailyCapacity, Collection $orders, array $cfg): array
    {
        // Due count per day (orders due that day) for the timeline.
        $dueByDate = [];
        foreach ($orders as $order) {
            if ($order->due_date) {
                $d = Carbon::parse($order->due_date)->toDateString();
                $dueByDate[$d] = ($dueByDate[$d] ?? 0) + 1;
            }
        }
        $rows = [];
        foreach ($dailyCapacity as $date => $cap) {
            $rows[] = [
                'date' => $date,
                'available_capacity' => $cap,
                'due_count' => $dueByDate[$date] ?? 0,
            ];
        }

        return $rows;
    }
}
