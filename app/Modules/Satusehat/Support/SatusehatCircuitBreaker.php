<?php

namespace App\Modules\Satusehat\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Cache-backed circuit breaker for the SATUSEHAT outbound path, scoped per
 * environment. After `threshold` consecutive hard failures the breaker OPENS
 * for `cooldown_seconds`; the first attempt after cooldown is a HALF-OPEN probe
 * that CLOSES the breaker on success or re-opens it on failure.
 *
 * It never touches candidate/submission source data — it only gates outbound
 * requests. State is advisory; callers ask {@see allowsRequest()} before a call.
 */
final class SatusehatCircuitBreaker
{
    private const STATE_CLOSED = 'closed';

    private const STATE_OPEN = 'open';

    private const STATE_HALF_OPEN = 'half_open';

    public function __construct(private readonly string $environment) {}

    public function allowsRequest(): bool
    {
        return $this->state() !== self::STATE_OPEN;
    }

    public function state(): string
    {
        $failures = (int) Cache::get($this->failureKey(), 0);
        $openUntil = (int) Cache::get($this->openUntilKey(), 0);
        $now = now()->getTimestamp();

        if ($openUntil > $now) {
            return self::STATE_OPEN;
        }

        // Cooldown elapsed but failures still recorded → allow a single probe.
        if ($openUntil > 0 && $openUntil <= $now) {
            return self::STATE_HALF_OPEN;
        }

        return $failures > 0 ? self::STATE_HALF_OPEN : self::STATE_CLOSED;
    }

    public function recordSuccess(): void
    {
        Cache::forget($this->failureKey());
        Cache::forget($this->openUntilKey());
    }

    public function recordFailure(): void
    {
        $threshold = max(1, (int) config('satusehat.circuit_breaker.threshold', 5));
        $cooldown = max(1, (int) config('satusehat.circuit_breaker.cooldown_seconds', 300));

        $failures = (int) Cache::get($this->failureKey(), 0) + 1;
        Cache::put($this->failureKey(), $failures, now()->addSeconds($cooldown * 2));

        if ($failures >= $threshold) {
            Cache::put($this->openUntilKey(), now()->addSeconds($cooldown)->getTimestamp(), now()->addSeconds($cooldown * 2));
        }
    }

    /**
     * Manual reset — used only by an authorized operator command, and audited by
     * the caller.
     */
    public function reset(): void
    {
        $this->recordSuccess();
    }

    private function failureKey(): string
    {
        return "satusehat:cb:{$this->environment}:failures";
    }

    private function openUntilKey(): string
    {
        return "satusehat:cb:{$this->environment}:open_until";
    }
}
