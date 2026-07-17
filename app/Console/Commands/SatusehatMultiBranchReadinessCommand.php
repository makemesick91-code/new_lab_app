<?php

namespace App\Console\Commands;

use App\Modules\Branch\Services\BranchService;
use App\Modules\Satusehat\Services\Pilot\SatusehatMultiBranchReadinessService;
use Illuminate\Console\Command;

/**
 * SATUSEHAT-4D — read-only comparative multi-branch readiness matrix.
 * Credential-independent, PII-free, branch-scoped (RME-enabled set). No network.
 */
class SatusehatMultiBranchReadinessCommand extends Command
{
    protected $signature = 'satusehat:multi-branch-readiness {--wave=} {--json}';

    protected $description = 'SATUSEHAT-4D comparative multi-branch readiness matrix (read-only, credential-independent)';

    public function handle(SatusehatMultiBranchReadinessService $matrix, BranchService $branches): int
    {
        $filters = [];
        if (is_numeric($this->option('wave'))) {
            $filters['wave_id'] = (int) $this->option('wave');
        }

        $rows = $matrix->matrix($branches->rmeEnabledIds(), $filters);
        $summary = $matrix->summary($rows);
        $report = ['summary' => $summary, 'branches' => $rows, 'external_blocker' => 'BLOCKED_EXTERNAL_CREDENTIAL', 'satusehat_2' => 'WATCH'];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        foreach ($rows as $r) {
            $this->line(sprintf(
                'branch=%d(%s) stage=%s score=%s hard=%d overdue=%d promote=%s',
                $r['branch_id'], $r['code'], $r['readiness_stage'], $r['internal_readiness_score'] ?? 'N/A',
                $r['open_hard_issues'], $r['overdue_issues'], $r['promotion_eligible'] ? 'yes' : 'no',
            ));
        }
        $this->info(sprintf('branches=%d pilot_ready_internal=%d — external submission BLOCKED (SATUSEHAT-2 WATCH).',
            $summary['branches_total'], $summary['branches_pilot_ready_internal']));

        return self::SUCCESS;
    }
}
