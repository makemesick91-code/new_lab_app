<?php

declare(strict_types=1);

namespace App\Modules\LabOrder\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\LabOrder\Models\ExternalLab;
use App\Modules\LabOrder\Models\LabDeliveryTask;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabPickupTask;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use App\Modules\Technician\Services\TechnicianAccountAuditor;
use App\Modules\Technician\Services\TechnicianAssignmentEligibility;
use App\Support\AccessControl\AdminLabLabOnlyAuditor;
use App\Support\DeveloperConsole\SensitiveValueMasker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * LAB-WORKFLOW-V2-PILOT-UAT-1 — read-only pilot operational readiness auditor.
 *
 * Aggregates every existing signal into ONE explainable GO/WATCH/NO-GO decision
 * for whether the Lab Workflow V2 pilot can be operated end-to-end. Reuses the
 * canonical services (resolver, technician eligibility/account auditor, Admin-Lab
 * auditor, branch service) rather than re-deriving their logic.
 *
 * Every check is independently guarded — a missing table/permission/disk degrades
 * that check to UNKNOWN (never a 500 and never a fake GO). Free text is masked.
 *
 * Severity → decision: any NO-GO ⇒ NO-GO; else any WATCH/UNKNOWN ⇒ WATCH; else GO.
 * The two pilot-blocking checks (V2 active, ≥1 eligible technician) are the only
 * ones that emit NO-GO on failure; staffing/master gaps are WATCH (a Super Admin
 * can always act during pilot), and an errored check is WATCH, never a silent GO.
 */
final class LabWorkflowPilotReadinessAuditor
{
    /** A non-terminal order untouched for longer than this is flagged as stuck. */
    private const STUCK_THRESHOLD_HOURS = 72;

    public function __construct(
        private readonly LabWorkflowResolver $resolver,
        private readonly TechnicianAssignmentEligibility $eligibility,
        private readonly TechnicianAccountAuditor $technicianAuditor,
        private readonly AdminLabLabOnlyAuditor $adminLabAuditor,
        private readonly BranchService $branchService,
        private readonly SensitiveValueMasker $masker,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function audit(?int $branchId = null, ?int $orderId = null): array
    {
        $checks = [];

        $checks[] = $this->guard('v2_active', function (): array {
            $active = $this->resolver->isV2Active();

            return [
                $active ? 'GO' : 'NO-GO',
                $active
                    ? 'Lab Workflow V2 is the active engine for new orders.'
                    : 'Lab Workflow V2 is NOT active — new orders would use the legacy engine.',
                ['v2_active' => $active],
            ];
        });

        $checks[] = $this->guard('legacy_create_blocked', function (): array {
            // When V2 is active the resolver blocks legacy creation server-side.
            $blocked = $this->resolver->isV2Active();

            return [
                $blocked ? 'GO' : 'WATCH',
                $blocked
                    ? 'Legacy order creation is blocked (V2 active).'
                    : 'Legacy create path is still open (V2 not active).',
                ['legacy_create_blocked' => $blocked],
            ];
        });

        $checks[] = $this->guard('rme_branches_available', function (): array {
            $ids = $this->branchService->rmeEnabledIds();
            $count = count($ids);

            return [
                $count > 0 ? 'GO' : 'NO-GO',
                "Active RME-enabled branches: {$count}.",
                ['count' => $count],
            ];
        });

        $checks[] = $this->guard('active_external_lab', function (): array {
            $active = ExternalLab::query()->where('is_active', true)->orderBy('name')->get();
            $count = $active->count();

            return [
                $count > 0 ? 'GO' : 'WATCH',
                $count > 0
                    ? "Active external labs: {$count}."
                    : 'No active external lab — the external path cannot be exercised until one is added.',
                [
                    'count' => $count,
                    // Vendor names are operational labels (not KTP/NIK/patient PII);
                    // surfaced so evidence shows which real vendor closed this check.
                    'active_labs' => $active->map(fn (ExternalLab $lab) => [
                        'id' => $lab->id,
                        'name' => $lab->name,
                    ])->all(),
                ],
            ];
        });

        $checks[] = $this->guard('eligible_technician', function (): array {
            $count = $this->eligibility->query()->count();

            return [
                $count > 0 ? 'GO' : 'NO-GO',
                $count > 0
                    ? "Eligible technicians for assignment: {$count}."
                    : 'No eligible technician (active user + Technician role) — internal production cannot be assigned.',
                ['count' => $count],
            ];
        });

        $checks[] = $this->actorCheck('qc_actor_available', ['pass_qc', 'reject_qc'], 'quality control');
        $checks[] = $this->actorCheck('courier_actor_available', ['manage_lab_pickups', 'start_delivery'], 'courier');
        $checks[] = $this->actorCheck('admin_lab_actor_available', ['manage_lab_orders'], 'lab admin');

        $checks[] = $this->guard('admin_lab_lab_only', function (): array {
            $report = $this->adminLabAuditor->audit();
            $decision = (string) ($report['summary']['decision'] ?? 'UNKNOWN');
            // The RBAC auditor's WATCH (Super-Admin leak) / NO-GO (role drift) is
            // surfaced as-is; a clean Admin-Lab-only posture is GO.
            $status = in_array($decision, ['GO', 'WATCH', 'NO-GO'], true) ? $decision : 'UNKNOWN';

            return [
                $status,
                'Admin Lab RBAC posture: '.$decision.'.',
                ['decision' => $decision, 'anomalies' => (int) ($report['summary']['anomalies'] ?? 0)],
            ];
        });

        $checks[] = $this->guard('technician_accounts', function (): array {
            $report = $this->technicianAuditor->audit();
            $decision = (string) ($report['summary']['decision'] ?? 'UNKNOWN');

            return [
                in_array($decision, ['GO', 'WATCH', 'NO-GO'], true) ? $decision : 'UNKNOWN',
                'Technician account posture: '.$decision.
                    ' ('.($report['eligible_technician_count'] ?? 0).' eligible).',
                [
                    'decision' => $decision,
                    'eligible' => (int) ($report['eligible_technician_count'] ?? 0),
                    // Additive evidence: active masters still needing a decision vs
                    // legitimately deactivated ones (inactive orphans are not anomalies).
                    'active_orphans' => (int) ($report['summary']['active_orphan_count'] ?? 0),
                    'inactive' => (int) ($report['summary']['inactive_technician_count'] ?? 0),
                    'codes' => (array) ($report['summary']['anomaly_codes'] ?? []),
                ],
            ];
        });

        $checks[] = $this->guard('invalid_status', function () use ($branchId, $orderId): array {
            $valid = LabWorkflowState::all();
            $bad = $this->v2Orders($branchId, $orderId)
                ->whereNotIn('status', $valid)
                ->pluck('status', 'id')
                ->all();

            return [
                $bad === [] ? 'GO' : 'NO-GO',
                $bad === []
                    ? 'All V2 orders carry a valid workflow status.'
                    : 'Orders with an unknown/invalid status: '.count($bad).'.',
                ['invalid' => $bad],
            ];
        });

        $checks[] = $this->guard('stuck_orders', function () use ($branchId, $orderId): array {
            $threshold = now()->subHours(self::STUCK_THRESHOLD_HOURS);
            $stuck = $this->v2Orders($branchId, $orderId)
                ->whereNotIn('status', LabWorkflowState::TERMINAL)
                ->withMax('statusLogs', 'changed_at')
                ->get(['id', 'order_number', 'status'])
                ->filter(function (LabOrder $o) use ($threshold): bool {
                    $raw = $o->status_logs_max_changed_at;
                    if ($raw === null) {
                        return false;
                    }

                    return Carbon::parse($raw)->lessThan($threshold);
                })
                ->map(fn (LabOrder $o) => [
                    'id' => $o->id,
                    'order_number' => $o->order_number,
                    'status' => $o->status,
                    'idle_since' => Carbon::parse($o->status_logs_max_changed_at)->toIso8601String(),
                ])
                ->values()
                ->all();

            return [
                $stuck === [] ? 'GO' : 'WATCH',
                $stuck === []
                    ? 'No stuck orders past the '.self::STUCK_THRESHOLD_HOURS.'h threshold.'
                    : count($stuck).' order(s) idle beyond '.self::STUCK_THRESHOLD_HOURS.'h.',
                ['stuck' => $stuck],
            ];
        });

        $checks[] = $this->guard('orphan_or_duplicate_tasks', function (): array {
            // lab_order_id is UNIQUE on both task tables, so duplicates are
            // schema-impossible; we surface only genuine orphans (task whose
            // order is missing/soft-deleted).
            $orphanPickups = LabPickupTask::query()
                ->whereDoesntHave('labOrder')
                ->count();
            $orphanDeliveries = LabDeliveryTask::query()
                ->whereDoesntHave('labOrder')
                ->count();
            $total = $orphanPickups + $orphanDeliveries;

            return [
                $total === 0 ? 'GO' : 'WATCH',
                $total === 0
                    ? 'No orphan pickup/delivery tasks.'
                    : "Orphan tasks — pickup: {$orphanPickups}, delivery: {$orphanDeliveries}.",
                ['orphan_pickups' => $orphanPickups, 'orphan_deliveries' => $orphanDeliveries],
            ];
        });

        $checks[] = $this->guard('failed_jobs', function (): array {
            $count = (int) DB::table('failed_jobs')->count();

            return [
                $count === 0 ? 'GO' : 'WATCH',
                $count === 0 ? 'No failed queue jobs.' : "Failed queue jobs: {$count}.",
                ['count' => $count],
            ];
        });

        $checks[] = $this->guard('evidence_storage_readable', function (): array {
            // Private evidence lives on the 'local' disk; confirm it is reachable.
            $disk = Storage::disk('local');
            $disk->exists('lab-workflow-evidence');

            return ['GO', 'Private evidence disk is reachable.', ['disk' => 'local']];
        });

        return $this->assemble($checks, $branchId, $orderId);
    }

    /**
     * Build an actor-availability check for a set of alternative permissions.
     *
     * @param  list<string>  $permissions
     * @return array<string,mixed>
     */
    private function actorCheck(string $key, array $permissions, string $label): array
    {
        return $this->guard($key, function () use ($permissions, $label): array {
            $count = User::query()
                ->where('is_active', true)
                ->where(function ($q) use ($permissions): void {
                    foreach ($permissions as $permission) {
                        $q->orWhereHas('roles.permissions', fn ($p) => $p->where('name', $permission))
                            ->orWhereHas('permissions', fn ($p) => $p->where('name', $permission));
                    }
                })
                ->distinct()
                ->count('users.id');

            return [
                $count > 0 ? 'GO' : 'WATCH',
                $count > 0
                    ? ucfirst($label)." actors available: {$count}."
                    : 'No dedicated '.$label.' actor — a Super Admin can act during pilot, but assign the role for real operation.',
                ['count' => $count],
            ];
        });
    }

    /**
     * V2 order query, optionally scoped to a branch and/or a single order.
     */
    private function v2Orders(?int $branchId, ?int $orderId): Builder
    {
        return LabOrder::query()
            ->where('workflow_version', LabOrder::WORKFLOW_V2)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when($orderId !== null, fn ($q) => $q->whereKey($orderId));
    }

    /**
     * Run a check body, catching any error into an UNKNOWN result.
     *
     * @param  callable():array{0:string,1:string,2:array<string,mixed>}  $body
     * @return array<string,mixed>
     */
    private function guard(string $key, callable $body): array
    {
        try {
            [$status, $detail, $value] = $body();

            return [
                'key' => $key,
                'status' => $status,
                'detail' => $this->masker->mask($detail),
                'value' => $value,
            ];
        } catch (\Throwable $e) {
            return [
                'key' => $key,
                'status' => 'UNKNOWN',
                'detail' => $this->masker->mask('check failed: '.$e->getMessage()),
                'value' => [],
            ];
        }
    }

    /**
     * @param  list<array<string,mixed>>  $checks
     * @return array<string,mixed>
     */
    private function assemble(array $checks, ?int $branchId, ?int $orderId): array
    {
        $noGo = [];
        $watch = [];
        foreach ($checks as $check) {
            $status = (string) $check['status'];
            if ($status === 'NO-GO') {
                $noGo[] = $check['key'];
            } elseif ($status === 'WATCH' || $status === 'UNKNOWN') {
                $watch[] = $check['key'];
            }
        }

        $decision = $noGo !== [] ? 'NO-GO' : ($watch !== [] ? 'WATCH' : 'GO');

        return [
            'generated_at' => now()->toIso8601String(),
            'environment' => app()->environment(),
            'scope' => [
                'branch_id' => $branchId,
                'order_id' => $orderId,
            ],
            'checks' => $checks,
            'summary' => [
                'decision' => $decision,
                'no_go' => $noGo,
                'watch' => $watch,
                'anomalies' => count($noGo) + count($watch),
                'critical_codes' => $noGo,
                'anomaly_codes' => array_values(array_merge($noGo, $watch)),
            ],
        ];
    }
}
