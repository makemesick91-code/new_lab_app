<?php

namespace App\Console\Commands;

use App\Services\Foundation\HealthCheckGovernanceService;
use Illuminate\Console\Command;

class FoundationHealthCheckCommand extends Command
{
    protected $signature = 'foundation:health-check
        {--json : Output JSON report}
        {--strict : Return non-zero on WATCH as well as FAIL}
        {--fail-on-warning : Alias for --strict}';

    protected $description = 'Read-only ENT-8 Observability & Health Check Pack governance check.';

    public function handle(HealthCheckGovernanceService $service): int
    {
        $report = $service->collect();
        $decision = (string) ($report['decision'] ?? 'FAIL');

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->printConsole($report);
        }

        if ($decision === 'FAIL') {
            return self::FAILURE;
        }

        if ($decision === 'WATCH' && ($this->option('strict') || $this->option('fail-on-warning'))) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printConsole(array $report): void
    {
        $this->info('Foundation Observability & Health Check Pack Governance Check (ENT-8)');
        $this->line('Decision: '.($report['decision'] ?? 'UNKNOWN'));
        $this->line('Readiness: '.($report['readiness_status'] ?? 'unknown'));
        $this->line('Health check enabled: '.(($report['health_check_enabled'] ?? false) ? 'yes' : 'no'));
        $this->line('Liveness route: '.(($report['liveness_route_registered'] ?? false) ? 'registered' : 'absent'));
        $this->line('Readiness route: '.(($report['readiness_route_registered'] ?? false) ? 'registered' : 'absent'));
        $this->line('Readiness overall status: '.($report['readiness_overall_status'] ?? 'unknown'));
        $this->line('Components: '.implode(', ', $report['components'] ?? []));
        $this->line('Alerting status: '.($report['alerting_status'] ?? 'unknown'));
        $this->line('External alert channel: '.(($report['external_alert_channel_enabled'] ?? false) ? 'ENABLED' : 'disabled'));
        $this->line('LB /health/lb registered: '.(($report['lb_health_endpoint_registered'] ?? false) ? 'yes' : 'no'));
        $this->line('ENT-5 queue retry governance: '.($report['queue_retry_decision'] ?? 'UNKNOWN'));
        $this->line('ENT-6 idempotency/outbox governance: '.($report['idempotency_outbox_decision'] ?? 'UNKNOWN'));
        $this->line('ENT-7 developer-console governance: '.($report['developer_console_decision'] ?? 'UNKNOWN'));
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
