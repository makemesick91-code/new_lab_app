<?php

use App\Services\Monitoring\PilotPerformanceSnapshotClassifier;
use App\Services\Monitoring\PilotPerformanceSnapshotLogAnalyzer;
use App\Services\Monitoring\PilotPerformanceSnapshotService;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * MONITORING-LOGS-WATCH-ROOT-CAUSE-1 — the logs half of the snapshot aggregate.
 *
 * The `logs` section is the only one that answers "is the application healthy" from
 * evidence it has to go and read. Every other section measures something it can
 * observe directly. That makes it the one section that can report OK having observed
 * nothing at all, and these tests exist to make that impossible to reintroduce.
 *
 * The invariant, stated once:
 *
 *   The logs section may report OK only when it actually read the log and actually
 *   determined that no error event falls inside the lookback window. Absence of
 *   evidence is never evidence of absence.
 *
 * This is the `logs` counterpart of rule 112 R4, which pins the same property for the
 * disk half of the aggregate.
 *
 * Every test pins the disk probe and skips DB/HTTP, so the logs section is the only
 * thing that can move `overall_status` (rule 112 R3).
 */
uses(TestCase::class);

/**
 * Collect a snapshot whose only live section is `logs`.
 */
function pilotSnapshotForLog(?string $logPath): array
{
    return (new PilotPerformanceSnapshotService(diskProbe: pilotSnapshotDiskProbe(100.0)))->collect([
        'skip_db' => true,
        'skip_http' => true,
        'since' => '24h',
        'log_path' => $logPath,
    ]);
}

function pilotSnapshotForLogContents(string $contents): array
{
    $logPath = tempnam(sys_get_temp_dir(), 'pilot-log-source-');
    file_put_contents($logPath, $contents);

    $snapshot = pilotSnapshotForLog($logPath);

    @unlink($logPath);

    return $snapshot;
}

/**
 * Classify an analyzed tail exactly the way the service does, so a drift in the
 * argument list is caught here rather than in production.
 *
 * @param  array<string, mixed>  $metrics
 * @return array{status:string, reason:string}
 */
function pilotClassifyLogMetrics(array $metrics): array
{
    return PilotPerformanceSnapshotClassifier::classifyFreshLogErrors(
        $metrics['fresh_error_like_count'],
        $metrics['critical_fresh_count'],
        $metrics['timestamp_parse_status'],
        $metrics['orphan_unparseable_error_like_count'],
        $metrics['historical_tail_error_like_count'],
        $metrics['historical_stack_trace_line_count'],
        $metrics['undated_error_like_count'],
    );
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-08-22 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

/*
|--------------------------------------------------------------------------
| No false green — an error the monitor cannot age is never OK
|--------------------------------------------------------------------------
*/

it('never reports OK for an error event whose timestamp cannot be parsed', function (string $header) {
    $metrics = (new PilotPerformanceSnapshotLogAnalyzer)->analyzeTail($header, '24h', 86400, now());

    // Before this sprint the event matched the header shape, failed Carbon::parse, and
    // was then counted in no bucket at all — fresh, historical, orphan and attached were
    // all zero and timestamp_parse_status still said 'ok'. The classifier saw a clean,
    // empty window and returned OK. A real ERROR line produced a green monitor.
    expect($metrics['undated_error_like_count'])->toBe(1)
        ->and($metrics['fresh_error_like_count'])->toBe(0)
        ->and($metrics['historical_tail_error_like_count'])->toBe(0)
        ->and($metrics['timestamp_parse_status'])->not->toBe('ok');

    expect(pilotClassifyLogMetrics($metrics)['status'])
        ->toBe(PilotPerformanceSnapshotClassifier::STATUS_WATCH);
})->with([
    'impossible calendar date' => ['[2026-13-45 99:99:99] pilot.ERROR: unparseable header'],
    'impossible clock time' => ['[2026-08-22 99:00:00] pilot.ERROR: unparseable header'],
]);

it('escalates the aggregate when the log holds an error it cannot age', function () {
    $snapshot = pilotSnapshotForLogContents('[2026-13-45 99:99:99] pilot.ERROR: unparseable header'.PHP_EOL);

    expect($snapshot['sections']['logs']['status'])->toBe('WATCH')
        ->and($snapshot['sections']['logs']['metrics']['undated_error_like_count'])->toBe(1)
        ->and($snapshot['overall_status'])->toBe('WATCH')
        ->and(PilotPerformanceSnapshotClassifier::exitCodeForStatus($snapshot['overall_status']))->toBe(1);
});

it('does not let an unageable event leak its continuation lines onto the next event', function () {
    $tail = implode(PHP_EOL, [
        '[2026-13-45 99:99:99] pilot.ERROR: unparseable header',
        '#0 /var/www/app/Undatable.php(1): App\\Services\\Undatable->run()',
        '[2026-06-01 10:00:00] production.ERROR: a real historical event',
        'Stack trace:',
        '#0 /var/www/app/Real.php(2): App\\Services\\Real->run()',
    ]);

    $metrics = (new PilotPerformanceSnapshotLogAnalyzer)->analyzeTail($tail, '24h', 86400, now());

    // Only the two continuation lines that genuinely belong to the historical event may
    // be attributed to it. The dropped event's frame is discarded with its header rather
    // than inflating the next event's stack trace count.
    expect($metrics['undated_error_like_count'])->toBe(1)
        ->and($metrics['historical_tail_error_like_count'])->toBe(1)
        ->and($metrics['historical_stack_trace_line_count'])->toBe(2)
        ->and($metrics['fresh_stack_trace_line_count'])->toBe(0);
});

/*
|--------------------------------------------------------------------------
| No false green — the monitor must have actually read the log
|--------------------------------------------------------------------------
*/

it('treats an unreadable log file as WATCH and warns, never as OK', function () {
    if (posix_getuid() === 0) {
        $this->markTestSkipped('Running as root: file mode cannot make a file unreadable.');
    }

    $logPath = tempnam(sys_get_temp_dir(), 'pilot-log-unreadable-');
    file_put_contents($logPath, '[2026-08-22 11:00:00] pilot.ERROR: a real fresh error'.PHP_EOL);
    chmod($logPath, 0o000);

    if (is_readable($logPath)) {
        @unlink($logPath);
        $this->markTestSkipped('Filesystem does not enforce the mode; cannot stage an unreadable file.');
    }

    $snapshot = pilotSnapshotForLog($logPath);

    chmod($logPath, 0o600);
    @unlink($logPath);

    // readTail() used to swallow the failed open and return an empty string, which the
    // analyzer could not tell apart from "scanned it, found nothing" — so a log the
    // monitor could not open at all was reported OK, alongside a fabricated non-zero
    // tail_bytes_scanned.
    expect($snapshot['sections']['logs']['status'])->toBe('WATCH')
        ->and($snapshot['sections']['logs']['metrics']['tail_bytes_scanned'])->toBe(0)
        ->and($snapshot['overall_status'])->toBe('WATCH')
        ->and(implode(' | ', $snapshot['warnings']))
        ->toContain('Could not open log source')
        ->toContain('log health was not evaluated');
});

it('treats an absent log source as WATCH, never as OK', function () {
    $snapshot = pilotSnapshotForLog(sys_get_temp_dir().'/pilot-log-definitely-absent-'.uniqid().'.log');

    // MONITORING-LOG-SOURCE-RESILIENCE-1 supersedes the previous contract here.
    //
    // This used to report OK, reasoning that Laravel creates the file on first write so a
    // missing file just means nothing was logged. That reasoning holds for a log that has
    // never existed — and not for the case this sprint is about, where the configured
    // channel moved and the monitor is left staring at a path the application abandoned.
    // Those two are indistinguishable from absence alone, so absence is now scored as what
    // it is: the monitor did not verify anything. A quiet fresh checkout reporting WATCH
    // is a cost worth paying to make a relocated log impossible to miss, and it is honest
    // — Monitoring GO has never meant Monitoring green.
    expect($snapshot['sections']['logs']['status'])->toBe('WATCH')
        ->and($snapshot['sections']['logs']['metrics']['file_exists'])->toBeFalse()
        ->and($snapshot['sections']['logs']['metrics']['source_coverage_complete'])->toBeFalse()
        ->and($snapshot['sections']['logs']['reason'])->toContain('not verified')
        ->and(implode(' | ', $snapshot['warnings']))->toContain('is missing; log health for that source was not verified');
});

it('reports that the scan was truncated so the counts are not read as a full-window total', function () {
    $filler = '[2026-08-22 11:00:00] pilot.INFO: benign padding line to grow the file beyond the tail budget'.PHP_EOL;
    $contents = str_repeat($filler, (int) ceil((2 * 1024 * 1024 + 4096) / strlen($filler)));

    $snapshot = pilotSnapshotForLogContents($contents);
    $metrics = $snapshot['sections']['logs']['metrics'];

    expect($metrics['tail_truncated'])->toBeTrue()
        ->and($metrics['tail_bytes_scanned'])->toBe(2 * 1024 * 1024)
        ->and(collect($snapshot['warnings'])->contains(fn (string $w) => str_contains($w, 'Log scan truncated')))->toBeTrue();
});

it('never reports OK when the truncated scan never reached the start of the window', function () {
    // The confirmed false green. An in-window error sits before the 2 MiB boundary and is
    // pushed out of the scanned tail by later traffic. The scan then finds nothing, and
    // before this sprint answered the 24h question with an unqualified
    // "No fresh error events within lookback window." and status OK.
    $error = '[2026-08-21 16:00:00] pilot.ERROR: SQLSTATE[08006] connection failure during a payment write'.PHP_EOL;
    $filler = '[2026-08-22 11:00:00] pilot.INFO: healthy request padding the file past the tail budget'.PHP_EOL;

    $snapshot = pilotSnapshotForLogContents(
        $error.str_repeat($filler, (int) ceil((2 * 1024 * 1024 + 8192) / strlen($filler)))
    );
    $metrics = $snapshot['sections']['logs']['metrics'];

    expect($metrics['tail_truncated'])->toBeTrue()
        ->and($metrics['window_fully_covered'])->toBeFalse()
        ->and($metrics['fresh_error_like_count'])->toBe(0)   // the error really is unseen
        ->and($snapshot['sections']['logs']['status'])->toBe('WATCH')
        ->and($snapshot['sections']['logs']['reason'])->toContain('did not reach the start')
        ->and($snapshot['overall_status'])->toBe('WATCH');
});

it('still reports OK when a truncated scan did reach back past the cutoff', function () {
    // Negative control for the rule above. Truncation on its own must not raise an alarm,
    // or every host writing more than the budget per day would sit in a permanent WATCH.
    // Here the scanned tail still starts before the cutoff, so the window IS covered.
    $filler = '[2026-08-20 09:00:00] pilot.INFO: old benign traffic, entirely before the cutoff'.PHP_EOL;

    $snapshot = pilotSnapshotForLogContents(
        str_repeat($filler, (int) ceil((2 * 1024 * 1024 + 8192) / strlen($filler)))
    );
    $metrics = $snapshot['sections']['logs']['metrics'];

    expect($metrics['tail_truncated'])->toBeTrue()
        ->and($metrics['window_fully_covered'])->toBeTrue()
        ->and($snapshot['sections']['logs']['status'])->toBe('OK')
        ->and($snapshot['overall_status'])->toBe('OK');
});

it('does not claim truncation for a log that fits inside the tail budget', function () {
    $snapshot = pilotSnapshotForLogContents('[2026-08-22 11:00:00] pilot.INFO: small and complete'.PHP_EOL);

    expect($snapshot['sections']['logs']['metrics']['tail_truncated'])->toBeFalse()
        ->and(collect($snapshot['warnings'])->contains(fn (string $w) => str_contains($w, 'Log scan truncated')))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| No understatement — unparseable noise never masks a real severity
|--------------------------------------------------------------------------
*/

it('keeps the parsed fresh count driving severity when orphan lines are also present', function () {
    // 150 fresh events is FIX by contract. The orphan guard used to return early with a
    // flat WATCH and discard the already-correct count, understating a FIX-level burst
    // to a WATCH precisely when the log was noisiest.
    $status = PilotPerformanceSnapshotClassifier::classifyFreshLogErrors(150, 20, 'partial', 21, 0, 0, 0)['status'];

    expect($status)->toBe(PilotPerformanceSnapshotClassifier::STATUS_FIX);
});

it('still escalates to WATCH when freshness is undetermined and nothing countable was found', function () {
    $status = PilotPerformanceSnapshotClassifier::classifyFreshLogErrors(0, 0, 'partial', 21, 0, 0, 0)['status'];

    expect($status)->toBe(PilotPerformanceSnapshotClassifier::STATUS_WATCH);
});

/*
|--------------------------------------------------------------------------
| The window boundary — recovery is by ageing, never by intervention
|--------------------------------------------------------------------------
*/

it('ages an error out of the lookback window exactly at the boundary', function (int $secondsOld, int $expectedFresh, int $expectedHistorical, string $expectedStatus) {
    $header = '['.now()->copy()->subSeconds($secondsOld)->format('Y-m-d H:i:s').'] pilot.ERROR: boundary probe';

    $metrics = (new PilotPerformanceSnapshotLogAnalyzer)->analyzeTail($header, '24h', 86400, now());

    expect($metrics['fresh_error_like_count'])->toBe($expectedFresh)
        ->and($metrics['historical_tail_error_like_count'])->toBe($expectedHistorical)
        ->and(pilotClassifyLogMetrics($metrics)['status'])->toBe($expectedStatus);
})->with([
    // The cutoff is inclusive: `timestamp >= now - lookback` is fresh.
    'one second inside the window' => [86_399, 1, 0, PilotPerformanceSnapshotClassifier::STATUS_WATCH],
    'exactly on the boundary' => [86_400, 1, 0, PilotPerformanceSnapshotClassifier::STATUS_WATCH],
    'one second outside the window' => [86_401, 0, 1, PilotPerformanceSnapshotClassifier::STATUS_OK],
]);

it('recovers to OK once the only error has aged out, with no intervention', function () {
    $header = '['.now()->copy()->subHours(23)->format('Y-m-d H:i:s').'] pilot.ERROR: will age out'.PHP_EOL;

    $whileFresh = pilotSnapshotForLogContents($header);
    expect($whileFresh['sections']['logs']['status'])->toBe('WATCH')
        ->and($whileFresh['overall_status'])->toBe('WATCH');

    // Same bytes on disk, same file, nothing deleted or rewritten. Only the clock moved.
    Carbon::setTestNow(now()->copy()->addHours(2));

    $afterAgeing = pilotSnapshotForLogContents($header);
    expect($afterAgeing['sections']['logs']['status'])->toBe('OK')
        ->and($afterAgeing['sections']['logs']['metrics']['historical_tail_error_like_count'])->toBe(1)
        ->and($afterAgeing['overall_status'])->toBe('OK');
});

/*
|--------------------------------------------------------------------------
| No false WATCH — a well-formed log is still allowed to be healthy
|--------------------------------------------------------------------------
*/

it('leaves a well-formed log with no fresh errors reporting OK', function () {
    $tail = implode(PHP_EOL, [
        '[2026-06-01 10:00:00] production.ERROR: an old grouped exception',
        'Stack trace:',
        '#0 /var/www/app/Old.php(1): App\\Services\\Old->run()',
        '#1 {main}',
        '[2026-08-22 11:59:00] pilot.INFO: a fresh but benign line',
    ]);

    $metrics = (new PilotPerformanceSnapshotLogAnalyzer)->analyzeTail($tail, '24h', 86400, now());

    expect($metrics['undated_error_like_count'])->toBe(0)
        ->and($metrics['timestamp_parse_status'])->toBe('ok')
        ->and($metrics['fresh_error_like_count'])->toBe(0)
        ->and(pilotClassifyLogMetrics($metrics)['status'])->toBe(PilotPerformanceSnapshotClassifier::STATUS_OK);
});

it('matches the production log shape observed during this sprint', function () {
    // The five fresh events that drove production to WATCH: one infrastructure error,
    // one genuine application error, and three operator-tooling errors. All are real
    // ERROR lines in the application log, all are well-formed, and the correct answer
    // is WATCH — not OK, and not a suppression rule for any of them.
    $tail = implode(PHP_EOL, [
        '[2026-08-21 06:16:25] pilot.ERROR: SQLSTATE[08006] connection to server failed',
        '[2026-08-21 13:07:16] pilot.ERROR: Unable to create a directory at a private storage path.',
        '[2026-08-21 17:01:30] pilot.ERROR: Writing to a tooling config directory is not allowed.',
        '[2026-08-21 17:02:51] pilot.ERROR: PHP Parse error: Syntax error in an interactive shell snippet',
        '[2026-08-22 06:06:51] pilot.ERROR: Writing to a tooling config directory is not allowed.',
    ]);

    $metrics = (new PilotPerformanceSnapshotLogAnalyzer)->analyzeTail(
        $tail,
        '24h',
        86400,
        Carbon::parse('2026-08-22 06:07:35'),
    );

    expect($metrics['fresh_error_like_count'])->toBe(5)
        ->and($metrics['undated_error_like_count'])->toBe(0)
        ->and($metrics['timestamp_parse_status'])->toBe('ok')
        ->and(pilotClassifyLogMetrics($metrics)['status'])->toBe(PilotPerformanceSnapshotClassifier::STATUS_WATCH);
});
