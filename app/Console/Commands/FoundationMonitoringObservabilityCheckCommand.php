<?php

namespace App\Console\Commands;

use App\Services\Foundation\FoundationMonitoringStatusService;
use Illuminate\Console\Command;

/**
 * MON-1 — read-only consolidation of existing observability signals into one
 * explainable GO/WATCH/FAIL/UNKNOWN decision.
 *
 * Default mode: lightweight, report-only (exit 0). It does NOT run heavy Pest
 * tests, does NOT duplicate NSF/CICD gates, and does NOT execute domain audits.
 * --include-audits: opt-in, CLI-only invocation of the existing, independent
 * audit commands (their exit status is reported, their logic is never copied).
 * --strict: exit non-zero only on real unsafe FAIL states.
 */
class FoundationMonitoringObservabilityCheckCommand extends Command
{
    protected $signature = 'foundation:monitoring-observability-check
        {--json : Output the consolidated report as JSON}
        {--strict : Exit non-zero on unsafe FAIL states}
        {--fail-on-warning : Also exit non-zero on WATCH (stricter than --strict)}
        {--include-audits : Also invoke existing audit commands (CLI-only) and report their exit status}';

    protected $description = 'Read-only MON-1 monitoring/observability consolidation across existing health, deploy, queue, storage, and audit signals.';

    public function handle(FoundationMonitoringStatusService $service): int
    {
        $report = $service->collect([
            'include_audits' => (bool) $this->option('include-audits'),
        ]);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderConsole($report);
        }

        $decision = (string) ($report['decision'] ?? FoundationMonitoringStatusService::UNKNOWN);
        $unsafe = (bool) ($report['unsafe'] ?? false);

        if ($this->option('strict') && ($decision === FoundationMonitoringStatusService::FAIL || $unsafe)) {
            return self::FAILURE;
        }

        if ($this->option('fail-on-warning')
            && in_array($decision, [FoundationMonitoringStatusService::FAIL, FoundationMonitoringStatusService::WATCH], true)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function renderConsole(array $report): void
    {
        $decision = (string) ($report['decision'] ?? 'UNKNOWN');
        $summary = (array) ($report['summary'] ?? []);

        $this->newLine();
        $this->line('  MON-1 Foundation Monitoring & Observability');
        $this->line('  Decision: '.$this->decorate($decision));
        $this->line(sprintf(
            '  Signals: GO=%d WATCH=%d FAIL=%d UNKNOWN=%d (total %d)',
            $summary['GO'] ?? 0,
            $summary['WATCH'] ?? 0,
            $summary['FAIL'] ?? 0,
            $summary['UNKNOWN'] ?? 0,
            $summary['total'] ?? 0,
        ));
        $this->line('  Include audits: '.(($report['include_audits'] ?? false) ? 'yes' : 'no'));
        $this->newLine();

        $rows = [];
        foreach ((array) ($report['signals'] ?? []) as $signal) {
            $rows[] = [
                (string) ($signal['status'] ?? '?'),
                (string) ($signal['label'] ?? $signal['key'] ?? '?'),
                (string) ($signal['summary'] ?? ''),
            ];
        }
        $this->table(['Status', 'Signal', 'Summary'], $rows);

        $reasons = (array) ($report['reasons'] ?? []);
        if ($reasons !== []) {
            $this->newLine();
            $this->line('  Reasons:');
            foreach ($reasons as $reason) {
                $this->line('   - '.$reason);
            }
        }
    }

    private function decorate(string $decision): string
    {
        return match ($decision) {
            'GO' => '<info>GO</info>',
            'WATCH' => '<comment>WATCH</comment>',
            'FAIL' => '<error>FAIL</error>',
            default => '<comment>UNKNOWN</comment>',
        };
    }
}
