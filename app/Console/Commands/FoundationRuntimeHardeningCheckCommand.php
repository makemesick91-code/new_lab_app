<?php

namespace App\Console\Commands;

use App\Services\Foundation\EntFoundationRuntimeHardeningGovernanceService;
use Illuminate\Console\Command;

class FoundationRuntimeHardeningCheckCommand extends Command
{
    protected $signature = 'foundation:runtime-hardening-check
        {--json : Output JSON report}
        {--strict : Return non-zero on WATCH as well as FAIL}
        {--fail-on-warning : Alias for --strict}';

    protected $description = 'Read-only POST-ENT umbrella check: ENT-1..4 audit + queue worker runtime + deploy evidence timeout hardening, re-verifying ENT-5..16 stay GO.';

    public function handle(EntFoundationRuntimeHardeningGovernanceService $service): int
    {
        $report = $service->collect();
        $decision = (string) ($report['decision'] ?? 'FAIL');

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('POST-ENT Enterprise Foundation Runtime Hardening');
            $this->line('Decision: '.$decision);
            $this->line('ENT-1..4 audit: '.(($report['ent_1_4_audit_ok'] ?? false) ? 'ok' : 'FAIL'));
            $this->line('Queue worker runtime: '.(($report['queue_worker_ok'] ?? false) ? 'ok' : 'FAIL').' ('.($report['queue_worker_service_name'] ?? '?').', conn '.($report['queue_connection'] ?? '?').')');
            $this->line('Deploy evidence timeout: '.(($report['deploy_evidence_timeout_ok'] ?? false) ? 'ok' : 'FAIL'));
            $this->line('Evidence profiles: '.(($report['evidence_profiles_ok'] ?? false) ? 'ok' : 'FAIL'));
            $this->line('Closed baseline (ENT-5..16): '.($report['closed_baseline_decision'] ?? 'UNKNOWN'));
            $this->line('Final closure tag: '.($report['final_closure_tag'] ?? '?').' (is ENT-17: '.(($report['is_ent_17'] ?? false) ? 'YES' : 'no').')');
            $this->line('Next recommended sprint: '.($report['next_recommended_sprint'] ?? '?'));
            $s = $report['summary'];
            $this->line(sprintf('Checks: %d | Passed: %d | Warnings: %d | Errors: %d', $s['checks'] ?? 0, $s['passed'] ?? 0, $s['warnings'] ?? 0, $s['errors'] ?? 0));
            $nonPassing = array_filter($report['checks'] ?? [], fn (array $c) => ($c['status'] ?? '') !== 'passed');
            foreach ($nonPassing as $c) {
                $this->line(sprintf('  - [%s] %s: %s', $c['status'], $c['check_id'], $c['message']));
            }
        }

        if ($decision === 'FAIL') {
            return self::FAILURE;
        }
        if ($decision === 'WATCH' && ($this->option('strict') || $this->option('fail-on-warning'))) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
