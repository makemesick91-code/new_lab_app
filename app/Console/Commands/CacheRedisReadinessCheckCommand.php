<?php

namespace App\Console\Commands;

use App\Support\Cache\RedisSharedCacheReadinessService;
use Illuminate\Console\Command;

class CacheRedisReadinessCheckCommand extends Command
{
    protected $signature = 'cache:redis-readiness-check
        {--json : Output JSON}
        {--strict : Exit non-zero when Redis is expected but misconfigured/unreachable}
        {--connect-test : Attempt a read-only Redis PING}
        {--ttl-test : Set/get/delete one unique healthcheck key with a short TTL}
        {--lock-test : Acquire/release one unique healthcheck lock with a short TTL}
        {--fail-on-warning : Exit non-zero on any warning}';

    protected $description = 'CACHE-1 — read-only Redis shared cache & session readiness check.';

    public function handle(RedisSharedCacheReadinessService $service): int
    {
        $result = $service->check([
            'strict' => (bool) $this->option('strict'),
            'connect_test' => (bool) $this->option('connect-test'),
            'ttl_test' => (bool) $this->option('ttl-test'),
            'lock_test' => (bool) $this->option('lock-test'),
        ]);

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderText($result);
        }

        if ($result['status'] === 'fail') {
            return self::FAILURE;
        }

        if ((bool) $this->option('fail-on-warning') && $result['status'] === 'warning') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function renderText(array $result): void
    {
        $this->info('Redis Shared Cache & Session Readiness (CACHE-1)');
        $this->line('App env: '.$result['app_env'].' (debug safe: '.($result['app_debug_safe'] ? 'yes' : 'no').')');
        $this->line('Cache store: '.$result['cache_store']);
        $this->line('Session driver: '.$result['session_driver']);
        $this->line('Queue connection: '.$result['queue_connection']);
        $this->line('Redis expected: '.($result['redis_expected'] ? 'yes' : 'no'));
        $this->line('Redis connection: '.$result['redis_connection']);
        $this->line('Redis client available: '.($result['redis_client_available'] ? 'yes' : 'no'));
        $this->line('Redis host configured: '.($result['redis_host_configured'] ? 'yes' : 'no'));
        $this->line('Redis port configured: '.($result['redis_port_configured'] ? 'yes' : 'no'));
        $this->line('Redis password configured: '.($result['redis_password_configured_as_boolean_only'] ? 'yes' : 'no'));
        $this->line('Connect test: '.$result['connect_test_status'].' — '.$result['connect_test_message']);
        $this->line('TTL test: '.$result['ttl_test_status'].' — '.$result['ttl_test_message']);
        $this->line('Lock test: '.$result['lock_test_status'].' — '.$result['lock_test_message']);
        $this->line('Healthcheck prefix: '.$result['healthcheck_prefix']);

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
