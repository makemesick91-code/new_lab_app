<?php

namespace App\Console\Commands;

use App\Modules\Branch\Services\BranchService;
use App\Modules\Satusehat\Services\Pilot\SatusehatBranchReadinessProfileService;
use Illuminate\Console\Command;

/**
 * SATUSEHAT-4C — read-only branch readiness snapshot(s). Credential-independent,
 * PII-free, branch-scoped (RME-enabled set). No external request.
 */
class SatusehatBranchReadinessCommand extends Command
{
    protected $signature = 'satusehat:branch-readiness {--branch=} {--json}';

    protected $description = 'SATUSEHAT-4C branch readiness snapshot (read-only, credential-independent)';

    public function handle(SatusehatBranchReadinessProfileService $profiles, BranchService $branches): int
    {
        $branchIds = $branches->rmeEnabledIds();
        if (is_numeric($this->option('branch'))) {
            $requested = (int) $this->option('branch');
            $branchIds = in_array($requested, $branchIds, true) ? [$requested] : [];
        }

        $rows = [];
        foreach ($branchIds as $branchId) {
            $snapshot = $profiles->computeSnapshot($branchId);
            $eligibility = $profiles->eligibilityFor($branchId);
            $rows[] = [
                'branch_id' => $branchId,
                'score' => $snapshot['score']['score'],
                'internal_ready' => $eligibility['internal_ready'],
                'decision' => $eligibility['decision'],
                'open_hard_issues' => $snapshot['open_hard_issues'],
                'source_changed_candidates' => $snapshot['source_changed_candidates'],
                'total_candidates' => $snapshot['total_candidates'],
                'failed_internal_gates' => $eligibility['failed_internal_gates'],
            ];
        }

        $report = ['branches' => $rows, 'external_blocker' => 'BLOCKED_EXTERNAL_CREDENTIAL'];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        foreach ($rows as $r) {
            $this->line(sprintf(
                'branch=%d score=%s ready=%s decision=%s hard=%d',
                $r['branch_id'], $r['score'] ?? 'N/A', $r['internal_ready'] ? 'yes' : 'no', $r['decision'], $r['open_hard_issues'],
            ));
        }
        $this->info('External submission remains blocked (SATUSEHAT-2 WATCH).');

        return self::SUCCESS;
    }
}
