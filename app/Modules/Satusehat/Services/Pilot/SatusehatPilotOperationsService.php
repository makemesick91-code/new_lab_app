<?php

namespace App\Modules\Satusehat\Services\Pilot;

use App\Modules\Satusehat\Models\SatusehatBranchPilotProfile;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Support\SatusehatProductionActivationGuard;
use Illuminate\Support\Facades\DB;

/**
 * SATUSEHAT-4C — pilot operations metrics (read-only, branch-scoped, bounded).
 *
 * Powers the pilot operations dashboard, issue aging, and operator backlog.
 * Constant/bounded query count; never PII; never HTTP; always surfaces the
 * external credential blocker + production guard honestly alongside internal
 * readiness.
 */
class SatusehatPilotOperationsService
{
    private const AGING_ROW_LIMIT = 100;

    public function __construct(
        private readonly SatusehatBranchReadinessProfileService $profiles,
        private readonly SatusehatProductionActivationGuard $productionGuard,
    ) {}

    /**
     * Pilot operations overview for the scoped branch set.
     *
     * @param  list<int>  $branchIds
     * @return array<string, mixed>
     */
    public function overview(array $branchIds): array
    {
        $env = (string) config('satusehat.environment');
        $pilots = $branchIds === [] ? collect() : SatusehatBranchPilotProfile::query()
            ->where('environment', $env)
            ->whereIn('branch_id', $branchIds)
            ->whereIn('pilot_status', [
                SatusehatBranchPilotProfile::STATUS_CANDIDATE,
                SatusehatBranchPilotProfile::STATUS_APPROVED,
                SatusehatBranchPilotProfile::STATUS_SUSPENDED,
            ])
            ->with('branch:id,code,name')
            ->orderByRaw("case pilot_status when 'approved' then 0 when 'candidate' then 1 else 2 end")
            ->get();

        $primary = $pilots->firstWhere('pilot_status', SatusehatBranchPilotProfile::STATUS_APPROVED)
            ?? $pilots->first();

        return [
            'production_blocked' => ! $this->productionGuard->isProductionAllowed(),
            'satusehat2_watch' => config('satusehat.sandbox_verified') !== true,
            'external_send_disabled' => config('satusehat.enabled') !== true && config('satusehat.send_enabled') !== true,
            'external_blocker' => 'BLOCKED_EXTERNAL_CREDENTIAL',
            'primary_pilot' => $primary,
            'pilots' => $pilots,
            'issue_aging' => $this->issueAging($branchIds),
            'operator_backlog' => $this->operatorBacklog($branchIds),
        ];
    }

    /**
     * Open-issue aging buckets + overdue count for the scoped branches.
     *
     * @param  list<int>  $branchIds
     * @return array<string, int>
     */
    public function issueAging(array $branchIds): array
    {
        if ($branchIds === []) {
            return ['fresh_lt_1d' => 0, 'age_1_3d' => 0, 'age_3_7d' => 0, 'age_gt_7d' => 0, 'overdue' => 0, 'total_open' => 0];
        }

        $base = fn () => SatusehatDataQualityIssue::query()
            ->where('environment', (string) config('satusehat.environment'))
            ->whereIn('branch_id', $branchIds)
            ->whereIn('status', SatusehatDataQualityIssue::OPEN_STATUSES);

        $now = now();

        return [
            'fresh_lt_1d' => (clone $base())->where('first_detected_at', '>=', $now->copy()->subDay())->count(),
            'age_1_3d' => (clone $base())->whereBetween('first_detected_at', [$now->copy()->subDays(3), $now->copy()->subDay()])->count(),
            'age_3_7d' => (clone $base())->whereBetween('first_detected_at', [$now->copy()->subDays(7), $now->copy()->subDays(3)])->count(),
            'age_gt_7d' => (clone $base())->where('first_detected_at', '<', $now->copy()->subDays(7))->count(),
            'overdue' => (clone $base())->whereNotNull('due_at')->where('due_at', '<', $now)->count(),
            'total_open' => (clone $base())->count(),
        ];
    }

    /**
     * Operator backlog: open-issue counts per assignee (bounded) + unassigned.
     *
     * @param  list<int>  $branchIds
     * @return array{unassigned: int, by_assignee: list<array<string, mixed>>}
     */
    public function operatorBacklog(array $branchIds): array
    {
        if ($branchIds === []) {
            return ['unassigned' => 0, 'by_assignee' => []];
        }

        $base = fn () => SatusehatDataQualityIssue::query()
            ->where('environment', (string) config('satusehat.environment'))
            ->whereIn('branch_id', $branchIds)
            ->whereIn('status', SatusehatDataQualityIssue::OPEN_STATUSES);

        $unassigned = (clone $base())->whereNull('assigned_to')->count();

        $rows = (clone $base())
            ->whereNotNull('assigned_to')
            ->selectRaw('assigned_to, count(*) as total, sum(case when due_at is not null and due_at < ? then 1 else 0 end) as overdue', [now()])
            ->groupBy('assigned_to')
            ->orderByDesc('total')
            ->limit(self::AGING_ROW_LIMIT)
            ->get();

        $names = $rows->isEmpty() ? collect() : DB::table('users')
            ->whereIn('id', $rows->pluck('assigned_to')->all())
            ->pluck('name', 'id');

        return [
            'unassigned' => $unassigned,
            'by_assignee' => $rows->map(fn ($r) => [
                'assigned_to' => (int) $r->assigned_to,
                'name' => (string) ($names[$r->assigned_to] ?? ('User #'.$r->assigned_to)),
                'open' => (int) $r->total,
                'overdue' => (int) $r->overdue,
            ])->values()->all(),
        ];
    }
}
