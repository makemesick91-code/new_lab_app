<?php

namespace App\Support\Health;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * ENT-8 — Observability & Health Check Pack.
 *
 * Read-only aggregation of DB/cache/queue/storage/object-storage health plus
 * alerting-threshold readiness. Every value is bounded and non-sensitive: no
 * DB host/credentials, driver internals, PII/KTP/NIK, secrets, or stack traces
 * ever leave this service. Built on the LB-1 /health/lb pattern and consumed by
 * the health endpoints and the foundation:health-check governance command.
 */
class HealthCheckService
{
    public const STATUS_OK = 'ok';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_DOWN = 'down';

    /**
     * Minimal liveness payload — confirms the app booted without touching any
     * dependency. Safe for an unauthenticated, high-frequency scrape.
     *
     * @return array{status: string, service: string, check: string}
     */
    public function liveness(): array
    {
        return [
            'status' => self::STATUS_OK,
            'service' => 'daengtisiams',
            'check' => 'live',
        ];
    }

    /**
     * Aggregated readiness across configured components. The public shape is
     * intentionally tiny: overall status + per-component ok/degraded/down only.
     *
     * @return array{status: string, service: string, check: string, components: array<string, string>}
     */
    public function readiness(): array
    {
        $components = [];
        $overall = self::STATUS_OK;

        foreach ($this->componentDetails() as $name => $detail) {
            $components[$name] = $detail['status'];

            if ($detail['critical'] && $detail['status'] === self::STATUS_DOWN) {
                $overall = self::STATUS_DOWN;
            } elseif ($overall !== self::STATUS_DOWN && $detail['status'] !== self::STATUS_OK) {
                $overall = self::STATUS_DEGRADED;
            }
        }

        return [
            'status' => $overall,
            'service' => 'daengtisiams',
            'check' => 'ready',
            'components' => $components,
        ];
    }

    /**
     * Rich, still non-sensitive component detail for the governance command.
     *
     * @return array<string, array{status: string, critical: bool, detail: string}>
     */
    public function componentDetails(): array
    {
        $configured = (array) config('health_check.components', []);
        $details = [];

        foreach ($configured as $name => $meta) {
            if (($meta['enabled'] ?? false) !== true) {
                continue;
            }

            $probe = $this->probe($name);

            $details[$name] = [
                'status' => $probe['status'],
                'critical' => (bool) ($meta['critical'] ?? false),
                'detail' => $probe['detail'],
            ];
        }

        return $details;
    }

    /**
     * Alerting-threshold readiness snapshot (ENT-8). Non-sensitive counters
     * compared against configured warn/fail thresholds; no external channel.
     *
     * @return array{status: string, external_channel_enabled: bool, metrics: array<string, mixed>, breaches: list<array<string, mixed>>}
     */
    public function alertingReadiness(): array
    {
        $enabled = (bool) config('health_check.alerting.enabled', true);
        $thresholds = (array) config('health_check.alerting.thresholds', []);
        $breaches = [];

        $failedJobs = $this->tableCount('failed_jobs');
        $pendingJobs = $this->tableCount('jobs');
        $storageFreePercent = $this->storageFreePercent();

        $this->evaluate($breaches, 'failed_jobs', $failedJobs, $thresholds['failed_jobs_warn'] ?? null, $thresholds['failed_jobs_fail'] ?? null, 'above');
        $this->evaluate($breaches, 'pending_jobs', $pendingJobs, $thresholds['pending_jobs_warn'] ?? null, $thresholds['pending_jobs_fail'] ?? null, 'above');
        $this->evaluate($breaches, 'storage_free_percent', $storageFreePercent, $thresholds['storage_free_percent_warn'] ?? null, $thresholds['storage_free_percent_fail'] ?? null, 'below');

        $status = self::STATUS_OK;
        foreach ($breaches as $breach) {
            if ($breach['severity'] === 'fail') {
                $status = self::STATUS_DOWN;
                break;
            }
            $status = self::STATUS_DEGRADED;
        }

        return [
            'status' => $enabled ? $status : self::STATUS_OK,
            'external_channel_enabled' => (bool) config('health_check.alerting.external_channel_enabled', false),
            'metrics' => [
                'failed_jobs' => $failedJobs,
                'pending_jobs' => $pendingJobs,
                'storage_free_percent' => $storageFreePercent,
            ],
            'breaches' => $enabled ? $breaches : [],
        ];
    }

    /**
     * @return array{status: string, detail: string}
     */
    private function probe(string $name): array
    {
        try {
            return match ($name) {
                'database' => $this->probeDatabase(),
                'cache' => $this->probeCache(),
                'queue' => $this->probeQueue(),
                'storage' => $this->probeStorage(),
                'object_storage' => $this->probeObjectStorage(),
                default => ['status' => self::STATUS_DEGRADED, 'detail' => 'unknown component'],
            };
        } catch (Throwable) {
            // Never surface the exception message (may carry host/credentials).
            return ['status' => self::STATUS_DOWN, 'detail' => 'probe error'];
        }
    }

    private function probeDatabase(): array
    {
        DB::select('select 1');

        return ['status' => self::STATUS_OK, 'detail' => 'connection ok'];
    }

    private function probeCache(): array
    {
        $key = 'health:ent8:'.Str::random(8);
        Cache::put($key, '1', 5);
        $ok = Cache::get($key) === '1';
        Cache::forget($key);

        return $ok
            ? ['status' => self::STATUS_OK, 'detail' => 'read/write ok']
            : ['status' => self::STATUS_DEGRADED, 'detail' => 'read-back mismatch'];
    }

    private function probeQueue(): array
    {
        // Non-blocking: confirm the queue backing tables are reachable. A large
        // failed-job backlog degrades (but does not down) queue readiness.
        if (! Schema::hasTable('jobs') || ! Schema::hasTable('failed_jobs')) {
            return ['status' => self::STATUS_DEGRADED, 'detail' => 'queue tables missing'];
        }

        $failFail = (int) config('health_check.alerting.thresholds.failed_jobs_fail', 25);
        $failed = (int) $this->tableCount('failed_jobs');

        return $failed >= $failFail
            ? ['status' => self::STATUS_DEGRADED, 'detail' => 'failed-job backlog high']
            : ['status' => self::STATUS_OK, 'detail' => 'reachable'];
    }

    private function probeStorage(): array
    {
        $path = storage_path('app');

        if (! is_dir($path) || ! is_writable($path)) {
            return ['status' => self::STATUS_DOWN, 'detail' => 'storage/app not writable'];
        }

        return ['status' => self::STATUS_OK, 'detail' => 'writable'];
    }

    private function probeObjectStorage(): array
    {
        // STORAGE-1 posture: OFF by default. Reported as ok/not-enabled so it
        // never degrades readiness while disabled.
        return (bool) config('object_storage.enabled', false)
            ? ['status' => self::STATUS_OK, 'detail' => 'enabled']
            : ['status' => self::STATUS_OK, 'detail' => 'disabled (STORAGE-1 default)'];
    }

    private function tableCount(string $table): ?int
    {
        try {
            return Schema::hasTable($table) ? (int) DB::table($table)->count() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function storageFreePercent(): ?int
    {
        try {
            $free = @disk_free_space(base_path());
            $total = @disk_total_space(base_path());

            if ($free === false || $total === false || $total <= 0) {
                return null;
            }

            return (int) floor(($free / $total) * 100);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $breaches
     */
    private function evaluate(array &$breaches, string $metric, ?int $value, ?int $warn, ?int $fail, string $direction): void
    {
        if ($value === null) {
            return;
        }

        $breached = fn (?int $threshold): bool => $threshold !== null
            && ($direction === 'above' ? $value >= $threshold : $value <= $threshold);

        if ($breached($fail)) {
            $breaches[] = ['metric' => $metric, 'value' => $value, 'threshold' => $fail, 'severity' => 'fail'];
        } elseif ($breached($warn)) {
            $breaches[] = ['metric' => $metric, 'value' => $value, 'threshold' => $warn, 'severity' => 'warn'];
        }
    }
}
