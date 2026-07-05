<?php

namespace App\Console\Commands;

use App\Support\Database\DatabaseReplicaReadinessService;
use Illuminate\Console\Command;

class DatabaseReplicaReadinessCheckCommand extends Command
{
    protected $signature = 'db:replica-readiness-check
        {--json : Output JSON}
        {--strict : Exit non-zero on strict replica readiness failures}
        {--connect-test : Run read-only select/recovery probes when replica is enabled}
        {--lag-check : Run read-only PostgreSQL replica lag probe when replica is enabled}
        {--fail-on-warning : Exit non-zero if any warning is reported}';

    protected $description = 'REPLICA-1 — read-only database replica readiness check.';

    public function handle(DatabaseReplicaReadinessService $service): int
    {
        $result = $service->check([
            'strict' => (bool) $this->option('strict'),
            'connect_test' => (bool) $this->option('connect-test'),
            'lag_check' => (bool) $this->option('lag-check'),
        ]);

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderText($result);
        }

        if ($result['decision'] === 'NO_GO') {
            return self::FAILURE;
        }

        if ($this->option('fail-on-warning') && $result['warnings'] !== []) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function renderText(array $result): void
    {
        $this->info('Database Replica Readiness (REPLICA-1)');
        $this->line('App env: '.$result['app_env'].' (debug safe: '.($result['app_debug_safe'] ? 'yes' : 'no').')');
        $this->line('Default connection: '.$result['default_connection']);
        $this->line('Primary driver: '.$result['primary_driver']);
        $this->line('Primary host configured: '.($result['primary_host_configured'] ? 'yes' : 'no'));
        $this->line('Replica enabled: '.($result['replica_enabled'] ? 'yes' : 'no'));
        $this->line('Replica expected: '.($result['replica_expected'] ? 'yes' : 'no'));
        $this->line('Replica connection: '.$result['replica_connection']);
        $this->line('Replica host configured: '.($result['replica_host_configured'] ? 'yes' : 'no'));
        $this->line('Replica database configured: '.($result['replica_database_configured'] ? 'yes' : 'no'));
        $this->line('Replica username configured: '.($result['replica_username_configured'] ? 'yes' : 'no'));
        $this->line('Replica password configured: '.($result['replica_password_configured_as_boolean_only'] ? 'yes' : 'no'));
        $this->line('Connect test: '.$result['connect_test_status'].' — '.$result['connect_test_message']);
        $this->line('Recovery check: '.$result['recovery_check_status'].' — '.$result['recovery_check_message']);
        $this->line('Lag check: '.$result['lag_check_status'].' — '.$result['lag_check_message']);
        $this->line('Max lag seconds: '.$result['max_lag_seconds']);

        if ($result['missing_required_config_keys'] !== []) {
            $this->warn('Missing required config keys: '.implode(', ', $result['missing_required_config_keys']));
        }

        if ($result['warnings'] !== []) {
            $this->newLine();
            $this->warn('Warnings:');
            foreach ($result['warnings'] as $warning) {
                $this->line('  - '.$warning);
            }
        }

        if ($result['recommendations'] !== []) {
            $this->newLine();
            $this->line('Recommendations:');
            foreach ($result['recommendations'] as $recommendation) {
                $this->line('  - '.$recommendation);
            }
        }

        $this->newLine();
        $this->line('Status: '.$result['status']);
        $this->line('Decision: '.$result['decision']);
    }
}
