<?php

use App\Services\Monitoring\PilotPerformanceSnapshotClassifier;
use App\Services\Monitoring\PilotPerformanceSnapshotLogAnalyzer;
use App\Services\Monitoring\PilotPerformanceSnapshotService;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * MONITORING-UNDATED-SEVERITY-ESCALATION-1 — how much severity unageable ERROR
 * evidence is worth.
 *
 * Rule 113 R2 settled that an ERROR whose timestamp cannot be trusted may never
 * read as OK, and rule 115 R9 pinned the mechanism: `undated_error_like_count > 0`
 * forces `freshnessUndetermined`, and OK becomes unreachable. Both statements are
 * about a *floor*. Neither says anything about a ceiling, and none of the higher
 * rungs of the severity ladder were ever extended to the undated bucket.
 *
 * So the ladder was truncated after its first step. `undated = 1` and
 * `undated = 1000` returned the same WATCH, and the identical burst of 150 ERROR
 * events reported FIX when its timestamps parsed and WATCH when they did not —
 * two severity levels of operational confidence bought by nothing but corruption
 * in the date field.
 *
 * The correction rests on one measured fact, pinned below by
 * `it('counts an undated error event in the same unit as a fresh one')`:
 * `undated_error_like_count` and `fresh_error_like_count` are produced by the same
 * event-grouping pass and carry the same unit — one error-like log event. They
 * differ only in whether the header timestamp could be trusted, never in whether
 * an error happened. Since the monitor cannot rule out that an unageable event
 * falls inside the window, the fail-closed reading is that it does, and the
 * canonical in-window ladder (1 → WATCH, >20 → INVESTIGATE, >100 → FIX) is the
 * ladder that applies. That ladder's first rung was already applied to undated
 * evidence; this sprint supplies the rest of it.
 *
 * What this must NOT do, and what the negative controls below exist to prove:
 *
 *  - it must not fire on a healthy, well-formed log (rule 113 R3 — a false WATCH
 *    is a defect too, and a false FIX more so);
 *  - it must not reach severity by loosening the timestamp parser, which would
 *    reverse MONITORING-LOG-TIMESTAMP-ROLLOVER-1 (rule 115 R2);
 *  - it must not mask the parsed fresh count, which stays the floor (rule 113 R4);
 *  - it must not downgrade a coverage or source failure that already fails closed.
 */
uses(TestCase::class);

/**
 * Classify with the exact argument order the service uses, so an argument-list
 * drift is caught here rather than in production.
 */
function undatedSeverityStatus(
    int $fresh,
    int $undated,
    string $parseStatus = 'ok',
    int $orphan = 0,
    bool $windowFullyCovered = true,
    int $criticalFresh = 0,
    int $historical = 0,
): string {
    return PilotPerformanceSnapshotClassifier::classifyFreshLogErrors(
        $fresh,
        $criticalFresh,
        $parseStatus,
        $orphan,
        $historical,
        0,
        $undated,
        $windowFullyCovered,
    )['status'];
}

function undatedSeverityReason(int $fresh, int $undated, string $parseStatus = 'partial'): string
{
    return PilotPerformanceSnapshotClassifier::classifyFreshLogErrors(
        $fresh, 0, $parseStatus, 0, 0, 0, $undated, true,
    )['reason'];
}

/** A tail of `$n` well-formed Laravel ERROR events all carrying `$date`. */
function undatedSeverityTail(int $n, string $date): string
{
    $tail = '';

    for ($i = 0; $i < $n; $i++) {
        $tail .= sprintf("[%s] production.ERROR: SQLSTATE[08006] connection failure #%d\n", $date, $i);
    }

    return $tail;
}

function undatedSeverityMetrics(string $tail, string $now = '2026-08-23 12:00:00'): array
{
    return (new PilotPerformanceSnapshotLogAnalyzer)
        ->analyzeTail($tail, '24h', 86400, Carbon::parse($now));
}

function undatedSeveritySnapshot(string $contents): array
{
    $logPath = tempnam(sys_get_temp_dir(), 'pilot-undated-severity-');
    file_put_contents($logPath, $contents);

    $snapshot = (new PilotPerformanceSnapshotService(diskProbe: pilotSnapshotDiskProbe(100.0)))->collect([
        'skip_db' => true,
        'skip_http' => true,
        'since' => '24h',
        'log_path' => $logPath,
    ]);

    @unlink($logPath);

    return $snapshot;
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-23 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| The measured premise: undated and fresh are the same unit of evidence
|--------------------------------------------------------------------------
*/

it('counts an undated error event in the same unit as a fresh one', function () {
    // The whole contract rests on this. If these two buckets ever stop being
    // one-event-per-count, applying the fresh ladder to `undated` stops being
    // conservative and starts being arbitrary — so it is measured, not assumed.
    foreach ([1, 20, 21, 150] as $n) {
        $valid = undatedSeverityMetrics(undatedSeverityTail($n, '2026-08-23 11:00:00'));
        $corrupt = undatedSeverityMetrics(undatedSeverityTail($n, '2026-02-30 11:00:00'));

        expect($valid['fresh_error_like_count'])->toBe($n, "n={$n} valid-dated events")
            ->and($valid['undated_error_like_count'])->toBe(0)
            ->and($corrupt['undated_error_like_count'])->toBe($n, "n={$n} corrupt-dated events")
            ->and($corrupt['fresh_error_like_count'])->toBe(0);
    }
});

it('does not double count an undated event into any other bucket', function () {
    // Severity is about to be driven by fresh + undated. If an undated event also
    // landed in another bucket the sum would over-count it and inflate severity.
    $tail = '';

    for ($i = 0; $i < 10; $i++) {
        $tail .= "[2026-02-30 11:00:00] production.ERROR: boom #{$i}\n"
            ."Stack trace:\n#0 /app/x.php(1): boom()\n{main}\n";
    }

    $metrics = undatedSeverityMetrics($tail);

    expect($metrics['undated_error_like_count'])->toBe(10)
        ->and($metrics['fresh_error_like_count'])->toBe(0)
        ->and($metrics['historical_tail_error_like_count'])->toBe(0)
        ->and($metrics['orphan_unparseable_error_like_count'])->toBe(0)
        ->and($metrics['attached_unparseable_line_count'])->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The closed defect: undated evidence carries weight, not just presence
|--------------------------------------------------------------------------
*/

it('escalates undated error evidence across the canonical ladder', function () {
    // Before this sprint every one of these returned WATCH.
    expect(undatedSeverityStatus(0, 0))->toBe('OK')
        ->and(undatedSeverityStatus(0, 1, 'partial'))->toBe('WATCH')
        ->and(undatedSeverityStatus(0, 19, 'partial'))->toBe('WATCH')
        ->and(undatedSeverityStatus(0, 20, 'partial'))->toBe('WATCH')
        ->and(undatedSeverityStatus(0, 21, 'partial'))->toBe('INVESTIGATE')
        ->and(undatedSeverityStatus(0, 100, 'partial'))->toBe('INVESTIGATE')
        ->and(undatedSeverityStatus(0, 101, 'partial'))->toBe('FIX')
        ->and(undatedSeverityStatus(0, 150, 'partial'))->toBe('FIX');
});

it('gives an identical burst the same severity whether or not its timestamps parsed', function () {
    // The asymmetry this sprint closed, stated as the property it violated: the
    // severity of an error burst may not depend on the integrity of its date field.
    foreach ([1, 20, 21, 150] as $n) {
        expect(undatedSeverityStatus(0, $n, 'partial'))
            ->toBe(undatedSeverityStatus($n, 0), "burst of {$n} events");
    }
});

it('combines fresh and undated evidence onto one ladder', function () {
    // Independent worst-of would report WATCH for 15 + 15 — up to 30 in-window
    // error events understated to the lowest non-clean rung, which is rule 113 R4's
    // failure mode relocated to a different boundary.
    expect(undatedSeverityStatus(5, 5, 'partial'))->toBe('WATCH')
        ->and(undatedSeverityStatus(15, 15, 'partial'))->toBe('INVESTIGATE')
        ->and(undatedSeverityStatus(20, 1, 'partial'))->toBe('INVESTIGATE')
        ->and(undatedSeverityStatus(15, 100, 'partial'))->toBe('FIX')
        ->and(undatedSeverityStatus(1, 150, 'partial'))->toBe('FIX')
        ->and(undatedSeverityStatus(25, 100, 'partial'))->toBe('FIX');
});

it('never lowers severity when adverse evidence is added', function () {
    // Monotonicity, checked exhaustively over the grid rather than at hand-picked
    // points: more evidence must never buy a calmer verdict, on either axis.
    $rank = ['OK' => 0, 'WATCH' => 1, 'INVESTIGATE' => 2, 'FIX' => 3];
    $counts = [0, 1, 5, 20, 21, 50, 100, 101, 150];

    foreach ($counts as $fresh) {
        foreach ($counts as $undated) {
            $base = $rank[undatedSeverityStatus($fresh, $undated, 'partial')];

            foreach ($counts as $moreFresh) {
                if ($moreFresh < $fresh) {
                    continue;
                }

                expect($rank[undatedSeverityStatus($moreFresh, $undated, 'partial')])
                    ->toBeGreaterThanOrEqual($base, "fresh {$fresh}->{$moreFresh} at undated={$undated}");
            }

            foreach ($counts as $moreUndated) {
                if ($moreUndated < $undated) {
                    continue;
                }

                expect($rank[undatedSeverityStatus($fresh, $moreUndated, 'partial')])
                    ->toBeGreaterThanOrEqual($base, "undated {$undated}->{$moreUndated} at fresh={$fresh}");
            }
        }
    }
});

it('names untrusted timestamps as the driver so an operator can act on the verdict', function () {
    expect(undatedSeverityReason(0, 150))->toContain('unparseable timestamps')
        ->and(undatedSeverityReason(0, 150))->not->toContain('SQLSTATE')
        ->and(undatedSeverityReason(0, 21))->toContain('could not be aged');
});

/*
|--------------------------------------------------------------------------
| Negative controls — rule 113 R3: a false WATCH is a defect too
|--------------------------------------------------------------------------
*/

it('leaves a healthy well-formed log at OK', function () {
    $snapshot = undatedSeveritySnapshot(
        "[2026-08-23 11:00:00] production.INFO: request handled\n"
        ."[2026-08-23 11:30:00] production.DEBUG: cache warm\n"
    );

    expect($snapshot['sections']['logs']['status'])->toBe('OK')
        ->and($snapshot['sections']['logs']['metrics']['undated_error_like_count'])->toBe(0)
        ->and($snapshot['overall_status'])->toBe('OK');
});

it('does not treat a non-error event with a corrupt timestamp as adverse evidence', function () {
    // Escalation keys on ERROR-like evidence, not on malformed text. An INFO line
    // with an impossible date is a parser observation, not an incident.
    $metrics = undatedSeverityMetrics(
        str_repeat("[2026-02-30 11:00:00] production.INFO: routine\n", 150)
    );

    expect($metrics['undated_error_like_count'])->toBe(0)
        ->and(undatedSeverityStatus(0, $metrics['undated_error_like_count']))->toBe('OK');
});

it('keeps a single undated error at WATCH', function () {
    // The rung rule 115 R9 pinned. Escalation adds rungs above it; it does not
    // move this one, so an isolated corrupt line cannot become an incident.
    $snapshot = undatedSeveritySnapshot("[2026-00-15 10:00:00] production.ERROR: boom\n");

    expect($snapshot['sections']['logs']['metrics']['undated_error_like_count'])->toBe(1)
        ->and($snapshot['sections']['logs']['status'])->toBe('WATCH');
});

/*
|--------------------------------------------------------------------------
| Preserved foundations
|--------------------------------------------------------------------------
*/

it('keeps the parsed fresh count driving severity when noise is present', function () {
    // Rule 113 R4, unchanged: 150 fresh events are FIX regardless of orphan noise.
    expect(undatedSeverityStatus(150, 0, 'partial', 21, true, 20))->toBe('FIX')
        ->and(undatedSeverityStatus(5, 0))->toBe('WATCH')
        ->and(undatedSeverityStatus(25, 0))->toBe('INVESTIGATE')
        ->and(undatedSeverityStatus(120, 0))->toBe('FIX')
        ->and(undatedSeverityStatus(2, 0, 'ok', 0, true, 10))->toBe('FIX');
});

it('still escalates to WATCH when freshness is undetermined and nothing was countable', function () {
    // Orphan noise with no countable event is still an unknown, not a finding.
    expect(undatedSeverityStatus(0, 0, 'partial', 21))->toBe('WATCH')
        ->and(undatedSeverityReason(0, 0, 'partial'))->not->toContain('unparseable timestamps');
});

it('does not let undated escalation downgrade a coverage or source failure', function () {
    // Coverage failures fail closed on their own. Adding undated evidence may only
    // raise the verdict, never relax it.
    expect(undatedSeverityStatus(0, 0, 'ok', 0, false))->toBe('WATCH')
        ->and(undatedSeverityStatus(0, 1, 'partial', 0, false))->toBe('WATCH')
        ->and(undatedSeverityStatus(0, 150, 'failed', 0, false))->toBe('FIX')
        ->and(undatedSeverityStatus(0, 21, 'failed', 0, false))->toBe('INVESTIGATE');
});

it('keeps timestamp faithfulness intact rather than parsing its way out of severity', function () {
    // Rule 115 R2: severity may never be reduced by making the parser accept a date
    // it had to invent. Each of these must remain unageable.
    foreach (['2026-02-30', '2026-08-00', '2026-00-15', '2026-13-01'] as $badDate) {
        $metrics = undatedSeverityMetrics("[{$badDate} 10:00:00] production.ERROR: boom\n");

        expect($metrics['undated_error_like_count'])->toBe(1, "date {$badDate}")
            ->and($metrics['fresh_error_like_count'])->toBe(0)
            ->and($metrics['historical_tail_error_like_count'])->toBe(0);
    }

    // And a well-formed timestamp still ages normally.
    $good = undatedSeverityMetrics("[2026-08-23 11:00:00] production.ERROR: boom\n");

    expect($good['undated_error_like_count'])->toBe(0)
        ->and($good['fresh_error_like_count'])->toBe(1);
});

it('introduces no cheaper path to a severe verdict than already exists', function () {
    // Alert-DoS check. Anyone able to write N corrupt-dated ERROR headers into the
    // log can write N well-formed ones just as easily, and the well-formed path
    // already reached FIX before this sprint. Escalation therefore adds no new
    // amplification: the undated verdict is never worse than the valid-dated one.
    $rank = ['OK' => 0, 'WATCH' => 1, 'INVESTIGATE' => 2, 'FIX' => 3];

    foreach ([1, 21, 101, 150, 1000] as $n) {
        expect($rank[undatedSeverityStatus(0, $n, 'partial')])
            ->toBeLessThanOrEqual($rank[undatedSeverityStatus($n, 0)], "forged volume {$n}");
    }
});

/*
|--------------------------------------------------------------------------
| End to end through the real service
|--------------------------------------------------------------------------
*/

it('reports the escalated verdict end to end from a real log file', function () {
    $snapshot = undatedSeveritySnapshot(undatedSeverityTail(150, '2026-02-30 11:00:00'));
    $logs = $snapshot['sections']['logs'];

    expect($logs['metrics']['undated_error_like_count'])->toBe(150)
        ->and($logs['metrics']['fresh_error_like_count'])->toBe(0)
        ->and($logs['status'])->toBe('FIX')
        ->and($logs['reason'])->toContain('unparseable timestamps')
        ->and($snapshot['overall_status'])->toBe('FIX');
});

it('keeps the undated count visible in the payload after classification', function () {
    // The classified verdict must not replace the evidence it was derived from.
    $snapshot = undatedSeveritySnapshot(undatedSeverityTail(30, '2026-02-30 11:00:00'));

    expect($snapshot['sections']['logs']['metrics'])
        ->toHaveKeys(['fresh_error_like_count', 'undated_error_like_count', 'timestamp_parse_status'])
        ->and($snapshot['sections']['logs']['metrics']['undated_error_like_count'])->toBe(30)
        ->and($snapshot['sections']['logs']['status'])->toBe('INVESTIGATE');
});

/*
|--------------------------------------------------------------------------
| Reason accuracy and input robustness (adversarial review follow-ups)
|--------------------------------------------------------------------------
*/

it('does not report confirmed fresh errors as a pure timestamp problem', function () {
    // A mix is not a corruption story. Saying "freshness is unknown" over 5 events
    // that were positively confirmed inside the window would send an operator after
    // the date writer while a real error burst goes unread.
    $mixed = undatedSeverityReason(5, 1);

    expect($mixed)->not->toContain('freshness is unknown')
        ->and($mixed)->toContain('Fresh error events detected within lookback window')
        ->and($mixed)->toContain('could not be aged');

    expect(undatedSeverityReason(15, 15))->toContain('exceed INVESTIGATE threshold')
        ->and(undatedSeverityReason(15, 15))->toContain('could not be aged')
        ->and(undatedSeverityReason(60, 60))->toContain('exceed FIX threshold')
        ->and(undatedSeverityReason(60, 60))->toContain('could not be aged');
});

it('says all rather than some when every event is undated', function () {
    expect(undatedSeverityReason(0, 150))->toContain('all carry unparseable timestamps')
        ->and(undatedSeverityReason(0, 21))->toContain('all carry unparseable timestamps')
        ->and(undatedSeverityReason(0, 1))->toContain('freshness is unknown');
});

it('keeps the fresh-only reason wording byte identical', function () {
    // A verdict untouched by undated evidence must read exactly as it always has.
    expect(undatedSeverityReason(5, 0, 'ok'))->toBe('Fresh error events detected within lookback window.')
        ->and(undatedSeverityReason(25, 0, 'ok'))->toBe('Fresh error events exceed INVESTIGATE threshold within lookback window.')
        ->and(undatedSeverityReason(120, 0, 'ok'))->toBe('Fresh error events exceed FIX threshold within lookback window.');
});

it('carries no log content into the reason', function () {
    // Reasons are static literals. Nothing read out of the log may reach an alert.
    foreach ([[0, 1], [0, 150], [5, 5], [150, 150], [150, 0]] as [$f, $u]) {
        $reason = undatedSeverityReason($f, $u);

        expect($reason)->not->toContain('SQLSTATE')
            ->and($reason)->not->toContain('production.ERROR')
            ->and($reason)->not->toContain('/app/');
    }
});

it('fails closed on a malformed negative count instead of cancelling it out', function () {
    // Not reachable from the analyzer, whose counters only ever increment from zero.
    // But summing two variables loses the accidental immunity the single-variable
    // zero test had, and a cancelling pair must not read as "no evidence".
    expect(undatedSeverityStatus(1, -1, 'partial'))->toBe('WATCH')
        ->and(undatedSeverityStatus(-1, 1, 'partial'))->toBe('WATCH')
        ->and(undatedSeverityStatus(-100, 100, 'partial', 0, true, 10))->toBe('FIX')
        ->and(undatedSeverityStatus(-5, 0))->toBe('OK')
        ->and(undatedSeverityStatus(0, -5))->toBe('OK');
});
