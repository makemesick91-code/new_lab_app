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
     *   undated_error_like_count:int,
     *   timestamped_lines:int,
     *   timestamp_parse_status:string,
     *   log_grouping_status:string,
     *   oldest_fresh_error_at:?string,
     *   latest_fresh_error_at:?string,
     *   latest_historical_error_at:?string,
     *   oldest_scanned_event_at:?string,
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
        $undatedErrorLikeCount = 0;
        $timestampedLines = 0;
        $oldestFresh = null;
        $latestFresh = null;
        $latestHistorical = null;
        $oldestScannedEvent = null;

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
            &$undatedErrorLikeCount,
            &$timestampedLines,
            &$oldestFresh,
            &$latestFresh,
            &$latestHistorical,
            &$oldestScannedEvent,
            $cutoff,
        ): void {
            if ($currentHeader === null) {
                return;
            }

            $timestamp = $this->extractTimestamp($currentHeader);

            if ($timestamp === null) {
                // The line matched the event-header shape but its timestamp could not be
                // parsed, so this event cannot be aged against the lookback window. It is
                // counted explicitly rather than dropped: an error event whose freshness
                // is unknown must never be silently discarded, and must never be able to
                // leave the logs section reporting OK.
                if ($this->isErrorLike($currentHeader)) {
                    $undatedErrorLikeCount++;
                }

                // Reset the grouping state so the orphaned continuation lines cannot leak
                // into the next event and be attributed to the wrong timestamp.
                $currentHeader = null;
                $currentContinuations = [];

                return;
            }

            $timestampedLines++;

            // Track the oldest event of ANY level that the scan actually reached. This is
            // how the caller decides whether the scanned tail reached back past the
            // lookback cutoff, i.e. whether "no fresh errors" is a statement about the
            // whole window or only about the part that fit inside the byte budget.
            if ($oldestScannedEvent === null || $timestamp->lt(Carbon::parse($oldestScannedEvent))) {
                $oldestScannedEvent = $timestamp->toIso8601String();
            }

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

        $errorLikeTotal = $freshEventCount + $historicalEventCount + $orphanUnparseableCount + $attachedUnparseableCount + $undatedErrorLikeCount;
        $timestampParseStatus = $this->resolveTimestampParseStatus($errorLikeTotal, $timestampedLines, $orphanUnparseableCount, $undatedErrorLikeCount);
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
            'undated_error_like_count' => $undatedErrorLikeCount,
            'timestamped_lines' => $timestampedLines,
            'timestamp_parse_status' => $timestampParseStatus,
            'log_grouping_status' => $logGroupingStatus,
            'oldest_fresh_error_at' => $oldestFresh,
            'latest_fresh_error_at' => $latestFresh,
            'latest_historical_error_at' => $latestHistorical,
            'oldest_scanned_event_at' => $oldestScannedEvent,
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

    /**
     * MONITORING-LOG-TIMESTAMP-ROLLOVER-1 — reject dates the parser had to invent.
     *
     * `Carbon::parse()` is permissive: it throws on some impossible timestamps
     * (month 13, day 32, hour 25) but silently *normalises* others by rolling them
     * onto a neighbouring calendar date — `2026-02-30` becomes `2026-03-02`,
     * `2026-08-00` becomes `2026-07-31`, and `2026-00-15` rolls back into the
     * previous year as `2025-12-15`. The rolled value is a plausible instant that
     * the log never recorded, so the event gets aged against the lookback window
     * with false confidence instead of being reported as unageable.
     *
     * That confidence is the bug, in both directions. A corrupt header that rolls
     * backwards out of the window is counted as an ordinary historical event and
     * stops contributing to the logs verdict at all — a false green, and precisely
     * the "an unageable ERROR must never disappear into logs=OK" guarantee this
     * monitor is built on. A corrupt header that rolls forwards onto today is
     * counted as fresh and publishes a `latest_fresh_error_at` for a moment that
     * never happened — a false WATCH pinned to fabricated evidence.
     *
     * The check is therefore a faithfulness test, not a stricter grammar: parse as
     * before, then require the result to reproduce the exact calendar digits that
     * were written in the line. Anything that round-trips is accepted unchanged,
     * which keeps every real format working — Laravel's `Y-m-d H:i:s`
     * (`LogManager::$dateFormat`), Monolog's ISO-8601-with-offset default,
     * fractional seconds, and explicit offsets all pass untouched. Only a value
     * the parser had to *change* to make legal is rejected, and it is rejected
     * into the existing null/unageable path, which already fails closed.
     */
    private function extractTimestamp(string $line): ?CarbonInterface
    {
        if (preg_match('/^\[((\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})[^\]]*)\]/', $line, $matches) !== 1) {
            return null;
        }

        try {
            $timestamp = Carbon::parse($matches[1]);
        } catch (\Throwable) {
            return null;
        }

        // The digits exactly as the log wrote them. Compared against the parsed
        // value in its own timezone, so an explicit offset is still honoured while
        // a normalised (rolled) date is caught.
        $literal = sprintf(
            '%s-%s-%s %s:%s:%s',
            $matches[2],
            $matches[3],
            $matches[4],
            $matches[5],
            $matches[6],
            $matches[7]
        );

        if ($timestamp->format('Y-m-d H:i:s') !== $literal) {
            return null;
        }

        return $timestamp;
    }

    private function resolveTimestampParseStatus(int $errorLikeTotal, int $timestampedLines, int $orphanUnparseableCount, int $undatedErrorLikeCount = 0): string
    {
        // An error event whose own header timestamp failed to parse means freshness was
        // not fully determinable, so the parse status may never be reported as 'ok'.
        if ($undatedErrorLikeCount > 0) {
            return $timestampedLines === 0 ? 'failed' : 'partial';
        }

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
