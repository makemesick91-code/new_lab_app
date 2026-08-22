<?php

/**
 * MONITORING-LOG-TIMESTAMP-ROLLOVER-1
 *
 * Pins the timestamp contract across calendar rollovers. Every test freezes the
 * clock — none of them may depend on the real date, midnight, a month end, or a
 * year end, because a suite that only fails in January is not a regression test.
 *
 * The residual these cover: `Carbon::parse()` throws on some impossible
 * timestamps but silently *normalises* others onto a neighbouring calendar date.
 * The normalised value is a plausible instant the log never recorded, so the
 * event used to be aged with false confidence instead of being reported as
 * unageable — in both directions, and the backwards direction is a false green.
 */

use App\Services\Monitoring\PilotPerformanceSnapshotClassifier;
use App\Services\Monitoring\PilotPerformanceSnapshotLogAnalyzer;
use Carbon\Carbon;
use Tests\TestCase;

uses(TestCase::class);

/** Analyse a single-event tail against a frozen clock. */
function rolloverMetrics(string $header, string $frozenNow, int $lookbackSeconds = 86400): array
{
    Carbon::setTestNow(Carbon::parse($frozenNow));

    return (new PilotPerformanceSnapshotLogAnalyzer)->analyzeTail(
        $header,
        '24h',
        $lookbackSeconds,
        now(),
    );
}

// ---------------------------------------------------------------------------
// The defect, in the direction that hides a problem: FALSE GREEN
// ---------------------------------------------------------------------------

it('never lets a rolled-over timestamp bury an error as ordinary history', function (string $header, string $frozenNow, string $why) {
    $metrics = rolloverMetrics("[{$header}] production.ERROR: database connection lost", $frozenNow);

    // The event must land in the unageable bucket, NOT the historical one. Being
    // counted as history is the false green: historical errors contribute nothing
    // to the verdict, so the section would report OK while an error whose real
    // date is unknowable sits in the log.
    expect($metrics['undated_error_like_count'])->toBe(1, $why)
        ->and($metrics['historical_tail_error_like_count'])->toBe(0)
        ->and($metrics['fresh_error_like_count'])->toBe(0)
        ->and($metrics['timestamp_parse_status'])->not->toBe('ok');
})->with([
    'month 00 rolls back into the previous year' => ['2026-00-15 10:00:00', '2026-01-05 12:00:00', '2026-00-15 silently becomes 2025-12-15'],
    'day 00 rolls back into the previous month' => ['2026-08-00 10:00:00', '2026-08-01 12:00:00', '2026-08-00 silently becomes 2026-07-31'],
]);

it('forces the logs verdict to WATCH, never OK, for a rolled-over error', function () {
    $metrics = rolloverMetrics('[2026-00-15 10:00:00] production.ERROR: boom', '2026-01-05 12:00:00');

    $classification = PilotPerformanceSnapshotClassifier::classifyFreshLogErrors(
        $metrics['fresh_error_like_count'],
        $metrics['critical_fresh_count'],
        $metrics['timestamp_parse_status'],
        $metrics['orphan_unparseable_error_like_count'],
        $metrics['historical_tail_error_like_count'],
        $metrics['historical_stack_trace_line_count'],
        $metrics['undated_error_like_count'],
    );

    expect($classification['status'])->toBe(PilotPerformanceSnapshotClassifier::STATUS_WATCH);
});

// ---------------------------------------------------------------------------
// The defect, in the direction that invents a problem: FALSE WATCH
// ---------------------------------------------------------------------------

it('never publishes a fresh error for a moment that never happened', function (string $header, string $frozenNow) {
    $metrics = rolloverMetrics("[{$header}] production.ERROR: stale malformed event", $frozenNow);

    // Rolling forward onto today used to report fresh=1 and publish a
    // latest_fresh_error_at for an instant the log never contained: a WATCH
    // pinned to fabricated evidence, which no operator could ever clear.
    expect($metrics['fresh_error_like_count'])->toBe(0)
        ->and($metrics['latest_fresh_error_at'])->toBeNull()
        ->and($metrics['undated_error_like_count'])->toBe(1);
})->with([
    'february 30th rolls forward onto the run date' => ['2026-02-30 10:00:00', '2026-03-02 12:00:00'],
    'february 29th in a non-leap year rolls forward' => ['2026-02-29 10:00:00', '2026-03-01 12:00:00'],
]);

// ---------------------------------------------------------------------------
// Rollover across day / month / year / leap-day, and the arbitrary-shift class
// ---------------------------------------------------------------------------

it('rejects every timestamp the parser had to change to make legal', function (string $header) {
    $metrics = rolloverMetrics("[{$header}] production.ERROR: boom", '2026-03-02 12:00:00');

    expect($metrics['undated_error_like_count'])->toBe(1)
        ->and($metrics['timestamp_parse_status'])->not->toBe('ok');
})->with([
    'day rollover' => '2026-08-00 10:00:00',
    'month rollover' => '2026-00-15 10:00:00',
    'year rollover (month 00)' => '2026-00-31 23:59:59',
    'leap day in a non-leap year' => '2026-02-29 10:00:00',
    'impossible day' => '2026-08-32 10:00:00',
    'impossible month' => '2026-13-01 10:00:00',
    'impossible hour' => '2026-08-22 25:00:00',
    'impossible minute' => '2026-08-22 10:61:00',
    // A relative modifier in the trailing segment is an unbounded, caller-chosen
    // age shift; it must not be honoured as if it were part of the timestamp.
    'relative modifier back a year' => '2026-08-22 10:00:00 -1 year',
    'relative modifier forward' => '2026-08-22 10:00:00 +5 days',
]);

it('accepts a real leap day', function () {
    $metrics = rolloverMetrics('[2028-02-29 10:00:00] production.ERROR: boom', '2028-02-29 12:00:00');

    expect($metrics['fresh_error_like_count'])->toBe(1)
        ->and($metrics['undated_error_like_count'])->toBe(0)
        ->and($metrics['timestamp_parse_status'])->toBe('ok')
        ->and($metrics['latest_fresh_error_at'])->toBe('2028-02-29T10:00:00+00:00');
});

it('ages an error correctly across a year boundary', function () {
    // A December event, read in January, must stay in December — and stay fresh
    // while it is genuinely inside the window.
    $fresh = rolloverMetrics('[2025-12-31 23:30:00] production.ERROR: boom', '2026-01-01 00:30:00');
    expect($fresh['fresh_error_like_count'])->toBe(1)
        ->and($fresh['latest_fresh_error_at'])->toBe('2025-12-31T23:30:00+00:00');

    $expired = rolloverMetrics('[2025-12-30 10:00:00] production.ERROR: boom', '2026-01-01 00:30:00');
    expect($expired['fresh_error_like_count'])->toBe(0)
        ->and($expired['historical_tail_error_like_count'])->toBe(1)
        ->and($expired['undated_error_like_count'])->toBe(0);
});

// ---------------------------------------------------------------------------
// NEGATIVE CONTROL — the fix must change nothing about well-formed input
// ---------------------------------------------------------------------------

it('leaves every real log timestamp format untouched', function (string $header, int $expectedFresh) {
    $metrics = rolloverMetrics("[{$header}] production.ERROR: boom", '2026-08-22 12:00:00');

    expect($metrics['undated_error_like_count'])->toBe(0)
        ->and($metrics['timestamp_parse_status'])->toBe('ok')
        ->and($metrics['fresh_error_like_count'])->toBe($expectedFresh);
})->with([
    // Laravel's own formatter — LogManager::$dateFormat — and what production writes.
    'laravel Y-m-d H:i:s, fresh' => ['2026-08-22 10:00:00', 1],
    'laravel Y-m-d H:i:s, aged' => ['2026-08-01 10:00:00', 0],
    // Monolog's default NormalizerFormatter::SIMPLE_DATE.
    'monolog ISO-8601 with offset' => ['2026-08-22T10:00:00+00:00', 1],
    'fractional seconds' => ['2026-08-22 10:00:00.123456', 1],
]);

it('still reports a genuinely old error as history rather than as unageable', function () {
    // The counterpart to the false-green test: a VALID old date must keep taking
    // the historical path. If this ever flipped to unageable the fix would be
    // manufacturing WATCH out of healthy logs.
    $metrics = rolloverMetrics('[2025-12-15 10:00:00] production.ERROR: boom', '2026-01-05 12:00:00');

    expect($metrics['historical_tail_error_like_count'])->toBe(1)
        ->and($metrics['undated_error_like_count'])->toBe(0)
        ->and($metrics['timestamp_parse_status'])->toBe('ok');
});

// ---------------------------------------------------------------------------
// The 24-hour window boundary, pinned exactly
// ---------------------------------------------------------------------------

it('treats the lookback window as inclusive of its own start instant', function (int $secondsOld, int $expectedFresh, int $expectedHistorical) {
    $now = Carbon::parse('2026-08-22 12:00:00');
    $header = $now->copy()->subSeconds($secondsOld)->format('Y-m-d H:i:s');

    $metrics = rolloverMetrics("[{$header}] production.ERROR: boundary probe", '2026-08-22 12:00:00');

    expect($metrics['fresh_error_like_count'])->toBe($expectedFresh)
        ->and($metrics['historical_tail_error_like_count'])->toBe($expectedHistorical);
})->with([
    'one second inside the window' => [86399, 1, 0],
    'exactly on the window start' => [86400, 1, 0],
    'one second outside the window' => [86401, 0, 1],
]);

// ---------------------------------------------------------------------------
// Timezone: display is not classification
// ---------------------------------------------------------------------------

it('classifies the same instant identically however it is written', function () {
    // 2026-08-22T18:00:00+08:00 and 2026-08-22T10:00:00+00:00 are the SAME
    // instant. Freshness follows the instant, so the reporting timezone a human
    // happens to read it in can never move an event across the window boundary.
    $wita = rolloverMetrics('[2026-08-22T18:00:00+08:00] production.ERROR: boom', '2026-08-22 12:00:00');
    $utc = rolloverMetrics('[2026-08-22T10:00:00+00:00] production.ERROR: boom', '2026-08-22 12:00:00');

    expect($wita['fresh_error_like_count'])->toBe($utc['fresh_error_like_count'])
        ->and($wita['latest_fresh_error_at'])->toBe('2026-08-22T18:00:00+08:00')
        ->and($utc['latest_fresh_error_at'])->toBe('2026-08-22T10:00:00+00:00')
        ->and(Carbon::parse($wita['latest_fresh_error_at'])->equalTo(Carbon::parse($utc['latest_fresh_error_at'])))->toBeTrue();
});

it('keeps a UTC-day and local-day disagreement from changing freshness', function () {
    // 2026-08-22 17:30 UTC is already 2026-08-23 in Asia/Makassar. The calendar
    // day the reader would print differs from the UTC day; the verdict must not.
    $metrics = rolloverMetrics('[2026-08-22 17:30:00] production.ERROR: boom', '2026-08-22 18:00:00');

    expect($metrics['fresh_error_like_count'])->toBe(1)
        ->and(Carbon::parse($metrics['latest_fresh_error_at'])->setTimezone('Asia/Makassar')->format('Y-m-d'))->toBe('2026-08-23')
        ->and(Carbon::parse($metrics['latest_fresh_error_at'])->setTimezone('UTC')->format('Y-m-d'))->toBe('2026-08-22');
});

// ---------------------------------------------------------------------------
// A future timestamp is suspicious, and must not read as healthy
// ---------------------------------------------------------------------------

it('does not let a future-dated error vanish from the verdict', function () {
    $metrics = rolloverMetrics('[2030-01-01 10:00:00] production.ERROR: clock skew', '2026-08-22 12:00:00');

    // Whatever bucket it lands in, it must remain counted — a future ERROR is
    // evidence of clock or parser corruption and may never read as OK.
    $classification = PilotPerformanceSnapshotClassifier::classifyFreshLogErrors(
        $metrics['fresh_error_like_count'],
        $metrics['critical_fresh_count'],
        $metrics['timestamp_parse_status'],
        $metrics['orphan_unparseable_error_like_count'],
        $metrics['historical_tail_error_like_count'],
        $metrics['historical_stack_trace_line_count'],
        $metrics['undated_error_like_count'],
    );

    expect($classification['status'])->not->toBe(PilotPerformanceSnapshotClassifier::STATUS_OK);
});

// ---------------------------------------------------------------------------
// The rolled event must not corrupt the coverage anchor either
// ---------------------------------------------------------------------------

it('does not let a rolled-over date anchor the scanned-window coverage claim', function () {
    // oldest_scanned_event_at decides whether "no fresh errors" describes the
    // whole window or only the scanned part. A fabricated 2025 date must never
    // be allowed to widen that claim.
    $metrics = rolloverMetrics('[2026-00-15 10:00:00] production.ERROR: boom', '2026-01-05 12:00:00');

    expect($metrics['oldest_scanned_event_at'])->toBeNull()
        ->and($metrics['timestamped_lines'])->toBe(0);
});
