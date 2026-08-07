<?php

namespace App\Console\Commands;

use App\Services\Foundation\SelfHostedRunnerGovernanceService;
use Illuminate\Console\Command;

class FoundationSelfHostedRunnerCheckCommand extends Command
{
    protected $signature = 'foundation:self-hosted-runner-check
        {--json : Output JSON report}
        {--strict : Return non-zero on WATCH as well as FAIL}
        {--fail-on-warning : Alias for --strict}';

    protected $description = 'Read-only CICD-CTRL-3 dedicated self-hosted CI runner governance check.';

    public function handle(SelfHostedRunnerGovernanceService $service): int
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
        $this->info('Foundation Dedicated Self-Hosted CI Runner Governance Check (CICD-CTRL-3)');
        $this->line('Decision: '.($report['decision'] ?? 'UNKNOWN'));
        $this->line('Readiness: '.($report['readiness_status'] ?? 'unknown'));
        $this->line('Runner: '.($report['runner_name'] ?? 'unknown').' (service user: '.($report['runner_service_user'] ?? 'unknown').')');
        $this->line('Required labels: ['.implode(', ', (array) ($report['required_labels'] ?? [])).']');
        $this->line('Default runner mode: '.($report['default_runner_mode'] ?? 'unknown').' (fail-safe)');
        $this->line('Runner contract: '.(($report['contract_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('Workflow routing: '.(($report['workflow_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('Deploy isolation: '.(($report['deploy_isolation_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('Health script: '.(($report['health_script_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('Production DB guard: '.(($report['database_guard_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('CICD-CTRL-1 runtime control: '.($report['ci_runtime_control_decision'] ?? 'UNKNOWN'));
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
