<?php

namespace App\Console\Commands;

use App\Services\Foundation\DbPerformanceGovernanceService;
use Illuminate\Console\Command;

/**
 * DBPERF-1 — Read-only PostgreSQL index optimization & query plan audit gate.
 *
 * Decision → exit code:
 *  - GO    → 0
 *  - WATCH → 0 (optional pg_stat introspection unavailable, or non-pgsql connection)
 *  - FAIL  → non-zero
 */
class FoundationDbPerformanceCheckCommand extends Command
{
    protected $signature = 'foundation:db-performance-check
        {--json : Output JSON report}
        {--include-db-stats : Read pg_indexes/pg_stat_user_tables/pg_stat_user_indexes metadata}
        {--include-query-plan-samples : Capture sanitized EXPLAIN-only (never ANALYZE) plan samples}';

    protected $description = 'Read-only DBPERF-1 PostgreSQL index optimization and query plan audit governance check.';

    public function handle(DbPerformanceGovernanceService $service): int
    {
        $report = $service->collect(
            (bool) $this->option('include-db-stats'),
            (bool) $this->option('include-query-plan-samples'),
        );
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
        $this->info('Foundation DB Performance Governance Check (DBPERF-1)');
        $this->line('Generated: '.($report['generated_at'] ?? ''));
        $this->line('DB driver: '.($report['db_driver'] ?? 'n/a'));
        $this->newLine();

        $s = $report['summary'];
        $this->line(sprintf(
            'Checks: %d | Passed: %d | Warnings: %d | Errors: %d | Decision: %s',
            $s['checks'] ?? 0, $s['passed'] ?? 0, $s['warnings'] ?? 0, $s['errors'] ?? 0, $s['decision'] ?? 'FAIL',
        ));

        $this->line(sprintf(
            'Applied index candidates: %d | Deferred candidates: %d',
            count($report['applied_index_candidates'] ?? []),
            count($report['deferred_index_candidates'] ?? []),
        ));

        if ($report['db_stats_requested'] ?? false) {
            $stats = $report['db_stats'];
            $this->line(sprintf(
                'DB stats: index_count=%d pg_stat_user_indexes=%s pg_stat_statements=%s',
                $stats['index_count'] ?? 0,
                ($stats['pg_stat_user_indexes_available'] ?? false) ? 'yes' : 'no',
                ($stats['pg_stat_statements_available'] ?? false) ? 'yes' : 'no',
            ));
        }

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
