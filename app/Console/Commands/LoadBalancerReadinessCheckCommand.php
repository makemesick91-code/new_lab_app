<?php

namespace App\Console\Commands;

use App\Support\LoadBalancer\LoadBalancerReadinessService;
use Illuminate\Console\Command;

class LoadBalancerReadinessCheckCommand extends Command
{
    protected $signature = 'lb:readiness-check
        {--json : Output JSON}
        {--strict : Exit non-zero on any warning}
        {--fail-on-warning : Alias for --strict}
        {--header-smoke : Simulate forwarded-header resolution in-process (no real network call)}
        {--url= : Reserved for future use; currently informational only}';

    protected $description = 'LB-1 — read-only load balancer pilot readiness check.';

    public function handle(LoadBalancerReadinessService $service): int
    {
        $result = $service->check();

        if ($this->option('header-smoke')) {
            $result['header_smoke'] = $service->headerSmoke();
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
        $this->info('Load Balancer Pilot Readiness (LB-1)');
        $this->line('App env: '.$result['app_env'].' (debug safe: '.($result['app_debug_safe'] ? 'yes' : 'no').')');
        $this->line('LB pilot enabled: '.($result['lb_pilot_enabled'] ? 'yes' : 'no'));
        $this->line('Health endpoint: '.($result['health_endpoint_enabled'] ? 'enabled' : 'disabled').' at '.$result['health_endpoint_path']);
        $this->line('Trusted proxies configured: '.($result['trusted_proxies_configured'] ? 'yes' : 'no'));
        $this->line('Expect forwarded headers: '.($result['expect_forwarded_headers'] ? 'yes' : 'no'));
        $this->line('Expect HTTPS: '.($result['expect_https'] ? 'yes' : 'no').' (APP_URL scheme: '.$result['app_url_scheme'].')');
        $this->line('Session driver: '.$result['session_driver'].' (secure cookie: '.($result['session_secure_cookie'] ? 'yes' : 'no').')');
        $this->line('Cache store: '.$result['cache_store']);
        $this->line('Queue connection: '.$result['queue_connection']);
        $this->line('Object storage enabled: '.($result['object_storage_enabled'] ? 'yes' : 'no'));
        $this->line('Runtime stateless status: '.($result['runtime_stateless_status'] ?? 'n/a'));

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

        if (isset($result['header_smoke'])) {
            $this->newLine();
            $smoke = $result['header_smoke'];
            $this->line('Header smoke: '.$smoke['status'].(isset($smoke['reason']) ? ' ('.$smoke['reason'].')' : ''));
            if ($smoke['status'] === 'simulated') {
                $this->line('  - resolved scheme: '.$smoke['resolved_scheme']);
                $this->line('  - resolved host: '.$smoke['resolved_host']);
                $this->line('  - resolved client ip: '.$smoke['resolved_client_ip']);
            }
        }

        $this->newLine();
        $this->line('Status: '.$result['status']);
        $this->line('Decision: '.$result['decision']);
    }
}
