<?php

namespace App\Modules\Observability\Support;

/**
 * FEATURE-DEV-CONSOLE-OBSERVABILITY-1 — request latency vocabulary.
 *
 * Thresholds live in config/observability_console.php `latency`; this class
 * owns only the names and the mapping, so a threshold is never repeated.
 */
final class LatencyCategory
{
    public const NORMAL = 'NORMAL';

    public const WATCH = 'WATCH';

    public const SLOW = 'SLOW';

    public const VERY_SLOW = 'VERY_SLOW';

    public const TIMEOUT = 'TIMEOUT';

    public const ALL = [self::NORMAL, self::WATCH, self::SLOW, self::VERY_SLOW, self::TIMEOUT];

    public static function forDuration(int $durationMs): string
    {
        $latency = (array) config('observability_console.latency', []);

        return match (true) {
            $durationMs >= (int) ($latency['very_slow_ms'] ?? 3000) => self::VERY_SLOW,
            $durationMs >= (int) ($latency['slow_ms'] ?? 1000) => self::SLOW,
            $durationMs >= (int) ($latency['watch_ms'] ?? 500) => self::WATCH,
            default => self::NORMAL,
        };
    }

    /** A request is "slow" for the console from WATCH upwards. */
    public static function isSlow(string $category): bool
    {
        return $category !== self::NORMAL;
    }

    public static function slowQuerySeverity(int $durationMs): string
    {
        return $durationMs >= (int) config('observability_console.slow_query.critical_ms', 2000)
            ? 'CRITICAL'
            : 'SLOW';
    }
}
