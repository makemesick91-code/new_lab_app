<?php

namespace App\Console\Commands;

use App\Services\Foundation\CacheGovernanceService;
use Illuminate\Console\Command;

/**
 * CACHE-1 — Read-only cache governance gate.
 *
 * Decision → exit code:
 *  - GO    → 0
 *  - WATCH → 0 (readiness-only Redis probe unavailable)
 *  - FAIL  → non-zero
 */
class FoundationCacheGovernanceCheckCommand extends Command
{
    protected $signature = 'foundation:cache-governance-check
        {--json : Output JSON report}
        {--include-redis-probe : Run optional Redis write/read probe}';

    protected $description = 'Read-only CACHE-1 cache strategy, Redis readiness, and invalidation governance check.';

    public function handle(CacheGovernanceService $service): int
    {
        $report = $service->collect((bool) $this->option('include-redis-probe'));
        $decision = (string) ($report['summary']['decision'] ?? 'FAIL');

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->printConsole($report);
        }

        return $decision === 'FAIL' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printConsole(array $report): void
    {
        $this->info('Foundation Cache Governance Check (CACHE-1)');
        $this->line('Generated: '.($report['generated_at'] ?? ''));
        $this->line('Cache store: '.($report['cache_store'] ?? 'n/a'));
        $this->line('Redis runtime enabled: '.(($report['redis_runtime_enabled'] ?? false) ? 'yes' : 'no'));
        $this->newLine();

        $s = $report['summary'];
        $this->line(sprintf(
            'Checks: %d | Passed: %d | Warnings: %d | Errors: %d | Decision: %s',
            $s['checks'] ?? 0, $s['passed'] ?? 0, $s['warnings'] ?? 0, $s['errors'] ?? 0, $s['decision'] ?? 'FAIL',
        ));

        $this->line(sprintf('Allowed categories: %d | Denied categories: %d',
            count($report['allowed_categories'] ?? []),
            count($report['denied_categories'] ?? []),
        ));

        $nonPassing = array_filter($report['checks'] ?? [], fn (array $c) => ($c['status'] ?? '') !== 'passed');
        if ($nonPassing !== []) {
            $this->newLine();
            $this->line('Non-passing checks:');
            foreach ($nonPassing as $check) {
                $this->line(sprintf('  - [%s] %s: %s', $check['status'], $check['check_id'], $check['message']));
            }
        }
    }
}
