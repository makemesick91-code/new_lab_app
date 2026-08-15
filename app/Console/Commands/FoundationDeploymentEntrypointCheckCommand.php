<?php

namespace App\Console\Commands;

use App\Services\Foundation\DeploymentEntrypointGovernanceService;
use Illuminate\Console\Command;

class FoundationDeploymentEntrypointCheckCommand extends Command
{
    protected $signature = 'foundation:deployment-entrypoint-check
        {--json : Output JSON report}
        {--strict : Return non-zero on WATCH as well as FAIL}
        {--fail-on-warning : Alias for --strict}';

    protected $description = 'Read-only DEPLOY-HARDEN-1 immutable deployment entrypoint & self-update safety check.';

    public function handle(DeploymentEntrypointGovernanceService $service): int
    {
        $report = $service->report();
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
        $yn = fn ($v) => $v ? 'yes' : 'NO';

        $this->info('Immutable Deployment Entrypoint & Self-Update Safety (DEPLOY-HARDEN-1)');
        $this->line('Decision: '.($report['decision'] ?? 'UNKNOWN'));
        $this->line('Status:   '.($report['status'] ?? 'unknown'));

        $bootstrap = (array) ($report['bootstrap'] ?? []);
        $this->line('Trusted bootstrap: '.(($bootstrap['ok'] ?? false) ? 'safe' : 'UNSAFE'));
        $this->line('  - exclusive deploy lock:        '.$yn($bootstrap['lock_present'] ?? false));
        $this->line('  - trusted lock/snapshot root:   '.$yn($bootstrap['trusted_root_present'] ?? false));
        $this->line('  - exact target pinning:         '.$yn($bootstrap['target_pinning_present'] ?? false));
        $this->line('  - immutable snapshot:           '.$yn($bootstrap['snapshot_present'] ?? false));
        $this->line('  - snapshot trust boundary:      '.$yn($bootstrap['trust_boundary_present'] ?? false));
        $this->line('  - guaranteed cleanup:           '.$yn($bootstrap['cleanup_present'] ?? false));
        $this->line('  - source/snapshot hash proof:   '.$yn($bootstrap['hash_proof_present'] ?? false));

        foreach ((array) ($report['mutating_entrypoints'] ?? []) as $key => $posture) {
            $posture = (array) $posture;
            $this->line("Entrypoint {$key}: ".(($posture['ok'] ?? false) ? 'safe' : 'UNSAFE'));
            $this->line('  - hands over to bootstrap:      '.$yn($posture['hands_over_to_bootstrap'] ?? false));
            $this->line('  - refuses mutable execution:    '.$yn($posture['refuses_mutable_execution'] ?? false));
            $this->line('  - pins an exact target SHA:     '.$yn($posture['pins_exact_target'] ?? false));
            $this->line('  - verifies HEAD vs target:      '.$yn($posture['verifies_head_against_target'] ?? false));
            $this->line('  - guards a dirty checkout:      '.$yn($posture['guards_dirty_checkout'] ?? false));
            $this->line('  - helpers from the snapshot:    '.$yn($posture['no_working_tree_invocation'] ?? false));
        }

        $closure = (array) ($report['execution_closure'] ?? []);
        $this->line('Execution closure complete:      '.$yn($closure['closure_complete'] ?? false));
        $this->line('Runtime identity overlay:        '.$yn($closure['identity_overlay_present'] ?? false));

        $runner = (array) ($report['operator_interface'] ?? []);
        $this->line('Stale checkout handled (no pre-pull): '.$yn($runner['stale_checkout_handled'] ?? false));
        $this->line('ENT-11 deployment/rollback governance: '.($report['deployment_rollback_governance_decision'] ?? 'UNKNOWN'));

        foreach ((array) ($report['errors'] ?? []) as $error) {
            $this->error('ERROR: '.$error);
        }
        foreach ((array) ($report['warnings'] ?? []) as $warning) {
            $this->warn('WATCH: '.$warning);
        }
    }
}
