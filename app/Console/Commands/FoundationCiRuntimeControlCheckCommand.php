<?php

namespace App\Console\Commands;

use App\Services\Foundation\CiRuntimeControlGovernanceService;
use Illuminate\Console\Command;

class FoundationCiRuntimeControlCheckCommand extends Command
{
    protected $signature = 'foundation:ci-runtime-control-check
        {--json : Output JSON report}
        {--strict : Return non-zero on WATCH as well as FAIL}
        {--fail-on-warning : Alias for --strict}';

    protected $description = 'Read-only CICD-CTRL-1 Safe CI Runtime Control governance check.';

    public function handle(CiRuntimeControlGovernanceService $service): int
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
        $this->info('Foundation Safe CI Runtime Control Governance Check (CICD-CTRL-1)');
        $this->line('Decision: '.($report['decision'] ?? 'UNKNOWN'));
        $this->line('Readiness: '.($report['readiness_status'] ?? 'unknown'));
        $this->line('Classifier: '.(($report['classifier_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('Workflow wiring: '.(($report['workflow_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('Safety invariant: '.(($report['safety_invariant_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('  - skip-critical profiles: ['.implode(', ', (array) ($report['skip_critical_profiles'] ?? [])).']');
        $this->line('  - default profile: '.($report['default_profile'] ?? 'unknown'));
        $this->line('Full-suite fallback: '.(($report['full_suite_fallback'] ?? false) ? 'preserved' : 'MISSING'));
        $this->line('ENT-10 enterprise gate: '.($report['enterprise_gate_decision'] ?? 'UNKNOWN'));
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
