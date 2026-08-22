<?php

use App\Services\Monitoring\MonitoringLogScanCoverage;
use App\Services\Monitoring\PilotPerformanceSnapshotService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * MONITORING-LOG-COVERAGE-ANCHOR-INJECTION-1 — log content may not prove log coverage.
 *
 * The three preceding Monitoring sprints closed, in order: a logs section that could
 * report OK having read nothing; a monitor that read a path the application had
 * abandoned; and a timestamp parser that let Carbon invent a date the log never wrote.
 * Each left the same question standing — how does the monitor know it looked at
 * everything it needed to look at?
 *
 * Until this sprint the answer came from the log itself. A source larger than the scan
 * budget is read tail-only, and coverage was granted when the oldest event timestamp
 * inside that tail already sat before the lookback cutoff — reasoning that the skipped
 * prefix must be older still. That assumed a strictly chronological file, and it handed
 * the decision to content. A single line beginning `[2019-01-01 00:00:00]` anywhere in
 * the scanned tail bought coverage for megabytes nobody opened, and a real in-window
 * ERROR sitting in that unread prefix was reported as
 * "No fresh error events within lookback window."
 *
 * The invariant, stated once:
 *
 *   Coverage is a fact about the read, not about what the read contained. An event
 *   cannot testify about bytes nobody looked at.
 *
 * Every test pins the disk probe and skips DB/HTTP, so the logs section is the only
 * thing that can move `overall_status` (rule 112 R3).
 */
uses(TestCase::class);

beforeEach(function () {
    // Cutoff for a 24h window is therefore 2026-08-21 12:00:00.
    Carbon::setTestNow(Carbon::parse('2026-08-22 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

/** An in-window ERROR: one hour old, squarely inside the 24h window. */
function anchorFreshError(string $note = 'SQLSTATE[08006] payment write failed'): string
{
    return '[2026-08-22 11:00:00] pilot.ERROR: '.$note.PHP_EOL;
}

/** Grow $line until the file exceeds the scan budget by $overBytes. */
function anchorPadTo(string $line, int $overBytes = 8192, int $budget = 2097152): string
{
    return str_repeat($line, (int) ceil(($budget + $overBytes) / strlen($line)));
}

/** @return array<string, mixed> the logs section plus overall status */
function anchorSnapshot(string $contents): array
{
    $logPath = tempnam(sys_get_temp_dir(), 'coverage-anchor-');
    file_put_contents($logPath, $contents);

    $snapshot = (new PilotPerformanceSnapshotService(diskProbe: pilotSnapshotDiskProbe(100.0)))->collect([
        'skip_db' => true,
        'skip_http' => true,
        'since' => '24h',
        'log_path' => $logPath,
    ]);

    @unlink($logPath);

    return [
        'metrics' => $snapshot['sections']['logs']['metrics'],
        'status' => $snapshot['sections']['logs']['status'],
        'reason' => $snapshot['sections']['logs']['reason'],
        'overall' => $snapshot['overall_status'],
        'warnings' => implode(' | ', $snapshot['warnings']),
    ];
}

/*
|--------------------------------------------------------------------------
| The anchor itself
|--------------------------------------------------------------------------
*/

it('refuses coverage when an ancient benign tail is the only thing suggesting the window was reached', function () {
    // The reproducer. A real in-window ERROR sits in the unscanned prefix; every scanned
    // byte is a valid, benign, ancient event. Before this sprint: window_fully_covered
    // true, status OK, reason "No fresh error events within lookback window." — while the
    // error was never read.
    $result = anchorSnapshot(
        anchorFreshError().anchorPadTo('[2026-08-20 09:00:00] pilot.INFO: benign ancient padding'.PHP_EOL)
    );

    expect($result['metrics']['tail_truncated'])->toBeTrue()
        ->and($result['metrics']['fresh_error_like_count'])->toBe(0)   // the error really is unseen
        ->and($result['metrics']['window_fully_covered'])->toBeFalse()
        ->and($result['metrics']['source_coverage_complete'])->toBeFalse()
        ->and($result['status'])->toBe('WATCH')
        ->and($result['overall'])->toBe('WATCH')
        ->and($result['reason'])->toContain('did not reach the start')
        // The ancient anchor is still there and still reported. It simply decides nothing.
        ->and($result['metrics']['oldest_scanned_event_at'])->toStartWith('2026-08-20');
});

it('reaches the same coverage verdict whether or not the ancient anchor is present', function () {
    // The differential that makes content-independence a property rather than a claim:
    // two files whose scanned tails differ only in how old their events look.
    $hidden = anchorFreshError();

    $withAnchor = anchorSnapshot($hidden.anchorPadTo('[2026-08-20 09:00:00] pilot.INFO: ancient padding'.PHP_EOL));
    $withoutAnchor = anchorSnapshot($hidden.anchorPadTo('[2026-08-22 11:30:00] pilot.INFO: recent padding'.PHP_EOL));

    expect($withAnchor['metrics']['window_fully_covered'])
        ->toBe($withoutAnchor['metrics']['window_fully_covered'])
        ->and($withAnchor['status'])->toBe($withoutAnchor['status'])
        ->and($withAnchor['metrics']['window_fully_covered'])->toBeFalse()
        // ...and the two really did observe different content, so this is a live comparison.
        ->and($withAnchor['metrics']['oldest_scanned_event_at'])
        ->not->toBe($withoutAnchor['metrics']['oldest_scanned_event_at']);
});

it('cannot be flipped to covered by one injected ancient header among otherwise fresh traffic', function () {
    // The injection shape. Laravel writes multi-line messages, so a single newline
    // followed by `[<old date>]` inside any logged string forges an event header. One
    // forged line used to be the whole exploit.
    $body = anchorPadTo('[2026-08-22 11:30:00] pilot.INFO: recent padding'.PHP_EOL);
    $body .= '[2019-01-01 00:00:00] pilot.INFO: text that arrived from somewhere else'.PHP_EOL;

    $result = anchorSnapshot(anchorFreshError().$body);

    expect($result['metrics']['oldest_scanned_event_at'])->toStartWith('2019-01-01')
        ->and($result['metrics']['window_fully_covered'])->toBeFalse()
        ->and($result['status'])->toBe('WATCH');
});

it('says which bytes it did not read, so the WATCH is actionable rather than mysterious', function () {
    $result = anchorSnapshot(
        anchorFreshError().anchorPadTo('[2026-08-20 09:00:00] pilot.INFO: ancient padding'.PHP_EOL)
    );

    expect($result['metrics']['tail_bytes_skipped'])->toBeGreaterThan(0)
        ->and($result['metrics']['source_bytes_total'])->toBeGreaterThan($result['metrics']['tail_bytes_scanned'])
        ->and($result['warnings'])->toContain('were never read')
        ->and($result['warnings'])->toContain('foundation_monitoring.log_scan.max_source_bytes');
});

/*
|--------------------------------------------------------------------------
| Content matrix — no class of line may buy coverage
|--------------------------------------------------------------------------
*/

it('ignores the class of the scanned content when deciding coverage', function (string $label, string $line) {
    $result = anchorSnapshot(anchorFreshError().anchorPadTo($line));

    expect($result['metrics']['window_fully_covered'])->toBeFalse()
        ->and($result['status'])->not->toBe('OK');
})->with([
    ['ancient INFO', '[2026-08-20 09:00:00] pilot.INFO: ancient benign'.PHP_EOL],
    ['ancient DEBUG', '[2026-08-20 09:00:00] pilot.DEBUG: ancient debug'.PHP_EOL],
    ['ancient WARNING', '[2026-08-20 09:00:00] pilot.WARNING: ancient warning'.PHP_EOL],
    ['ancient ERROR', '[2026-08-20 09:00:00] pilot.ERROR: ancient error, outside window'.PHP_EOL],
    ['fresh INFO', '[2026-08-22 11:30:00] pilot.INFO: fresh benign'.PHP_EOL],
    ['epoch-old header', '[1970-01-01 00:00:00] pilot.INFO: as old as it gets'.PHP_EOL],
    ['unparseable date', '[2026-02-30 09:00:00] pilot.INFO: a date that does not exist'.PHP_EOL],
    ['no header at all', 'plain padding with no event header whatsoever'.PHP_EOL],
]);

it('is unaffected by where in the scanned tail the ancient anchor sits', function (string $label, string $body) {
    $result = anchorSnapshot(anchorFreshError().$body);

    expect($result['metrics']['window_fully_covered'])->toBeFalse()
        ->and($result['status'])->not->toBe('OK');
})->with(function () {
    $ancient = '[2019-01-01 00:00:00] pilot.INFO: anchor'.PHP_EOL;
    $fresh = '[2026-08-22 11:30:00] pilot.INFO: recent padding'.PHP_EOL;
    $pad = anchorPadTo($fresh);

    return [
        ['anchor first', $ancient.$pad],
        ['anchor last', $pad.$ancient],
        ['anchor in the middle', substr($pad, 0, 1048576).$ancient.substr($pad, 1048576)],
        ['many anchors', str_repeat($ancient, 500).$pad],
        ['anchor repeated at both ends', $ancient.$pad.$ancient],
    ];
});

/*
|--------------------------------------------------------------------------
| The coverage type may not be able to see content at all
|--------------------------------------------------------------------------
*/

it('gives the coverage decision no way to accept a timestamp', function () {
    // The mutation control. Behaviour can be re-broken by someone reintroducing a content
    // term; this fails the moment the type is given a way to look at one.
    $class = new ReflectionClass(MonitoringLogScanCoverage::class);

    foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        foreach ($method->getParameters() as $parameter) {
            expect((string) $parameter->getType())->toBe('int',
                "MonitoringLogScanCoverage::{$method->getName()} accepts a non-int parameter");
        }
    }

    expect($class->getMethod('isComplete')->getNumberOfParameters())->toBe(0)
        ->and(file_get_contents((string) $class->getFileName()))
        ->not->toContain('Carbon')
        ->not->toContain('timestamp_at');
});

it('reports a source covered only when the read began at byte zero', function () {
    expect(MonitoringLogScanCoverage::fromRead(4096, 0, 4096)->isComplete())->toBeTrue()
        ->and(MonitoringLogScanCoverage::fromRead(4096, 1, 4095)->isComplete())->toBeFalse()
        ->and(MonitoringLogScanCoverage::fromRead(4096, 1, 4095)->skippedBytes())->toBe(1)
        // An empty source has no bytes that could be hiding anything.
        ->and(MonitoringLogScanCoverage::empty()->isComplete())->toBeTrue()
        // Negative inputs cannot manufacture a complete read.
        ->and(MonitoringLogScanCoverage::fromRead(-1, -5, -5)->isComplete())->toBeTrue()
        ->and(MonitoringLogScanCoverage::fromRead(10, 10, 0)->isTruncated())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| File size boundaries
|--------------------------------------------------------------------------
*/

it('decides coverage at every boundary of the scan budget', function (string $label, int $overBudgetBy, bool $expectCovered) {
    $line = '[2026-08-20 09:00:00] pilot.INFO: ancient benign padding'.PHP_EOL;
    $target = 2097152 + $overBudgetBy;
    $contents = substr(str_repeat($line, (int) ceil(max($target, strlen($line)) / strlen($line))), 0, max($target, 0));

    $result = anchorSnapshot($contents);

    expect($result['metrics']['window_fully_covered'])->toBe($expectCovered)
        ->and($result['status'])->toBe($expectCovered ? 'OK' : 'WATCH');
})->with([
    ['far below the budget', -2000000, true],
    ['just below the budget', -1, true],
    ['exactly the budget', 0, true],
    ['one byte over', 1, false],
    ['far over the budget', 1048576, false],
]);

/*
|--------------------------------------------------------------------------
| The verdicts that must still be reachable
|--------------------------------------------------------------------------
*/

it('still reports OK for a fully read source with no fresh errors', function () {
    // Without this the sprint would have produced a monitor that can never go green.
    $result = anchorSnapshot(
        '[2026-08-20 09:00:00] pilot.INFO: old benign traffic'.PHP_EOL
        .'[2026-08-22 11:00:00] pilot.INFO: recent benign traffic'.PHP_EOL
    );

    expect($result['metrics']['window_fully_covered'])->toBeTrue()
        ->and($result['metrics']['tail_bytes_skipped'])->toBe(0)
        ->and($result['status'])->toBe('OK')
        ->and($result['overall'])->toBe('OK');
});

it('still reports WATCH for a fully read source carrying one fresh error', function () {
    $result = anchorSnapshot(anchorFreshError());

    expect($result['metrics']['window_fully_covered'])->toBeTrue()
        ->and($result['metrics']['fresh_error_like_count'])->toBe(1)
        ->and($result['status'])->toBe('WATCH');
});

it('still escalates a fully read source carrying 150 fresh errors', function () {
    // Severity must keep coming from the counted events. A coverage change that quietly
    // deduplicated or truncated before classification would show up here.
    $burst = '';

    for ($i = 0; $i < 150; $i++) {
        $burst .= sprintf('[2026-08-22 11:%02d:00] pilot.ERROR: failure number %d'.PHP_EOL, $i % 60, $i);
    }

    $result = anchorSnapshot($burst);

    expect($result['metrics']['window_fully_covered'])->toBeTrue()
        ->and($result['metrics']['fresh_error_like_count'])->toBe(150)
        ->and($result['status'])->toBe('FIX');
});

it('withdraws the coverage claim rather than reporting a clean partial scan', function () {
    // Partial coverage with nothing visibly wrong is the exact shape a false green takes.
    $result = anchorSnapshot(anchorPadTo('[2026-08-20 09:00:00] pilot.INFO: entirely benign'.PHP_EOL));

    expect($result['metrics']['fresh_error_like_count'])->toBe(0)
        ->and($result['metrics']['window_fully_covered'])->toBeFalse()
        ->and($result['status'])->toBe('WATCH');
});

/*
|--------------------------------------------------------------------------
| The scan budget is a lever, and a bounded one
|--------------------------------------------------------------------------
*/

it('earns coverage back when the budget is raised to cover the whole source', function () {
    $contents = anchorPadTo('[2026-08-20 09:00:00] pilot.INFO: ancient benign padding'.PHP_EOL);

    expect(anchorSnapshot($contents)['metrics']['window_fully_covered'])->toBeFalse();

    Config::set('foundation_monitoring.log_scan.max_source_bytes', 8 * 1024 * 1024);

    $raised = anchorSnapshot($contents);

    expect($raised['metrics']['window_fully_covered'])->toBeTrue()
        ->and($raised['metrics']['tail_bytes_skipped'])->toBe(0)
        ->and($raised['status'])->toBe('OK');
});

it('clamps the configured budget so coverage cannot be bought with an unbounded read', function () {
    $small = '[2026-08-22 11:00:00] pilot.INFO: a very small log'.PHP_EOL;

    // Absurdly small: clamped up to the floor, so a small file is still fully read.
    Config::set('foundation_monitoring.log_scan.max_source_bytes', 1);
    expect(anchorSnapshot($small)['metrics']['window_fully_covered'])->toBeTrue();

    // Absurdly large: clamped down to the ceiling rather than honoured verbatim.
    Config::set('foundation_monitoring.log_scan.max_source_bytes', PHP_INT_MAX);
    expect(anchorSnapshot($small)['metrics']['window_fully_covered'])->toBeTrue();

    expect(PilotPerformanceSnapshotService::LOG_SCAN_BUDGET_MAX_BYTES)->toBe(64 * 1024 * 1024)
        ->and(PilotPerformanceSnapshotService::LOG_SCAN_BUDGET_MIN_BYTES)->toBe(64 * 1024);
});

it('falls back to the default budget when the configured value is not a number', function () {
    Config::set('foundation_monitoring.log_scan.max_source_bytes', 'not-a-number');

    $result = anchorSnapshot(anchorPadTo('[2026-08-20 09:00:00] pilot.INFO: ancient padding'.PHP_EOL));

    // The default budget is 2 MiB, so a file just over it is still truncated — a garbled
    // config must not silently become an unbounded read or a zero-byte one.
    expect($result['metrics']['window_fully_covered'])->toBeFalse()
        ->and($result['metrics']['tail_bytes_scanned'])
        ->toBe(PilotPerformanceSnapshotService::LOG_SCAN_BUDGET_DEFAULT_BYTES);
});

/*
|--------------------------------------------------------------------------
| Multi-source coverage — no source may vouch for another
|--------------------------------------------------------------------------
| These drive the real configuration-resolution path rather than the `log_path`
| override, because the property under test is how per-source verdicts combine.
*/

/** @return array{0:string, 1:string} [directory, log path prefix] */
function anchorChannelStorage(): array
{
    $dir = sys_get_temp_dir().'/coverage-anchor-src-'.uniqid();
    mkdir($dir.'/logs', 0o755, true);

    Config::set('logging.channels.single.path', $dir.'/logs/laravel.log');
    Config::set('logging.channels.daily.path', $dir.'/logs/laravel.log');
    Config::set('logging.channels.daily.days', 14);

    return [$dir, $dir.'/logs/laravel.log'];
}

function anchorChannelCleanup(string $dir): void
{
    foreach (glob($dir.'/logs/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($dir.'/logs');
    @rmdir($dir);
}

/** @return array<string, mixed> */
function anchorConfiguredSnapshot(): array
{
    $snapshot = (new PilotPerformanceSnapshotService(diskProbe: pilotSnapshotDiskProbe(100.0)))->collect([
        'skip_db' => true,
        'skip_http' => true,
        'since' => '24h',
    ]);

    return [
        'metrics' => $snapshot['sections']['logs']['metrics'],
        'status' => $snapshot['sections']['logs']['status'],
        'overall' => $snapshot['overall_status'],
    ];
}

it('does not let an ancient line in today\'s file certify a truncated day file', function () {
    // A 24h window at noon spans two day-files. Yesterday's is far over the budget and so
    // is only partly read; today's is small, complete, and full of ancient-looking lines.
    // Nothing in today's file says anything about yesterday's unread bytes.
    [$dir] = anchorChannelStorage();

    file_put_contents(
        $dir.'/logs/laravel-2026-08-21.log',
        anchorPadTo('[2026-08-21 08:00:00] pilot.INFO: yesterday padding'.PHP_EOL)
    );
    file_put_contents(
        $dir.'/logs/laravel-2026-08-22.log',
        '[2019-01-01 00:00:00] pilot.INFO: an ancient looking line in a complete file'.PHP_EOL
    );

    Config::set('logging.default', 'daily');
    $result = anchorConfiguredSnapshot();

    anchorChannelCleanup($dir);

    expect($result['metrics']['tail_truncated'])->toBeTrue()
        ->and($result['metrics']['source_coverage_complete'])->toBeFalse()
        ->and($result['metrics']['window_fully_covered'])->toBeFalse()
        ->and($result['status'])->not->toBe('OK');
});

it('reports the whole window uncovered when any one stack member was only partly read', function () {
    [$dir, $single] = anchorChannelStorage();

    Config::set('logging.default', 'stack');
    Config::set('logging.channels.stack.channels', ['single', 'daily']);

    // The `single` member is small and completely read.
    file_put_contents($single, '[2026-08-22 11:00:00] pilot.INFO: small and complete'.PHP_EOL);
    // The `daily` member for today is over the budget, and every scanned byte looks ancient.
    file_put_contents(
        $dir.'/logs/laravel-2026-08-22.log',
        anchorPadTo('[2019-01-01 00:00:00] pilot.INFO: ancient anchor padding'.PHP_EOL)
    );
    file_put_contents($dir.'/logs/laravel-2026-08-21.log', '[2026-08-21 08:00:00] pilot.INFO: small'.PHP_EOL);

    $result = anchorConfiguredSnapshot();

    anchorChannelCleanup($dir);

    expect($result['metrics']['source_coverage_complete'])->toBeFalse()
        ->and($result['metrics']['window_fully_covered'])->toBeFalse()
        ->and($result['status'])->not->toBe('OK');
});

it('keeps a fully read multi-source set reporting OK', function () {
    // The multi-source recovery control: every member complete, nothing fresh, so the
    // combination rule is "all must be covered", not "none may ever be".
    [$dir, $single] = anchorChannelStorage();

    Config::set('logging.default', 'stack');
    Config::set('logging.channels.stack.channels', ['single', 'daily']);

    file_put_contents($single, '[2026-08-22 11:00:00] pilot.INFO: small and complete'.PHP_EOL);
    file_put_contents($dir.'/logs/laravel-2026-08-22.log', '[2026-08-22 10:00:00] pilot.INFO: today, complete'.PHP_EOL);
    file_put_contents($dir.'/logs/laravel-2026-08-21.log', '[2026-08-21 08:00:00] pilot.INFO: yesterday, complete'.PHP_EOL);

    $result = anchorConfiguredSnapshot();

    anchorChannelCleanup($dir);

    expect($result['metrics']['source_coverage_complete'])->toBeTrue()
        ->and($result['metrics']['window_fully_covered'])->toBeTrue()
        ->and($result['status'])->toBe('OK')
        ->and($result['overall'])->toBe('OK');
});

it('keeps an unsupported channel costing coverage even beside a fully read file', function () {
    // Preserved from MONITORING-LOG-SOURCE-RESILIENCE-1: a driver this monitor cannot read
    // may be carrying the very errors being counted. A complete read of its neighbour is
    // not evidence about it.
    [$dir, $single] = anchorChannelStorage();

    Config::set('logging.default', 'stack');
    Config::set('logging.channels.stack.channels', ['single', 'stderr']);
    Config::set('logging.channels.stderr', ['driver' => 'monolog', 'handler' => 'StreamHandler']);

    file_put_contents($single, '[2026-08-22 11:00:00] pilot.INFO: small and complete'.PHP_EOL);

    $result = anchorConfiguredSnapshot();

    anchorChannelCleanup($dir);

    expect($result['metrics']['source_coverage_complete'])->toBeFalse()
        ->and($result['status'])->not->toBe('OK');
});

it('does not treat a falsy one-byte log as an empty complete read', function () {
    // `stream_get_contents(...) ?: ''` collapsed both a failed read and the literal
    // string "0" into an empty string. For a file under the budget the read offset is 0,
    // so the collapse produced "coverage complete, zero events" — OK — from a read that
    // returned nothing, alongside a fabricated non-zero tail_bytes_scanned. The read
    // failure now fails closed, and a genuinely falsy byte is reported truthfully.
    $result = anchorSnapshot('0');

    expect($result['metrics']['tail_bytes_scanned'])->toBe(1)
        ->and($result['metrics']['window_fully_covered'])->toBeTrue()
        ->and($result['metrics']['file_exists'])->toBeTrue();
});
