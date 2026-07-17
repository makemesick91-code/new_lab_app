<?php

namespace App\Console\Commands;

use App\Modules\Branch\Services\BranchService;
use App\Modules\Satusehat\Services\Pilot\SatusehatPilotOperationsService;
use Illuminate\Console\Command;

/**
 * SATUSEHAT-4C — read-only operator remediation backlog across RME branches.
 * Shows open + overdue counts per assignee (bounded) and unassigned total.
 * PII-free (names are operational labels only).
 */
class SatusehatOperatorBacklogCommand extends Command
{
    protected $signature = 'satusehat:operator-backlog {--branch=} {--json}';

    protected $description = 'SATUSEHAT-4C operator remediation backlog (read-only)';

    public function handle(SatusehatPilotOperationsService $ops, BranchService $branches): int
    {
        $branchIds = $branches->rmeEnabledIds();
        if (is_numeric($this->option('branch'))) {
            $requested = (int) $this->option('branch');
            $branchIds = in_array($requested, $branchIds, true) ? [$requested] : [];
        }

        $backlog = $ops->operatorBacklog($branchIds);

        if ($this->option('json')) {
            $this->line(json_encode($backlog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('  unassigned: '.$backlog['unassigned']);
        foreach ($backlog['by_assignee'] as $row) {
            $this->line(sprintf('  %-24s open=%d overdue=%d', $row['name'], $row['open'], $row['overdue']));
        }

        return self::SUCCESS;
    }
}
