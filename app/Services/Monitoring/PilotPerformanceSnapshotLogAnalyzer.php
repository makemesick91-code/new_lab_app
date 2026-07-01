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
     *   fresh_stack_trace_line_count:int,
     *   historical_stack_trace_line_count:int,
     *   orphan_unparseable_error_like_count:int,
     *   attached_unparseable_line_count:int,
     *   timestamped_lines:int,
     *   timestamp_parse_status:string,
     *   log_grouping_status:string,
     *   oldest_fresh_error_at:?string,
     *   latest_fresh_error_at:?string,
     *   latest_historical_error_at:?string,
     *   tail_bytes_scanned:int,
     *   file_exists:bool
     * }
     */
    public function analyzeTail(string $tail, string $lookbackWindow, int $lookbackSeconds, CarbonInterface $now): array
    {
        $cutoff = $now->copy()->subSeconds($lookbackSeconds);

        $freshEventCount = 0;
        $historicalEventCount = 0;
        $criticalFreshCount = 0;
        $freshStackTraceCount = 0;
        $historicalStackTraceCount = 0;
        $orphanUnparseableCount = 0;
        $attachedUnparseableCount = 0;
        $timestampedLines = 0;
        $oldestFresh = null;
        $latestFresh = null;
        $latestHistorical = null;

        $currentHeader = null;
        /** @var list<string> */
        $currentContinuations = [];

        $lines = preg_split('/\R/', $tail) ?: [];

        $flushEvent = function () use (
            &$currentHeader,
            &$currentContinuations,
            &$freshEventCount,
            &$historicalEventCount,
            &$criticalFreshCount,
            &$freshStackTraceCount,
            &$historicalStackTraceCount,
            &$attachedUnparseableCount,
            &$timestampedLines,
            &$oldestFresh,
            &$latestFresh,
            &$latestHistorical,
            $cutoff,
        ): void {
            if ($currentHeader === null) {
                return;
            }

            $timestamp = $this->extractTimestamp($currentHeader);

            if ($timestamp === null) {
                return;
            }

            $timestampedLines++;

            if (! $this->isErrorLike($currentHeader)) {
                $currentHeader = null;
                $currentContinuations = [];

                return;
            }

            $isFresh = $timestamp->greaterThanOrEqualTo($cutoff);
            $iso = $timestamp->toIso8601String();

            if ($isFresh) {
                $freshEventCount++;

                if (preg_match(self::CRITICAL_PATTERN, $currentHeader) === 1) {
                    $criticalFreshCount++;
                }

                if ($oldestFresh === null || $timestamp->lt(Carbon::parse($oldestFresh))) {
                    $oldestFresh = $iso;
                }

                if ($latestFresh === null || $timestamp->gt(Carbon::parse($latestFresh))) {
                    $latestFresh = $iso;
                }
            } else {
                $historicalEventCount++;

                if ($latestHistorical === null || $timestamp->gt(Carbon::parse($latestHistorical))) {
                    $latestHistorical = $iso;
                }
            }

            foreach ($currentContinuations as $continuation) {
                if ($this->isStackTraceContinuation($continuation)) {
                    if ($isFresh) {
                        $freshStackTraceCount++;
                    } else {
                        $historicalStackTraceCount++;
                    }

                    continue;
                }

                if ($this->isErrorLike($continuation)) {
                    $attachedUnparseableCount++;
                }
            }

            $currentHeader = null;
            $currentContinuations = [];
        };

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if ($this->isTimestampedHeader($line)) {
                $flushEvent();
                $currentHeader = $line;
                $currentContinuations = [];

                continue;
            }

            if ($currentHeader !== null) {
                $currentContinuations[] = $line;

                continue;
            }

            if ($this->isStackTraceContinuation($line) || $this->isErrorLike($line)) {
                $orphanUnparseableCount++;
            }
        }

        $flushEvent();

        $errorLikeTotal = $freshEventCount + $historicalEventCount + $orphanUnparseableCount + $attachedUnparseableCount;
        $timestampParseStatus = $this->resolveTimestampParseStatus($errorLikeTotal, $timestampedLines, $orphanUnparseableCount);
        $logGroupingStatus = $this->resolveLogGroupingStatus(
            $freshStackTraceCount + $historicalStackTraceCount,
            $attachedUnparseableCount,
            $orphanUnparseableCount,
        );

        return [
            'lookback_window' => $lookbackWindow,
            'fresh_error_like_count' => $freshEventCount,
            'historical_tail_error_like_count' => $historicalEventCount,
            'critical_fresh_count' => $criticalFreshCount,
            'unparseable_error_like_count' => $orphanUnparseableCount,
            'fresh_stack_trace_line_count' => $freshStackTraceCount,
            'historical_stack_trace_line_count' => $historicalStackTraceCount,
            'orphan_unparseable_error_like_count' => $orphanUnparseableCount,
            'attached_unparseable_line_count' => $attachedUnparseableCount,
            'timestamped_lines' => $timestampedLines,
            'timestamp_parse_status' => $timestampParseStatus,
            'log_grouping_status' => $logGroupingStatus,
            'oldest_fresh_error_at' => $oldestFresh,
            'latest_fresh_error_at' => $latestFresh,
            'latest_historical_error_at' => $latestHistorical,
            'tail_bytes_scanned' => strlen($tail),
            'file_exists' => true,
        ];
    }

    private function isTimestampedHeader(string $line): bool
    {
        return preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}[^\]]*)\]/', $line) === 1;
    }

    private function isErrorLike(string $line): bool
    {
        return preg_match(self::ERROR_PATTERN, $line) === 1;
    }

    private function isStackTraceContinuation(string $line): bool
    {
        $trimmed = trim($line);

        if ($trimmed === '{main}') {
            return true;
        }

        if (stripos($line, 'Stack trace:') !== false) {
            return true;
        }

        if (stripos($line, 'thrown in') !== false) {
            return true;
        }

        if (preg_match('/^#\d+\s/', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/^\s+#\d+\s/', $line) === 1) {
            return true;
        }

        return false;
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

    private function resolveTimestampParseStatus(int $errorLikeTotal, int $timestampedLines, int $orphanUnparseableCount): string
    {
        if ($errorLikeTotal === 0) {
            return 'ok';
        }

        if ($timestampedLines === 0 && $orphanUnparseableCount > 0) {
            return 'failed';
        }

        if ($orphanUnparseableCount > 0) {
            return 'partial';
        }

        return 'ok';
    }

    private function resolveLogGroupingStatus(int $stackTraceLines, int $attachedLines, int $orphanLines): string
    {
        $groupedLines = $stackTraceLines + $attachedLines;

        if ($groupedLines > 0 && $orphanLines === 0) {
            return 'grouped';
        }

        if ($groupedLines > 0 && $orphanLines > 0) {
            return 'partial';
        }

        return 'none';
    }
}
