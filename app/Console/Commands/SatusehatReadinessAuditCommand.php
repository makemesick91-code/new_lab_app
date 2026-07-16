<?php

namespace App\Console\Commands;

use App\Modules\Branch\Services\BranchService;
use App\Modules\Satusehat\Services\DataQuality\SatusehatOnboardingChecklistService;
use App\Modules\Satusehat\Services\DataQuality\SatusehatOperationalReadinessService;
use Illuminate\Console\Command;

/**
 * SATUSEHAT-4A — aggregated readiness audit. Read-only. Decision:
 * GO   — no open HARD issue (external items may honestly remain blocked)
 * WATCH — open hard issues or drifted candidates need attention
 * `--strict` exits non-zero on WATCH.
 */
class SatusehatReadinessAuditCommand extends Command
{
    protected $signature = 'satusehat:readiness-audit {--branch=} {--json} {--strict}';

    protected $description = 'SATUSEHAT-4A aggregated operational readiness audit (read-only, credential-independent)';

    public function handle(
        SatusehatOperationalReadinessService $readiness,
        SatusehatOnboardingChecklistService $checklist,
        BranchService $branches,
    ): int {
        $branchIds = $branches->rmeEnabledIds();
        if (is_numeric($this->option('branch'))) {
            $requested = (int) $this->option('branch');
            $branchIds = in_array($requested, $branchIds, true) ? [$requested] : [];
        }

        $metrics = $readiness->metrics($branchIds);
        $openHard = (int) ($metrics['issues']['open_by_severity']['hard'] ?? 0);
        $drifted = (int) ($metrics['by_readiness_status']['source_changed'] ?? 0);

        $decision = ($openHard > 0 || $drifted > 0) ? 'WATCH' : 'GO';

        $report = [
            'decision' => $decision,
            'open_hard_issues' => $openHard,
            'source_changed_candidates' => $drifted,
            'metrics' => $metrics,
            'onboarding' => $checklist->report()['summary'],
            'note' => 'GO = tidak ada isu hard internal; item eksternal tetap blocked_external sampai kredensial resmi (SATUSEHAT-2 WATCH).',
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("Decision: {$decision}");
            $this->line('  open_hard_issues: '.$openHard);
            $this->line('  source_changed_candidates: '.$drifted);
            $this->line('  total_candidates: '.$metrics['total_candidates']);
            $this->line('  open_issue_total: '.$metrics['open_issue_total']);
        }

        if ($decision !== 'GO' && $this->option('strict')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
