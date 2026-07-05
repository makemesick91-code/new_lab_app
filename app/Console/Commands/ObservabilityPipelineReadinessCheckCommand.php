<?php

namespace App\Console\Commands;

use App\Support\Observability\CentralizedObservabilityReadinessService;
use Illuminate\Console\Command;

class ObservabilityPipelineReadinessCheckCommand extends Command
{
    protected $signature = 'obs:pipeline-readiness-check
        {--json : Output JSON}
        {--strict : Exit non-zero on any warning}
        {--fail-on-warning : Alias for --strict}
        {--log-smoke : Write a single safe synthetic non-PII log line locally}
        {--error-smoke : Simulate error-report readiness without external traffic (opt-in, default skipped)}';

    protected $description = 'OBS-2 — read-only centralized logging / error tracking readiness check.';

    public function handle(CentralizedObservabilityReadinessService $service): int
    {
        $result = $service->check();

        $result['log_smoke_status'] = $this->option('log-smoke')
            ? $service->logSmoke()['status']
            : 'not_requested';

        $result['error_smoke_status'] = $this->option('error-smoke')
            ? $service->errorSmoke()['status']
            : 'not_requested';

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderText($result);
        }

        if ($result['status'] === 'fail') {
            return self::FAILURE;
        }

        $strict = (bool) $this->option('strict') || (bool) $this->option('fail-on-warning');

        if ($strict && $result['status'] === 'warning') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function renderText(array $result): void
    {
        $this->info('Centralized Logging & Error Tracking Readiness (OBS-2)');
        $this->line('App env: '.$result['app_env'].' (debug safe: '.($result['app_debug_safe'] ? 'yes' : 'no').')');
        $this->line('Log channel: '.$result['log_channel'].' (stack configured: '.($result['log_stack_configured'] ? 'yes' : 'no').')');
        $this->line('Request id enabled: '.($result['request_id_enabled'] ? 'yes' : 'no'));
        $this->line('Log context enabled: '.($result['log_context_enabled'] ? 'yes' : 'no'));
        $this->newLine();
        $this->line('Central logging enabled: '.($result['central_logging_enabled'] ? 'yes' : 'no').' (driver: '.$result['central_logging_driver'].')');
        $this->line('Central logging endpoint configured: '.($result['central_logging_endpoint_configured'] ? 'yes' : 'no'));
        $this->line('Central logging API key configured: '.($result['central_logging_api_key_configured_as_boolean_only'] ? 'yes' : 'no'));
        $this->line('Central logging send PII: '.($result['central_logging_send_pii'] ? 'yes' : 'no'));
        $this->newLine();
        $this->line('Error tracking enabled: '.($result['error_tracking_enabled'] ? 'yes' : 'no').' (driver: '.$result['error_tracking_driver'].')');
        $this->line('Error tracking DSN configured: '.($result['error_tracking_dsn_configured_as_boolean_only'] ? 'yes' : 'no'));
        $this->line('Error tracking sample rate: '.$result['error_tracking_sample_rate']);
        $this->line('Error tracking send PII: '.($result['error_tracking_send_pii'] ? 'yes' : 'no'));

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
        $this->line('Log smoke: '.$result['log_smoke_status']);
        $this->line('Error smoke: '.$result['error_smoke_status']);

        $this->newLine();
        $this->line('Status: '.$result['status']);
        $this->line('Decision: '.$result['decision']);
    }
}
