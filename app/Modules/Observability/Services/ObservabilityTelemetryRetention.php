<?php

namespace App\Modules\Observability\Services;

use App\Modules\Observability\Repositories\ObservabilityTelemetryRepositoryInterface;
use Carbon\CarbonInterface;
use Throwable;

/**
 * FEATURE-DEV-CONSOLE-OBSERVABILITY-1 — bounded retention.
 *
 * Production's Laravel scheduler is registered but never fires, so a
 * schedule-only prune would silently never run. Pruning therefore happens as
 * a LOTTERY on recorded requests (the session-GC pattern) after the response
 * is sent, and one pass deletes at most `batch_size` rows per class — the
 * prune can never become the slow request it bounds. `observability:prune`
 * runs the same code on demand and is idempotent.
 */
class ObservabilityTelemetryRetention
{
    public function __construct(private readonly ObservabilityTelemetryRepositoryInterface $telemetry) {}

    /** @return array<string, CarbonInterface> */
    public function cutoffs(): array
    {
        $retention = (array) config('observability_console.retention', []);
        $now = now();

        return [
            'access' => $now->copy()->subHours(max(1, (int) ($retention['access_hours'] ?? 72))),
            'error' => $now->copy()->subHours(max(1, (int) ($retention['error_hours'] ?? 720))),
            'slow_request' => $now->copy()->subHours(max(1, (int) ($retention['slow_request_hours'] ?? 336))),
            'slow_query' => $now->copy()->subHours(max(1, (int) ($retention['slow_query_hours'] ?? 336))),
        ];
    }

    /** @return array<string, int> */
    public function prune(?int $batch = null): array
    {
        $batch ??= (int) config('observability_console.retention.batch_size', 1000);

        return $this->telemetry->prune($this->cutoffs(), max(1, $batch));
    }

    public function maybePrune(): void
    {
        [$chances, $outOf] = array_pad((array) config('observability_console.retention.lottery', [1, 200]), 2, 200);

        if ((int) $outOf < 1 || random_int(1, (int) $outOf) > (int) $chances) {
            return;
        }

        try {
            $this->prune();
        } catch (Throwable) {
            // Retention is best-effort per request; the command is the backstop.
        }
    }
}
