<?php

namespace App\Console\Commands;

use App\Support\Runtime\RuntimePortabilityReadinessService;
use Illuminate\Console\Command;

class RuntimeStatelessReadinessCheckCommand extends Command
{
    protected $signature = 'runtime:stateless-readiness-check
        {--write-test : Additionally write/read/delete a small healthcheck file}
        {--json : Output JSON}
        {--strict : Exit non-zero on any warning}
        {--fail-on-warning : Alias for --strict}';

    protected $description = 'STATELESS-1 — read-only runtime statelessness & deploy portability readiness check.';

    public function handle(RuntimePortabilityReadinessService $service): int
    {
        $result = $service->check((bool) $this->option('write-test'));

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderText($result);
        }

        if ($result['status'] === 'fail') {
            return self::FAILURE;
        }

        $strict = (bool) $this->option('strict') || (bool) $this->option('fail-on-warning');

        if ($strict && in_array($result['status'], ['warning', 'ready_single_node'], true)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function renderText(array $result): void
    {
        $this->info('Runtime Statelessness & Deploy Portability Readiness (STATELESS-1)');
        $this->line('App env: '.$result['app_env'].' (debug safe: '.($result['app_debug_safe'] ? 'yes' : 'no').')');
        $this->line('Session driver: '.$result['session_driver']);
        $this->line('Cache store: '.$result['cache_store']);
        $this->line('Queue connection: '.$result['queue_connection']);
        $this->line('Filesystem default disk: '.$result['filesystem_default_disk']);
        $this->line('Log channel: '.$result['log_channel']);
        $this->line('Object storage enabled: '.($result['object_storage_enabled'] ? 'yes' : 'no').' ('.$result['object_storage_status'].')');
        $this->line('Allowed local write paths: '.implode(', ', $result['local_write_paths_allowed']));

        foreach ($result['writable_paths_status'] as $path => $writable) {
            $this->line(($writable ? '  [ok] ' : '  [MISSING] ').$path);
        }

        if ($result['horizontal_scale_warnings'] !== []) {
            $this->newLine();
            $this->warn('Horizontal scale warnings:');
            foreach ($result['horizontal_scale_warnings'] as $warning) {
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

        $this->line('Write test: '.$result['write_test_status']);
        if (! empty($result['write_test_error'])) {
            $this->error('Write test error: '.$result['write_test_error']);
        }

        $this->newLine();
        $this->line('Status: '.$result['status']);
    }
}
