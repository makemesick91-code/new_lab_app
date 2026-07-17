<?php

namespace App\Console\Commands;

use App\Modules\Branch\Services\BranchService;
use App\Modules\Satusehat\Services\Pilot\SatusehatBranchReadinessProfileService;
use Illuminate\Console\Command;

/**
 * SATUSEHAT-4C — internal pilot eligibility gate for a branch. Read-only.
 * Decision: suspended | not_eligible | blocked_external_credential (internally
 * ready, externally blocked — always this sprint). `--strict` exits non-zero
 * when internal gates fail (not internally ready).
 */
class SatusehatPilotEligibilityCommand extends Command
{
    protected $signature = 'satusehat:pilot-eligibility {--branch=} {--json} {--strict}';

    protected $description = 'SATUSEHAT-4C internal pilot eligibility (read-only, credential-independent)';

    public function handle(SatusehatBranchReadinessProfileService $profiles, BranchService $branches): int
    {
        $branchIds = $branches->rmeEnabledIds();
        if (is_numeric($this->option('branch'))) {
            $requested = (int) $this->option('branch');
            $branchIds = in_array($requested, $branchIds, true) ? [$requested] : [];
        }

        $results = [];
        $anyFailed = false;
        foreach ($branchIds as $branchId) {
            $result = $profiles->eligibilityFor($branchId);
            $results[] = [
                'branch_id' => $branchId,
                'decision' => $result['decision'],
                'internal_ready' => $result['internal_ready'],
                'external_blocked' => $result['external_blocked'],
                'failed_internal_gates' => $result['failed_internal_gates'],
            ];
            if (! $result['internal_ready']) {
                $anyFailed = true;
            }
        }

        $report = [
            'results' => $results,
            'note' => 'blocked_external_credential = seluruh gate internal lulus; hanya kredensial eksternal yang tersisa (SATUSEHAT-2 WATCH).',
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($results as $r) {
                $this->line(sprintf('branch=%d decision=%s internal_ready=%s',
                    $r['branch_id'], $r['decision'], $r['internal_ready'] ? 'yes' : 'no'));
                if ($r['failed_internal_gates'] !== []) {
                    $this->line('  failed gates: '.implode(', ', $r['failed_internal_gates']));
                }
            }
        }

        return $anyFailed && $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }
}
