<?php

namespace App\Console\Commands;

use App\Services\Foundation\ReportingSummaryGovernanceService;
use Illuminate\Console\Command;

class FoundationReportingSummaryCheckCommand extends Command
{
    protected $signature = 'foundation:reporting-summary-check
        {--json : Output JSON report}
        {--include-db-inventory : Safely inventory rpt_* tables/views/materialized views without row data}';

    protected $description = 'Read-only RPT-1 reporting summary and materialized-view readiness governance check.';

    public function handle(ReportingSummaryGovernanceService $service): int
    {
        $report = $service->collect((bool) $this->option('include-db-inventory'));
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
        $this->info('Foundation Reporting Summary Governance Check (RPT-1)');
        $this->line('Generated: '.($report['generated_at'] ?? ''));
        $this->line('Runtime summary reads: '.(($report['runtime_reads_from_summary_enabled'] ?? false) ? 'enabled' : 'disabled'));
        $this->line('Auto refresh: '.(($report['auto_refresh_enabled'] ?? false) ? 'enabled' : 'disabled'));
        $this->newLine();

        $s = $report['summary'];
        $this->line(sprintf(
            'Checks: %d | Passed: %d | Warnings: %d | Errors: %d | Decision: %s',
            $s['checks'] ?? 0,
            $s['passed'] ?? 0,
            $s['warnings'] ?? 0,
            $s['errors'] ?? 0,
            $s['decision'] ?? 'FAIL',
        ));

        $this->line(sprintf(
            'Allowed categories: %d | Denied categories: %d | Deferred candidates: %d',
            count($report['allowed_categories'] ?? []),
            count($report['denied_categories'] ?? []),
            count($report['deferred_candidates'] ?? []),
        ));

        if ($report['db_inventory_requested'] ?? false) {
            $inventory = (array) ($report['db_inventory'] ?? []);
            $this->line(sprintf(
                'DB inventory: %d rpt table(s), %d view(s), %d materialized view(s)',
                count($inventory['tables'] ?? []),
                count($inventory['views'] ?? []),
                count($inventory['materialized_views'] ?? []),
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
