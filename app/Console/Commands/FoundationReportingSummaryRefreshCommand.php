<?php

namespace App\Console\Commands;

use App\Services\Foundation\ReportingSummaryRefreshService;
use Illuminate\Console\Command;

class FoundationReportingSummaryRefreshCommand extends Command
{
    protected $signature = 'foundation:reporting-summary-refresh
        {--summary= : Reporting summary key to refresh/preview}
        {--dry-run : Force dry-run mode (default)}
        {--execute : Request execute mode; requires --confirm}
        {--confirm : Confirm execute mode}
        {--json : Output JSON report}';

    protected $description = 'RPT-1 reporting summary refresh readiness command. Dry-run by default; no physical summary writes in RPT-1.';

    public function handle(ReportingSummaryRefreshService $service): int
    {
        $execute = (bool) $this->option('execute');
        $confirm = (bool) $this->option('confirm');
        $summaryKey = $this->option('summary') !== null ? (string) $this->option('summary') : null;

        $report = $service->preview($summaryKey, $execute, $confirm);
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
        $this->info('Foundation Reporting Summary Refresh Readiness (RPT-1)');
        $this->line('Generated: '.($report['generated_at'] ?? ''));
        $this->line('Summary: '.($report['summary_key'] ?? 'all'));
        $this->line('Mode: '.($report['mode'] ?? 'dry_run'));
        $this->line('Writes performed: '.(($report['writes_performed'] ?? false) ? 'yes' : 'no'));
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

        $this->line((string) ($report['message'] ?? ''));
    }
}
