<?php

namespace App\Console\Commands;

use App\Services\Foundation\EnterpriseFoundationClosureGovernanceService;
use Illuminate\Console\Command;

class FoundationEnterpriseClosureCheckCommand extends Command
{
    protected $signature = 'foundation:enterprise-closure-check
        {--json : Output JSON report}
        {--strict : Return non-zero on WATCH as well as NO-GO}
        {--fail-on-warning : Alias for --strict}';

    protected $description = 'Read-only ENT-16 Enterprise Foundation Closure GO/NO-GO governance check.';

    public function handle(EnterpriseFoundationClosureGovernanceService $service): int
    {
        $report = $service->collect();
        $decision = (string) ($report['decision'] ?? 'NO-GO');

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->printConsole($report);
        }

        if ($decision === 'NO-GO') {
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
        $this->info('Foundation Enterprise Foundation Closure GO/NO-GO Check (ENT-16)');
        $this->line('Decision: '.($report['decision'] ?? 'UNKNOWN'));
        $this->line('Readiness: '.($report['readiness_status'] ?? 'unknown'));
        $this->line('Final closure tag: '.($report['final_closure_tag'] ?? 'n/a'));
        $this->line('Roadmap (ENT-1..16 completed): '.(($report['roadmap_ok'] ?? false) ? 'ok' : 'FAIL')
            .' ('.($report['roadmap_completed_count'] ?? 0).' completed)');
        $this->line('Mandatory gates config: '.(($report['mandatory_gates_config_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('Next recommended sprint: '.($report['next_recommended_sprint'] ?? 'n/a')
            .(($report['stale_next_detected'] ?? false) ? ' (STALE)' : ' (not stale)'));
        $this->line('Evidence profiles: '.(($report['evidence_profiles_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('Release-safety gate: '.(($report['release_safety_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('CI-gate registry: '.(($report['ci_gate_registry_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('Operational scripts: '.(($report['scripts_ok'] ?? false) ? 'present' : 'MISSING'));
        $this->line('Runbooks: '.(($report['runbooks_ok'] ?? false) ? 'present' : 'MISSING'));
        $this->line('Sensitive-content (secret/PII): '.(($report['sensitive_content_ok'] ?? false) ? 'clean' : 'FAIL'));
        $this->line(sprintf('Closure criteria met: %d / %d', $report['closure_criteria_met'] ?? 0, $report['closure_criteria_total'] ?? 0));
        $this->newLine();

        $this->line('Mandatory gate decisions:');
        foreach ((array) ($report['mandatory_gate_decisions'] ?? []) as $id => $decision) {
            $this->line(sprintf('  - %s: %s', $id, $decision));
        }
        $this->newLine();

        $s = $report['summary'];
        $this->line(sprintf(
            'Checks: %d | Passed: %d | Warnings: %d | Errors: %d | Decision: %s',
            $s['checks'] ?? 0,
            $s['passed'] ?? 0,
            $s['warnings'] ?? 0,
            $s['errors'] ?? 0,
            $s['decision'] ?? 'NO-GO',
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
