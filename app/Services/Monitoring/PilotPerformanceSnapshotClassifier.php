<?php

namespace App\Services\Monitoring;

class PilotPerformanceSnapshotClassifier
{
    public const STATUS_OK = 'OK';

    public const STATUS_WATCH = 'WATCH';

    public const STATUS_INVESTIGATE = 'INVESTIGATE';

    public const STATUS_FIX = 'FIX';

    /** @var array<string, int> */
    private const SEVERITY = [
        self::STATUS_OK => 0,
        self::STATUS_WATCH => 1,
        self::STATUS_INVESTIGATE => 2,
        self::STATUS_FIX => 3,
    ];

    public static function worst(string ...$statuses): string
    {
        $worst = self::STATUS_OK;

        foreach ($statuses as $status) {
            if ((self::SEVERITY[$status] ?? 0) > (self::SEVERITY[$worst] ?? 0)) {
                $worst = $status;
            }
        }

        return $worst;
    }

    public static function exitCodeForStatus(string $status): int
    {
        return match ($status) {
            self::STATUS_OK => 0,
            self::STATUS_WATCH => 1,
            self::STATUS_INVESTIGATE => 2,
            self::STATUS_FIX => 3,
            default => 10,
        };
    }

    public static function classifySqlRuntimeMs(?float $runtimeMs): string
    {
        if ($runtimeMs === null) {
            return self::STATUS_OK;
        }

        if ($runtimeMs < 100) {
            return self::STATUS_OK;
        }

        if ($runtimeMs < 500) {
            return self::STATUS_WATCH;
        }

        if ($runtimeMs <= 1000) {
            return self::STATUS_INVESTIGATE;
        }

        return self::STATUS_FIX;
    }

    public static function classifyHttpTimingMs(?float $avgMs, ?float $p95Ms = null): string
    {
        $timingStatus = self::STATUS_OK;

        if ($avgMs !== null) {
            if ($avgMs > 1000 || ($p95Ms !== null && $p95Ms > 1000)) {
                $timingStatus = self::STATUS_FIX;
            } elseif ($avgMs > 500 || ($p95Ms !== null && $p95Ms > 500)) {
                $timingStatus = self::STATUS_INVESTIGATE;
            } elseif ($avgMs > 300) {
                $timingStatus = self::STATUS_WATCH;
            } elseif ($avgMs > 100) {
                $timingStatus = self::STATUS_WATCH;
            }
        }

        return $timingStatus;
    }

    /**
     * @param  array{code?:int|null,error?:string|null,avg_ms?:float|null}  $check
     */
    public static function classifyHttpCheck(array $check): string
    {
        $code = $check['code'] ?? null;
        $error = $check['error'] ?? null;
        $avgMs = $check['avg_ms'] ?? null;

        if ($error !== null) {
            return self::STATUS_INVESTIGATE;
        }

        if ($code === null) {
            return self::STATUS_WATCH;
        }

        if ($code >= 500) {
            return self::STATUS_FIX;
        }

        if ($code >= 400) {
            return self::STATUS_INVESTIGATE;
        }

        $expected = in_array($code, [200, 302], true);

        $status = $expected ? self::STATUS_OK : self::STATUS_WATCH;

        return self::worst($status, self::classifyHttpTimingMs($avgMs));
    }

    public static function classifyDiskFreeGb(?float $freeGb): string
    {
        if ($freeGb === null) {
            return self::STATUS_WATCH;
        }

        if ($freeGb < 10) {
            return self::STATUS_FIX;
        }

        if ($freeGb < 20) {
            return self::STATUS_WATCH;
        }

        return self::STATUS_OK;
    }

    public static function classifyPaymentCount(int $count, string $sqlStatus = self::STATUS_OK, string $httpStatus = self::STATUS_OK): string
    {
        if ($count < 10_000) {
            return self::STATUS_OK;
        }

        if ($count < 1_000_000) {
            return self::STATUS_WATCH;
        }

        return self::worst(self::STATUS_WATCH, $sqlStatus, $httpStatus);
    }

    public static function classifyDebugMode(bool $debug, string $environment): string
    {
        if ($debug && $environment === 'pilot') {
            return self::STATUS_WATCH;
        }

        return self::STATUS_OK;
    }

    public static function classifyMaintenanceMode(bool $maintenance): string
    {
        return $maintenance ? self::STATUS_INVESTIGATE : self::STATUS_OK;
    }

    public static function classifyLongRunningQueries(int $over30s, int $over5m): string
    {
        if ($over5m > 0) {
            return self::STATUS_FIX;
        }

        if ($over30s > 0) {
            return self::STATUS_WATCH;
        }

        return self::STATUS_OK;
    }
}
