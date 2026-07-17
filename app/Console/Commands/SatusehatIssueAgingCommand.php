<?php

namespace App\Console\Commands;

use App\Modules\Branch\Services\BranchService;
use App\Modules\Satusehat\Services\Pilot\SatusehatPilotOperationsService;
use Illuminate\Console\Command;

/**
 * SATUSEHAT-4C — read-only open-issue aging across RME branches. PII-free.
 */
class SatusehatIssueAgingCommand extends Command
{
    protected $signature = 'satusehat:issue-aging {--branch=} {--json}';

    protected $description = 'SATUSEHAT-4C data-quality issue aging (read-only)';

    public function handle(SatusehatPilotOperationsService $ops, BranchService $branches): int
    {
        $branchIds = $branches->rmeEnabledIds();
        if (is_numeric($this->option('branch'))) {
            $requested = (int) $this->option('branch');
            $branchIds = in_array($requested, $branchIds, true) ? [$requested] : [];
        }

        $aging = $ops->issueAging($branchIds);

        if ($this->option('json')) {
            $this->line(json_encode($aging, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        foreach ($aging as $bucket => $count) {
            $this->line(sprintf('  %-14s %d', $bucket, $count));
        }

        return self::SUCCESS;
    }
}
