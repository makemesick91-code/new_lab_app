<?php

namespace App\Modules\Observability\Services;

use App\Modules\Observability\Repositories\ObservabilityTelemetryRepositoryInterface;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * FEATURE-DEV-CONSOLE-OBSERVABILITY-1 — cache effectiveness, with honest
 * scope labels.
 *
 * TWO DIFFERENT NUMBERS, NEVER CONFLATED:
 *
 *   APPLICATION cache hit rate — counted from Laravel's own CacheHit /
 *   CacheMissed events on the requests this app recorded. It is specific to
 *   DaengtisiaMS, whatever the cache store is.
 *
 *   REDIS SERVER hit rate — keyspace_hits / keyspace_misses from Redis INFO.
 *   That is server-wide: every database and every application sharing the
 *   server contributes. It is never labelled as this application's rate.
 *
 * Redis is probed only when this deployment actually uses it (cache, session
 * or queue driver). Otherwise it is reported NOT_IN_USE — never "healthy" and
 * never "down" — because a server we do not depend on is neither.
 */
class CacheObservabilityService
{
    public const REDIS_CONNECTED = 'CONNECTED';

    public const REDIS_UNAVAILABLE = 'UNAVAILABLE';

    public const REDIS_NOT_IN_USE = 'NOT_IN_USE';

    public function __construct(private readonly ObservabilityTelemetryRepositoryInterface $telemetry) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(CarbonInterface $since): array
    {
        $totals = $this->telemetry->cacheTotalsSince($since);

        return [
            'cache_driver' => (string) config('cache.default'),
            'session_driver' => (string) config('session.driver'),
            'queue_connection' => (string) config('queue.default'),
            'redis_in_use' => $this->redisInUse(),
            'app' => [
                'hits' => $totals['hits'],
                'misses' => $totals['misses'],
                'requests' => $totals['requests'],
                'hit_rate' => self::hitRate($totals['hits'], $totals['misses']),
            ],
            'redis' => $this->redisInfo(),
        ];
    }

    /**
     * hits / (hits + misses) * 100, or NULL when there is nothing to divide —
     * "no lookups" is not "0% effective".
     */
    public static function hitRate(int $hits, int $misses): ?float
    {
        $total = $hits + $misses;

        return $total > 0 ? round($hits / $total * 100, 1) : null;
    }

    public function redisInUse(): bool
    {
        return in_array('redis', [
            (string) config('cache.stores.'.config('cache.default').'.driver'),
            (string) config('session.driver'),
            (string) config('queue.connections.'.config('queue.default').'.driver'),
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function redisInfo(): array
    {
        if (! $this->redisInUse() && ! (bool) config('observability_console.redis.probe_when_unused', false)) {
            return ['status' => self::REDIS_NOT_IN_USE, 'reason' => 'Redis bukan backend cache, session, atau queue pada deployment ini.'];
        }

        try {
            $raw = Redis::connection()->command('info');
        } catch (Throwable $e) {
            // The exception class is enough; its message can name a host.
            return ['status' => self::REDIS_UNAVAILABLE, 'reason' => 'Koneksi Redis gagal ('.class_basename($e).').'];
        }

        $info = $this->flattenInfo($raw);
        $hits = (int) ($info['keyspace_hits'] ?? 0);
        $misses = (int) ($info['keyspace_misses'] ?? 0);

        return [
            'status' => self::REDIS_CONNECTED,
            'reason' => null,
            // Only these allow-listed counters are ever surfaced — never
            // config, never a key name, never anything that names a host.
            'keyspace_hits' => $hits,
            'keyspace_misses' => $misses,
            'hit_rate' => self::hitRate($hits, $misses),
            'used_memory_human' => isset($info['used_memory_human']) ? (string) $info['used_memory_human'] : null,
            'connected_clients' => isset($info['connected_clients']) ? (int) $info['connected_clients'] : null,
            'evicted_keys' => isset($info['evicted_keys']) ? (int) $info['evicted_keys'] : null,
            'expired_keys' => isset($info['expired_keys']) ? (int) $info['expired_keys'] : null,
            'redis_version' => isset($info['redis_version']) ? (string) $info['redis_version'] : null,
        ];
    }

    /**
     * phpredis returns INFO as a flat or sectioned array; predis as sections.
     *
     * @return array<string, mixed>
     */
    private function flattenInfo(mixed $raw): array
    {
        if (is_string($raw)) {
            $parsed = [];
            foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
                if ($line !== '' && $line[0] !== '#' && str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $parsed[$k] = $v;
                }
            }

            return $parsed;
        }

        if (! is_array($raw)) {
            return [];
        }

        $flat = [];
        foreach ($raw as $key => $value) {
            if (is_array($value)) {
                $flat = array_merge($flat, $value);
            } else {
                $flat[$key] = $value;
            }
        }

        return $flat;
    }
}
