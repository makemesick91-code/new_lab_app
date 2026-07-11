<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Foundation\DevflowGovernanceService;
use Illuminate\Console\Command;

/**
 * DEVFLOW-1 — governance readiness check.
 */
final class FoundationDevflowCheckCommand extends Command
{
    protected $signature = 'foundation:devflow-check
        {--json : Output JSON report}
        {--strict : Return non-zero on WATCH as well as FAIL}
        {--fail-on-warning : Alias for --strict}';

    protected $description = 'Verify the DEVFLOW-1 safe-sprint-acceleration foundation is intact and safe.';

    public function handle(DevflowGovernanceService $service): int
    {
        $report = $service->collect();
        $decision = (string) ($report['decision'] ?? 'FAIL');

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('DEVFLOW-1 governance decision: '.$decision);
            foreach ($report['checks'] as $check) {
                $mark = $check['status'] === 'passed' ? '✓' : ($check['status'] === 'warning' ? '!' : '✗');
                $line = "  {$mark} {$check['check_id']}: {$check['message']}";
                $check['status'] === 'failed' ? $this->error($line) : ($check['status'] === 'warning' ? $this->warn($line) : $this->line($line));
            }
        }

        if ($decision === 'FAIL') {
            return self::FAILURE;
        }
        if ($decision === 'WATCH' && ($this->option('strict') || $this->option('fail-on-warning'))) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
