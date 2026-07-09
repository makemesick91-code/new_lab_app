<?php

namespace App\Console\Commands;

use App\Services\Foundation\FiveBranchRolloutReadinessService;
use Illuminate\Console\Command;

/**
 * ROLL-5-1 — read-only readiness check for a controlled, staged rollout to five
 * clinic branches. Consolidates existing foundations (MON-1 monitoring, ENT-8
 * health, branch/role readiness, RME/cashier/inventory surfaces, backup +
 * restore-drill evidence, deploy/rollback, lightweight capacity smoke) into one
 * explainable GO/WATCH/FAIL/UNKNOWN decision.
 *
 * Default mode: lightweight, report-only (exit 0). Does NOT run heavy tests,
 * does NOT duplicate NSF/CICD gates, and does NOT execute domain audits.
 * --include-audits invokes the existing audit commands (CLI-only) and reports
 * their exit status. --capacity-smoke runs bounded read-only COUNT probes.
 * --strict exits non-zero only on a real unsafe FAIL.
 */
class FiveBranchRolloutReadinessCommand extends Command
{
    protected $signature = 'rollout:five-branch-readiness
        {--json : Output the consolidated readiness report as JSON}
        {--strict : Exit non-zero on unsafe FAIL states}
        {--fail-on-warning : Also exit non-zero on WATCH (stricter than --strict)}
        {--include-audits : Also invoke existing audit commands (CLI-only) and report their exit status}
        {--capacity-smoke : Run the lightweight, read-only capacity smoke probes}
        {--stage= : Focus branch-count readiness on rollout stage 1, 2, or 3}';

    protected $description = 'Read-only ROLL-5-1 controlled five-branch rollout readiness across app health, branch/role, RME/cashier/inventory, backup, restore-drill, monitoring, and capacity signals.';

    public function handle(FiveBranchRolloutReadinessService $service): int
    {
        $report = $service->collect([
            'include_audits' => (bool) $this->option('include-audits'),
            'capacity_smoke' => (bool) $this->option('capacity-smoke'),
            'stage' => $this->option('stage'),
        ]);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderConsole($report);
        }

        $decision = (string) ($report['decision'] ?? FiveBranchRolloutReadinessService::UNKNOWN);
        $unsafe = (bool) ($report['unsafe'] ?? false);

        if ($this->option('strict') && ($decision === FiveBranchRolloutReadinessService::FAIL || $unsafe)) {
            return self::FAILURE;
        }

        if ($this->option('fail-on-warning')
            && in_array($decision, [FiveBranchRolloutReadinessService::FAIL, FiveBranchRolloutReadinessService::WATCH], true)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function renderConsole(array $report): void
    {
        $decision = (string) ($report['decision'] ?? 'UNKNOWN');
        $summary = (array) ($report['summary'] ?? []);
        $stage = $report['stage'] ?? null;

        $this->newLine();
        $this->line('  ROLL-5-1 Five Branch Controlled Rollout Readiness');
        $this->line('  Decision: '.$this->decorate($decision).($stage ? '  (stage '.$stage.')' : ''));
        $this->line(sprintf(
            '  Signals: GO=%d WATCH=%d FAIL=%d UNKNOWN=%d (total %d)',
            $summary['GO'] ?? 0,
            $summary['WATCH'] ?? 0,
            $summary['FAIL'] ?? 0,
            $summary['UNKNOWN'] ?? 0,
            $summary['total'] ?? 0,
        ));
        $this->line('  Include audits: '.(($report['include_audits'] ?? false) ? 'yes' : 'no').
            ' · Capacity smoke: '.(($report['capacity_smoke'] ?? false) ? 'yes' : 'no'));
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

        $stages = (array) ($report['stages'] ?? []);
        if ($stages !== []) {
            $this->newLine();
            $this->line('  Rollout stages:');
            foreach ($stages as $s) {
                $this->line(sprintf(
                    '   %s  %s (butuh %s cabang, tersedia %s)',
                    $this->decorate((string) ($s['status'] ?? '?')),
                    (string) ($s['label'] ?? '?'),
                    (string) ($s['branch_target'] ?? '?'),
                    (string) ($s['available_branches'] ?? '?'),
                ));
            }
        }

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
