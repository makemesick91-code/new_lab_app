<?php

namespace App\Console\Commands;

use App\Services\Foundation\PostgresRuntimeGovernanceService;
use Illuminate\Console\Command;

/**
 * DBPERF-2 — Read-only PgBouncer readiness & PostgreSQL runtime tuning gate.
 *
 * Decision → exit code:
 *  - GO    → 0
 *  - WATCH → 0 (PgBouncer not installed/probed while cutover disabled, or non-pgsql connection)
 *  - FAIL  → non-zero
 */
class FoundationPostgresRuntimeCheckCommand extends Command
{
    protected $signature = 'foundation:postgres-runtime-check
        {--json : Output JSON report}
        {--include-db-stats : Read safe PostgreSQL runtime settings and connection stats}
        {--include-pgbouncer-probe : Probe for a local PgBouncer binary/service/listener}';

    protected $description = 'Read-only DBPERF-2 PgBouncer readiness and PostgreSQL runtime tuning governance check.';

    public function handle(PostgresRuntimeGovernanceService $service): int
    {
        $report = $service->collect(
            (bool) $this->option('include-db-stats'),
            (bool) $this->option('include-pgbouncer-probe'),
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
        $this->info('Foundation PostgreSQL Runtime & PgBouncer Readiness Check (DBPERF-2)');
        $this->line('Generated: '.($report['generated_at'] ?? ''));
        $this->line('DB driver: '.($report['db_driver'] ?? 'n/a'));
        $this->newLine();

        $s = $report['summary'];
        $this->line(sprintf(
            'Checks: %d | Passed: %d | Warnings: %d | Errors: %d | Decision: %s',
            $s['checks'] ?? 0, $s['passed'] ?? 0, $s['warnings'] ?? 0, $s['errors'] ?? 0, $s['decision'] ?? 'FAIL',
        ));

        $cutover = $report['app_cutover_detection'] ?? [];
        $this->line(sprintf(
            'App DB routing: %s (potential_cutover=%s)',
            ($cutover['potential_cutover'] ?? false) ? 'PgBouncer-shaped' : 'direct PostgreSQL',
            ($cutover['potential_cutover'] ?? false) ? 'yes' : 'no',
        ));

        if ($report['db_stats_requested'] ?? false) {
            $stats = $report['db_stats'];
            $conn = $stats['connection_stats'] ?? [];
            $this->line(sprintf(
                'Connections: active=%d idle=%d idle_in_transaction=%d total=%d',
                $conn['active'] ?? 0, $conn['idle'] ?? 0, $conn['idle_in_transaction'] ?? 0, $conn['total'] ?? 0,
            ));
        }

        if ($report['pgbouncer_probe_requested'] ?? false) {
            $probe = $report['pgbouncer_probe'] ?? [];
            $this->line(sprintf(
                'PgBouncer probe: installed=%s listener_active=%s',
                ($probe['installed'] ?? false) ? 'yes' : 'no',
                ($probe['listener_active'] ?? false) ? 'yes' : 'no',
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
