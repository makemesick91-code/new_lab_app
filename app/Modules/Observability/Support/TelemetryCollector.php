<?php

namespace App\Modules\Observability\Support;

use App\Models\User;
use Throwable;

/**
 * FEATURE-DEV-CONSOLE-OBSERVABILITY-1 — the per-request in-memory accumulator.
 *
 * Bound as a singleton. It is ACTIVE only between RecordRequestTelemetry's
 * handle() and terminate(), so the query/cache listeners do nothing at all in
 * a queue worker, a scheduler tick or an Artisan command — a long-running
 * worker can never accumulate an unbounded slow-query buffer.
 *
 * The FLUSHING guard is the self-observability fence: while the recorder is
 * writing telemetry, the listeners ignore the queries that writing produces,
 * so recording a request can never record itself.
 */
final class TelemetryCollector
{
    private bool $active = false;

    private bool $flushing = false;

    private int $queryCount = 0;

    private float $dbTimeMs = 0.0;

    private int $cacheHits = 0;

    private int $cacheMisses = 0;

    /** @var list<array{sql: string, time_ms: float, connection: string|null}> */
    private array $slowQueries = [];

    private int $droppedSlowQueries = 0;

    private ?Throwable $exception = null;

    private ?User $loggedOutUser = null;

    public function begin(): void
    {
        $this->active = true;
        $this->flushing = false;
        $this->queryCount = 0;
        $this->dbTimeMs = 0.0;
        $this->cacheHits = 0;
        $this->cacheMisses = 0;
        $this->slowQueries = [];
        $this->droppedSlowQueries = 0;
        $this->exception = null;
        $this->loggedOutUser = null;
    }

    public function end(): void
    {
        $this->active = false;
        $this->flushing = false;
        $this->slowQueries = [];
        $this->exception = null;
        $this->loggedOutUser = null;
    }

    /** True only while an HTTP request is being handled by the recorder. */
    public function isActive(): bool
    {
        return $this->active;
    }

    public function isCollecting(): bool
    {
        return $this->active && ! $this->flushing;
    }

    public function startFlush(): void
    {
        $this->flushing = true;
    }

    public function recordQuery(string $sql, float $timeMs, ?string $connection): void
    {
        if (! $this->isCollecting()) {
            return;
        }

        $this->queryCount++;
        $this->dbTimeMs += $timeMs;

        $threshold = (int) config('observability_console.slow_query.threshold_ms', 500);
        if ($timeMs < $threshold) {
            return;
        }

        if (count($this->slowQueries) >= (int) config('observability_console.slow_query.max_per_request', 20)) {
            $this->droppedSlowQueries++;

            return;
        }

        // The raw SQL text is held only in memory until terminate(), where it
        // is normalized before anything is written. Bindings are never taken.
        $this->slowQueries[] = ['sql' => $sql, 'time_ms' => $timeMs, 'connection' => $connection];
    }

    public function recordCacheHit(): void
    {
        if ($this->isCollecting()) {
            $this->cacheHits++;
        }
    }

    public function recordCacheMiss(): void
    {
        if ($this->isCollecting()) {
            $this->cacheMisses++;
        }
    }

    /**
     * The exception the handler rendered for this request, captured through
     * the handler's respond() hook — the one place every rendered exception
     * passes, including those never reported (404, 403, validation).
     */
    public function noteException(Throwable $exception): void
    {
        if ($this->active) {
            $this->exception = $exception;
        }
    }

    public function noteLogout(User $user): void
    {
        if ($this->active) {
            $this->loggedOutUser = $user;
        }
    }

    public function loggedOutUser(): ?User
    {
        return $this->loggedOutUser;
    }

    public function exception(): ?Throwable
    {
        return $this->exception;
    }

    public function queryCount(): int
    {
        return $this->queryCount;
    }

    public function dbTimeMs(): float
    {
        return $this->dbTimeMs;
    }

    public function cacheHits(): int
    {
        return $this->cacheHits;
    }

    public function cacheMisses(): int
    {
        return $this->cacheMisses;
    }

    /**
     * @return list<array{sql: string, time_ms: float, connection: string|null}>
     */
    public function slowQueries(): array
    {
        return $this->slowQueries;
    }

    public function droppedSlowQueries(): int
    {
        return $this->droppedSlowQueries;
    }
}
