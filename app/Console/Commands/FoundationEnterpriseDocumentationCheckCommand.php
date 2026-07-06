<?php

namespace App\Console\Commands;

use App\Services\Foundation\EnterpriseDocumentationGovernanceService;
use Illuminate\Console\Command;

class FoundationEnterpriseDocumentationCheckCommand extends Command
{
    protected $signature = 'foundation:enterprise-documentation-check
        {--json : Output JSON report}
        {--strict : Return non-zero on WATCH as well as FAIL}
        {--fail-on-warning : Alias for --strict}';

    protected $description = 'Read-only ENT-15 Enterprise Documentation & Runbook governance check.';

    public function handle(EnterpriseDocumentationGovernanceService $service): int
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
        $this->info('Foundation Enterprise Documentation & Runbook Governance Check (ENT-15)');
        $this->line('Decision: '.($report['decision'] ?? 'UNKNOWN'));
        $this->line('Readiness: '.($report['readiness_status'] ?? 'unknown'));
        $this->line('Runbook registry: '.(($report['registry_ok'] ?? false) ? 'ok' : 'FAIL')
            .' ('.($report['runbook_count'] ?? 0).' runbooks)');
        $this->line('Runbook files: '.(($report['runbook_files_ok'] ?? false) ? 'present' : 'MISSING'));
        $this->line('Required sections: '.(($report['required_sections_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('Forbidden-command declarations: '.(($report['forbidden_declaration_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('Destructive-command safety: '.(($report['destructive_safety_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('Sensitive-content (secret/PII): '.(($report['sensitive_content_ok'] ?? false) ? 'clean' : 'FAIL'));
        $this->line('Foundation command linkage: '.(($report['foundation_linkage_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('Summary command: '.(($report['summary_command_registered'] ?? false) ? 'registered' : 'MISSING'));
        $this->line('Evidence profiles: '.(($report['evidence_profiles_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('Release-safety (pre-deploy gate): '.(($report['release_safety_ok'] ?? false) ? 'ok' : 'FAIL'));
        $this->line('ENT-14 scale projection: '.($report['load_test_scale_projection_decision'] ?? 'UNKNOWN'));
        $this->line('ENT-13 load-test baseline: '.($report['load_test_baseline_decision'] ?? 'UNKNOWN'));
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
