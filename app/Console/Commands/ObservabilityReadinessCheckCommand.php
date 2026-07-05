<?php

namespace App\Console\Commands;

use App\Support\Observability\ObservabilityReadinessService;
use Illuminate\Console\Command;

class ObservabilityReadinessCheckCommand extends Command
{
    protected $signature = 'obs:readiness-check
        {--json : Output JSON}
        {--strict : Exit non-zero on any warning or missing critical config}
        {--fail-on-warning : Alias for --strict}
        {--header-smoke : Simulate an inbound request in-process (no real network call) and verify header behavior}';

    protected $description = 'OBS-1 — read-only request id / correlation id / observability readiness check.';

    public function handle(ObservabilityReadinessService $service): int
    {
        $result = $service->check();

        if ($this->option('header-smoke')) {
            $result['header_smoke'] = $service->headerSmoke();
            $result['header_smoke_status'] = $result['header_smoke']['status'];
        } else {
            $result['header_smoke_status'] = 'not_requested';
        }

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
        $this->info('Request ID / Correlation ID Observability Readiness (OBS-1)');
        $this->line('App env: '.$result['app_env'].' (debug safe: '.($result['app_debug_safe'] ? 'yes' : 'no').')');
        $this->line('Log channel: '.$result['log_channel'].' (stack configured: '.($result['log_stack_configured'] ? 'yes' : 'no').')');
        $this->line('Observability enabled: '.($result['observability_enabled'] ? 'yes' : 'no'));
        $this->line('Request id enabled: '.($result['request_id_enabled'] ? 'yes' : 'no').' (header: '.$result['request_id_header'].')');
        $this->line('Correlation id header: '.$result['correlation_id_header']);
        $this->line('Response header: '.$result['response_header']);
        $this->line('Trust inbound request id: '.($result['trust_inbound_request_id'] ? 'yes' : 'no'));
        $this->line('Trust inbound correlation id: '.($result['trust_inbound_correlation_id'] ? 'yes' : 'no'));
        $this->line('Max id length: '.$result['max_id_length']);
        $this->line('Log context enabled: '.($result['log_context_enabled'] ? 'yes' : 'no'));
        $this->line('PII masking enabled: '.($result['pii_masking_enabled'] ? 'yes' : 'no'));
        $this->line('Middleware detected: '.($result['middleware_detected'] ? 'yes' : 'no'));

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
        $this->line('Header smoke: '.$result['header_smoke_status']);

        $this->newLine();
        $this->line('Status: '.$result['status']);
        $this->line('Decision: '.$result['decision']);
    }
}
