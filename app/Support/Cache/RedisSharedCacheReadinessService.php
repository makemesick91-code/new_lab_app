<?php

namespace App\Support\Cache;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Predis\Client;
use Throwable;

/**
 * CACHE-1 — read-only Redis shared cache & session readiness audit.
 *
 * OFF by default (CACHE_REDIS_EXPECTED=false): never switches CACHE_STORE,
 * SESSION_DRIVER, or any queue connection. Optional connect/TTL/lock checks
 * are non-destructive, prefixed, short-TTL, and never call FLUSHDB/FLUSHALL
 * or delete a wildcard key.
 */
class RedisSharedCacheReadinessService
{
    /**
     * @param  array{strict?: bool, connect_test?: bool, ttl_test?: bool, lock_test?: bool}  $options
     * @return array<string, mixed>
     */
    public function check(array $options = []): array
    {
        $expected = (bool) config('cache_scale.redis.expected', false);
        $strict = (bool) ($options['strict'] ?? false) || (bool) config('cache_scale.redis.strict', false);
        $connectionName = (string) config('cache_scale.redis.connection', 'cache');
        $healthcheckPrefix = (string) config('cache_scale.redis.healthcheck_prefix', 'healthchecks:cache');
        $ttlSeconds = (int) config('cache_scale.redis.ttl_seconds', 30);
        $expectCacheStore = (bool) config('cache_scale.redis.expect_cache_store', false);
        $expectSessionDriver = (bool) config('cache_scale.redis.expect_session_driver', false);
        $expectLocks = (bool) config('cache_scale.redis.expect_locks', false);
        $allowSingleVpsWarnings = (bool) config('cache_scale.redis.allow_single_vps_warnings', true);

        $cacheStore = (string) config('cache.default');
        $sessionDriver = (string) config('session.driver');
        $queueConnection = (string) config('queue.default');

        $connectionConfig = (array) config("database.redis.{$connectionName}", []);
        $connectionConfigured = $connectionConfig !== [] && $this->configured($connectionConfig['host'] ?? null);
        $hostConfigured = $this->configured($connectionConfig['host'] ?? null);
        $portConfigured = $this->configured($connectionConfig['port'] ?? null);
        $passwordConfigured = $this->configured($connectionConfig['password'] ?? null);

        $clientAvailable = $this->clientAvailable();

        $warnings = [];

        if ($expected && ! $clientAvailable) {
            $warnings[] = 'Redis is expected but no Redis client (ext-redis or predis) is installed.';
        }

        if ($expected && ! $connectionConfigured) {
            $warnings[] = "Redis is expected but the \"{$connectionName}\" connection is not configured in database.redis.";
        }

        if (($expected || $expectCacheStore) && $cacheStore !== 'redis') {
            $warnings[] = 'Redis is expected but the cache store (CACHE_STORE) is not redis.';
        }

        if (($expected || $expectSessionDriver) && $sessionDriver !== 'redis') {
            $warnings[] = 'Redis is expected but the session driver (SESSION_DRIVER) is not redis.';
        }

        if ($expectLocks && ! (bool) ($options['lock_test'] ?? false)) {
            $warnings[] = 'Distributed locks are expected (CACHE_REDIS_EXPECT_LOCKS) but the lock healthcheck was not run this pass — use --lock-test to verify.';
        }

        if (! $expected && $allowSingleVpsWarnings) {
            if ($cacheStore === 'file') {
                $warnings[] = 'Cache store is file — acceptable for this single VPS pilot but not for multi-node; revisit before real load-balancer scale-out.';
            }

            if (in_array($sessionDriver, ['file', 'database'], true)) {
                $warnings[] = "Session driver is {$sessionDriver} — acceptable for pilot but evaluate a shared Redis/database session before load-balancer scale-out.";
            }

            if ($queueConnection === 'database') {
                $warnings[] = 'Queue connection is database — acceptable now, but future workers need lock/idempotency governance before relying on Redis queues.';
            }
        }

        $connectTest = $this->runOrSkip(
            $clientAvailable,
            $connectionConfigured,
            $expected || (bool) ($options['connect_test'] ?? false),
            fn () => $this->ping($connectionName)
        );

        $ttlTest = $this->runOrSkip(
            $clientAvailable,
            $connectionConfigured,
            (bool) ($options['ttl_test'] ?? false),
            fn () => $this->ttlHealthcheck($connectionName, $healthcheckPrefix, $ttlSeconds)
        );

        $lockTest = $this->runOrSkip(
            $clientAvailable,
            $connectionConfigured,
            (bool) ($options['lock_test'] ?? false),
            fn () => $this->lockHealthcheck($connectionName, $healthcheckPrefix, $ttlSeconds)
        );

        $anyTestFailed = in_array('failed', [$connectTest['status'], $ttlTest['status'], $lockTest['status']], true);
        $strictFailure = $strict && $expected && (! $clientAvailable || ! $connectionConfigured || $anyTestFailed);

        $status = match (true) {
            $strictFailure => 'fail',
            ! $expected => 'redis_not_expected',
            $anyTestFailed => 'warning',
            $warnings !== [] => 'warning',
            $connectTest['status'] === 'passed' => 'redis_connect_ready',
            $connectionConfigured => 'redis_config_ready',
            default => 'single_node_ready',
        };

        $decision = match ($status) {
            'fail' => 'NO_GO',
            'warning' => 'GO_WITH_WARNINGS',
            default => 'GO',
        };

        return [
            'status' => $status,
            'decision' => $decision,
            'app_env' => (string) config('app.env'),
            'app_debug_safe' => ! ((string) config('app.env') === 'production' && (bool) config('app.debug')),
            'cache_store' => $cacheStore,
            'session_driver' => $sessionDriver,
            'queue_connection' => $queueConnection,
            'redis_expected' => $expected,
            'redis_connection' => $connectionName,
            'redis_client_available' => $clientAvailable,
            'redis_host_configured' => $hostConfigured,
            'redis_port_configured' => $portConfigured,
            'redis_password_configured_as_boolean_only' => $passwordConfigured,
            'connect_test_status' => $connectTest['status'],
            'connect_test_message' => $connectTest['message'],
            'ttl_test_status' => $ttlTest['status'],
            'ttl_test_message' => $ttlTest['message'],
            'lock_test_status' => $lockTest['status'],
            'lock_test_message' => $lockTest['message'],
            'healthcheck_prefix' => $healthcheckPrefix,
            'warnings' => $warnings,
            'recommendations' => $this->recommendations($cacheStore, $sessionDriver),
        ];
    }

    private function clientAvailable(): bool
    {
        return extension_loaded('redis') || class_exists(Client::class);
    }

    private function configured(mixed $value): bool
    {
        return is_string($value) ? trim($value) !== '' : $value !== null;
    }

    /**
     * @return array{status: string, message: string}
     */
    private function runOrSkip(bool $clientAvailable, bool $connectionConfigured, bool $shouldRun, callable $fn): array
    {
        if (! $clientAvailable) {
            return ['status' => 'unavailable', 'message' => 'Redis client library not installed (ext-redis/predis); skipped safely.'];
        }

        if (! $connectionConfigured) {
            return ['status' => 'unavailable', 'message' => 'Redis connection is not configured; skipped safely.'];
        }

        if (! $shouldRun) {
            return ['status' => 'skipped', 'message' => 'Not requested — pass the matching command option to run it.'];
        }

        return $fn();
    }

    /**
     * @return array{status: string, message: string}
     */
    private function ping(string $connection): array
    {
        try {
            $pong = Redis::connection($connection)->command('ping');

            return ['status' => 'passed', 'message' => 'Read-only PING succeeded ('.$this->safeScalar($pong).').'];
        } catch (Throwable $e) {
            return ['status' => 'failed', 'message' => 'PING failed: '.$this->safeError($e)];
        }
    }

    /**
     * @return array{status: string, message: string}
     */
    private function ttlHealthcheck(string $connection, string $prefix, int $ttlSeconds): array
    {
        $key = $prefix.':ttl:'.Str::uuid()->toString();
        $value = 'cache-1-'.now()->timestamp;

        try {
            $redis = Redis::connection($connection);
            $redis->set($key, $value, 'EX', $ttlSeconds);
            $readBack = $redis->get($key);
            $redis->del($key);

            if ($readBack !== $value) {
                return ['status' => 'failed', 'message' => 'TTL healthcheck value mismatch on read-back.'];
            }

            return ['status' => 'passed', 'message' => "SET/GET/DEL healthcheck succeeded on key {$key} (TTL {$ttlSeconds}s)."];
        } catch (Throwable $e) {
            return ['status' => 'failed', 'message' => 'TTL healthcheck failed: '.$this->safeError($e)];
        }
    }

    /**
     * @return array{status: string, message: string}
     */
    private function lockHealthcheck(string $connection, string $prefix, int $ttlSeconds): array
    {
        $key = $prefix.':lock:'.Str::uuid()->toString();
        $token = Str::uuid()->toString();

        try {
            $redis = Redis::connection($connection);
            $acquired = $redis->set($key, $token, 'EX', $ttlSeconds, 'NX');

            if (! $acquired) {
                return ['status' => 'failed', 'message' => 'Lock healthcheck could not acquire a fresh unique lock key.'];
            }

            $heldValue = $redis->get($key);
            if ($heldValue === $token) {
                $redis->del($key);
            }

            if ($heldValue !== $token) {
                return ['status' => 'failed', 'message' => 'Lock healthcheck token mismatch before release.'];
            }

            return ['status' => 'passed', 'message' => "Lock acquire/release healthcheck succeeded on key {$key} (TTL {$ttlSeconds}s)."];
        } catch (Throwable $e) {
            return ['status' => 'failed', 'message' => 'Lock healthcheck failed: '.$this->safeError($e)];
        }
    }

    private function safeScalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : gettype($value);
    }

    private function safeError(Throwable $e): string
    {
        return str($e->getMessage())
            ->replaceMatches('/password[=:][^\s;,]*/i', 'password=[masked]')
            ->replaceMatches('/(REDIS_PASSWORD)=\S+/i', '$1=[masked]')
            ->limit(240)
            ->toString();
    }

    /**
     * @return list<string>
     */
    private function recommendations(string $cacheStore, string $sessionDriver): array
    {
        return [
            'Keep single-VPS cache/session configuration until a dedicated Redis service is provisioned and load-tested.',
            'Use explicit, separate Redis logical databases (or connections) for cache vs. session — never share a database with ad-hoc app data.',
            'Use short healthcheck TTLs and a controlled key prefix; never run FLUSHDB/FLUSHALL against a shared Redis instance.',
            'Enable Redis cache/session in a separate sprint with an explicit canary and rollback plan.',
            'Monitor Redis latency and memory before routing production cache/session traffic to it.',
            $cacheStore === 'redis' || $sessionDriver === 'redis'
                ? 'Redis is already the active cache/session backend — verify invalidation and failure-mode behavior before scaling further.'
                : 'No production behavior changes yet — cache and session remain on their current drivers.',
        ];
    }
}
