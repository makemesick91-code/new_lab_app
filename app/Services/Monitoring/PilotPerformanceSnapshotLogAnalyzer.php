<?php

namespace App\Services\Monitoring;

use Carbon\Carbon;
use Carbon\CarbonInterface;

class PilotPerformanceSnapshotLogAnalyzer
{
    private const ERROR_PATTERN = '/ERROR|CRITICAL|SQLSTATE|timeout|exception|emergency|fatal/i';

    private const CRITICAL_PATTERN = '/CRITICAL|emergency|fatal/i';

    /** @var array<string, int> */
    private const SINCE_SECONDS = [
        '1h' => 3600,
        '6h' => 21600,
        '12h' => 43200,
        '24h' => 86400,
        '48h' => 172800,
        '7d' => 604800,
    ];

    /**
     * @return array{seconds:int, label:string}|null
     */
    public static function parseSinceDuration(string $since): ?array
    {
        $normalized = strtolower(trim($since));

        if ($normalized === '') {
            return null;
        }

        if (isset(self::SINCE_SECONDS[$normalized])) {
            return [
                'seconds' => self::SINCE_SECONDS[$normalized],
                'label' => $normalized,
            ];
        }

        if (preg_match('/^(\d+)(h|d)$/', $normalized, $matches) === 1) {
            $value = (int) $matches[1];
            $unit = $matches[2];

            if ($value <= 0) {
                return null;
            }

            $seconds = $unit === 'h' ? $value * 3600 : $value * 86400;

            return [
                'seconds' => $seconds,
                'label' => $normalized,
            ];
        }

        return null;
    }

    /**
     * @return array{
     *   lookback_window:string,
     *   fresh_error_like_count:int,
     *   historical_tail_error_like_count:int,
     *   critical_fresh_count:int,
     *   unparseable_error_like_count:int,
     *   timestamped_lines:int,
     *   timestamp_parse_status:string,
     *   oldest_fresh_error_at:?string,
     *   latest_fresh_error_at:?string,
     *   tail_bytes_scanned:int,
     *   file_exists:bool
     * }
     */
    public function analyzeTail(string $tail, string $lookbackWindow, int $lookbackSeconds, CarbonInterface $now): array
    {
        $cutoff = $now->copy()->subSeconds($lookbackSeconds);

        $freshCount = 0;
        $historicalCount = 0;
        $criticalFreshCount = 0;
        $unparseableCount = 0;
        $timestampedLines = 0;
        $oldestFresh = null;
        $latestFresh = null;

        foreach (preg_split('/\R/', $tail) as $line) {
            if ($line === '' || preg_match(self::ERROR_PATTERN, $line) !== 1) {
                continue;
            }

            $timestamp = $this->extractTimestamp($line);

            if ($timestamp === null) {
                $unparseableCount++;

                continue;
            }

            $timestampedLines++;

            if ($timestamp->greaterThanOrEqualTo($cutoff)) {
                $freshCount++;

                if (preg_match(self::CRITICAL_PATTERN, $line) === 1) {
                    $criticalFreshCount++;
                }

                $iso = $timestamp->toIso8601String();

                if ($oldestFresh === null || $timestamp->lt(Carbon::parse($oldestFresh))) {
                    $oldestFresh = $iso;
                }

                if ($latestFresh === null || $timestamp->gt(Carbon::parse($latestFresh))) {
                    $latestFresh = $iso;
                }
            } else {
                $historicalCount++;
            }
        }

        $errorLikeTotal = $freshCount + $historicalCount + $unparseableCount;
        $timestampParseStatus = $this->resolveTimestampParseStatus($errorLikeTotal, $timestampedLines, $unparseableCount);

        return [
            'lookback_window' => $lookbackWindow,
            'fresh_error_like_count' => $freshCount,
            'historical_tail_error_like_count' => $historicalCount,
            'critical_fresh_count' => $criticalFreshCount,
            'unparseable_error_like_count' => $unparseableCount,
            'timestamped_lines' => $timestampedLines,
            'timestamp_parse_status' => $timestampParseStatus,
            'oldest_fresh_error_at' => $oldestFresh,
            'latest_fresh_error_at' => $latestFresh,
            'tail_bytes_scanned' => strlen($tail),
            'file_exists' => true,
        ];
    }

    private function extractTimestamp(string $line): ?CarbonInterface
    {
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}[^\]]*)\]/', $line, $matches) !== 1) {
            return null;
        }

        try {
            return Carbon::parse($matches[1]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveTimestampParseStatus(int $errorLikeTotal, int $timestampedLines, int $unparseableCount): string
    {
        if ($errorLikeTotal === 0) {
            return 'ok';
        }

        if ($timestampedLines === 0 && $unparseableCount > 0) {
            return 'failed';
        }

        if ($unparseableCount > 0) {
            return 'partial';
        }

        return 'ok';
    }
}
