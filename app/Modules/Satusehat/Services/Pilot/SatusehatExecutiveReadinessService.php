<?php

namespace App\Modules\Satusehat\Services\Pilot;

use App\Modules\Satusehat\Models\SatusehatBranchTransition;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Models\SatusehatRolloutWave;
use App\Modules\Satusehat\Models\SatusehatUatRun;
use Illuminate\Support\Carbon;

/**
 * SATUSEHAT-4D — executive / owner cross-branch aggregate metrics.
 *
 * Read-only, aggregate-by-default, PII-free (no NIK, no raw clinical notes, no
 * scans). Reuses the comparative matrix + summary; adds wave progress, UAT
 * completion, rehearsal coverage, and daily/weekly/monthly governance windows.
 * Every branch remains blocked externally by design — external readiness is
 * never asserted here. Owner access is read-only.
 */
class SatusehatExecutiveReadinessService
{
    public function __construct(
        private readonly SatusehatMultiBranchReadinessService $matrix,
        private readonly SatusehatPilotOperationsService $operations,
    ) {}

    private function env(): string
    {
        return (string) config('satusehat.environment');
    }

    /**
     * @param  list<int>  $branchIds  authorized branch-scoped set
     * @return array<string,mixed>
     */
    public function overview(array $branchIds): array
    {
        $rows = $this->matrix->matrix($branchIds);
        $summary = $this->matrix->summary($rows);

        return [
            'summary' => $summary,
            'wave_progress' => $this->waveProgress(),
            'uat_completion' => $this->uatCompletion(),
            'rehearsal_coverage' => $this->rehearsalCoverage($rows),
            'issue_aging' => $this->operations->issueAging($branchIds),
            'operator_backlog' => $this->operations->operatorBacklog($branchIds),
            // Always-true, explicit external posture.
            'external_submission_enabled' => (bool) config('satusehat.send_enabled', false),
            'production_blocked' => true,
            'satusehat_2_status' => 'WATCH',
        ];
    }

    /** Wave counts by status (env-scoped; waves are not branch-owned). */
    private function waveProgress(): array
    {
        $counts = SatusehatRolloutWave::query()
            ->where('environment', $this->env())
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->toArray();

        $active = 0;
        foreach (SatusehatRolloutWave::ACTIVE_STATUSES as $s) {
            $active += (int) ($counts[$s] ?? 0);
        }

        return [
            'by_status' => $counts,
            'active' => $active,
            'closed' => (int) ($counts[SatusehatRolloutWave::STATUS_CLOSED] ?? 0),
            'total' => array_sum($counts),
        ];
    }

    private function uatCompletion(): array
    {
        $total = SatusehatUatRun::query()->where('environment', $this->env())->count();
        $signedOff = SatusehatUatRun::query()->where('environment', $this->env())
            ->where('status', SatusehatUatRun::STATUS_SIGNED_OFF)->count();

        return [
            'total_runs' => $total,
            'signed_off_runs' => $signedOff,
            // null (not 0%) when there are no runs — never a fake rate.
            'completion_rate' => $total > 0 ? round($signedOff / $total * 100, 1) : null,
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    private function rehearsalCoverage(array $rows): array
    {
        $count = count($rows);
        $rehearsed = count(array_filter($rows, fn ($r) => $r['last_rehearsal_result'] !== null));

        return [
            'branches_total' => $count,
            'branches_rehearsed' => $rehearsed,
            'coverage_rate' => $count > 0 ? round($rehearsed / $count * 100, 1) : null,
        ];
    }

    /**
     * Daily / weekly / monthly governance windows (bounded counts, PII-free).
     *
     * @param  list<int>  $branchIds
     * @return array<string,array<string,int>>
     */
    public function governanceWindows(array $branchIds): array
    {
        $branchIds = array_values(array_unique(array_map('intval', $branchIds)));

        return [
            'daily' => $this->windowCounts($branchIds, Carbon::now()->subDay()),
            'weekly' => $this->windowCounts($branchIds, Carbon::now()->subWeek()),
            'monthly' => $this->windowCounts($branchIds, Carbon::now()->subMonth()),
        ];
    }

    /**
     * @param  list<int>  $branchIds
     * @return array<string,int>
     */
    private function windowCounts(array $branchIds, Carbon $since): array
    {
        if ($branchIds === []) {
            return ['new_hard_issues' => 0, 'source_drift_issues' => 0, 'overdue_open_issues' => 0, 'demotions' => 0];
        }

        $issue = fn () => SatusehatDataQualityIssue::query()
            ->where('environment', $this->env())
            ->whereIn('branch_id', $branchIds);

        $newHard = (clone $issue())
            ->where('severity', SatusehatDataQualityIssue::SEVERITY_HARD)
            ->where('first_detected_at', '>=', $since)
            ->count();

        $sourceDrift = (clone $issue())
            ->where('rule_code', 'source_drift')
            ->where('first_detected_at', '>=', $since)
            ->count();

        $overdue = (clone $issue())
            ->whereIn('status', SatusehatDataQualityIssue::OPEN_STATUSES)
            ->whereNotNull('due_at')
            ->where('due_at', '<', Carbon::now())
            ->count();

        $demotions = SatusehatBranchTransition::query()
            ->where('environment', $this->env())
            ->whereIn('branch_id', $branchIds)
            ->where('transition_type', SatusehatBranchTransition::TYPE_DEMOTION)
            ->where('created_at', '>=', $since)
            ->count();

        return [
            'new_hard_issues' => $newHard,
            'source_drift_issues' => $sourceDrift,
            'overdue_open_issues' => $overdue,
            'demotions' => $demotions,
        ];
    }
}
