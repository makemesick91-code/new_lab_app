<?php

use App\Console\Commands\PilotPerformanceSnapshotCommand;
use App\Services\Monitoring\MonitoringLogSourceResolver;
use App\Services\Monitoring\PilotPerformanceSnapshotService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * MONITORING-LOG-SOURCE-RESILIENCE-1 — the monitor must read where the application writes.
 *
 * The previous sprint proved the logs section may only report OK when it actually read a
 * log and actually aged its events. It left one assumption standing: that the log lives at
 * `storage/logs/laravel.log`. That is true today only because production resolves
 * `stack -> [single] -> laravel.log`. Change the channel to `daily`, or add a member to the
 * stack, and the application writes its errors to a different file while the monitor keeps
 * reading a stale one — and reports OK for a system that is actively failing.
 *
 * The invariant, stated once:
 *
 *   The monitored log sources are derived from the effective Laravel logging
 *   configuration, and the logs section may report OK only when those sources were
 *   actually read across the whole lookback window.
 *
 * Every test here pins the disk probe and skips DB/HTTP so the logs section is the only
 * thing that can move `overall_status` (rule 112 R3).
 */
uses(TestCase::class);

/**
 * Point the configured channels at a throwaway log directory, so every test drives the
 * real configuration-resolution path rather than a `log_path` override. The override
 * exists for the analyzer's own tests; using it here would bypass the code under test.
 *
 * The paths are set through config on purpose: config is the authority the resolver
 * reads, and a test that only moved `storage_path()` would not move the resolved sources
 * at all — which is precisely the property being pinned.
 */
function logSourceStorage(): string
{
    $dir = sys_get_temp_dir().'/mon-log-src-'.uniqid();
    mkdir($dir.'/logs', 0o755, true);

    Config::set('logging.channels.single.path', $dir.'/logs/laravel.log');
    Config::set('logging.channels.daily.path', $dir.'/logs/laravel.log');
    Config::set('logging.channels.daily.days', 14);

    return $dir;
}

function logSourceCleanup(string $dir): void
{
    foreach (glob($dir.'/logs/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($dir.'/logs');
    @rmdir($dir);
}

/**
 * Collect a snapshot whose only live section is `logs`, with no path override, so the
 * source set comes from configuration exactly as it does in production.
 */
function logSourceSnapshot(string $since = '24h'): array
{
    return (new PilotPerformanceSnapshotService(diskProbe: pilotSnapshotDiskProbe(100.0)))->collect([
        'skip_db' => true,
        'skip_http' => true,
        'since' => $since,
    ]);
}

function logSourceErrorLine(Carbon $at, string $message = 'a real error inside the window'): string
{
    return '['.$at->format('Y-m-d H:i:s').'] pilot.ERROR: '.$message.PHP_EOL;
}

/*
|--------------------------------------------------------------------------
| Negative controls — these fail against the hardcoded-path implementation
|--------------------------------------------------------------------------
|
| Each of these stages a live error where the *configured* channel actually writes, and
| leaves a clean `laravel.log` sitting next to it. A monitor that reads the configured
| source sees the error; a monitor that reads `laravel.log` reports OK. They are the
| reproducers for the latent false green, kept as permanent regressions.
*/

it('sees an error in the daily file the configured channel actually writes to', function () {
    $dir = logSourceStorage();
    Carbon::setTestNow(Carbon::parse('2026-08-22 11:00:00', 'UTC'));

    // The stale file the old implementation would have read: present, and clean.
    file_put_contents($dir.'/logs/laravel.log', '[2026-08-01 09:00:00] pilot.INFO: nothing to see here'.PHP_EOL);
    // Where a `daily` channel really writes today.
    file_put_contents($dir.'/logs/laravel-2026-08-22.log', logSourceErrorLine(Carbon::parse('2026-08-22 10:30:00', 'UTC')));

    Config::set('logging.default', 'daily');

    $snapshot = logSourceSnapshot();

    Carbon::setTestNow();
    logSourceCleanup($dir);

    expect($snapshot['sections']['logs']['status'])->toBe('WATCH')
        ->and($snapshot['sections']['logs']['metrics']['fresh_error_like_count'])->toBe(1)
        ->and($snapshot['overall_status'])->toBe('WATCH');
});

it('sees an error in a daily file reached through a stack', function () {
    $dir = logSourceStorage();
    Carbon::setTestNow(Carbon::parse('2026-08-22 11:00:00', 'UTC'));

    file_put_contents($dir.'/logs/laravel.log', '[2026-08-01 09:00:00] pilot.INFO: nothing to see here'.PHP_EOL);
    file_put_contents($dir.'/logs/laravel-2026-08-22.log', logSourceErrorLine(Carbon::parse('2026-08-22 10:30:00', 'UTC')));

    // The migration this sprint exists to survive: LOG_STACK flipped from single to daily.
    Config::set('logging.default', 'stack');
    Config::set('logging.channels.stack.channels', ['daily']);

    $snapshot = logSourceSnapshot();

    Carbon::setTestNow();
    logSourceCleanup($dir);

    expect($snapshot['sections']['logs']['status'])->toBe('WATCH')
        ->and($snapshot['sections']['logs']['metrics']['fresh_error_like_count'])->toBe(1);
});

it('sees an error in yesterdays daily file just after the day rolled over', function () {
    $dir = logSourceStorage();
    // Thirty seconds into a new day: today's file exists but is empty, and the whole of
    // the lookback window except those thirty seconds lives in yesterday's file.
    Carbon::setTestNow(Carbon::parse('2026-08-23 00:00:30', 'UTC'));

    file_put_contents($dir.'/logs/laravel-2026-08-23.log', '');
    file_put_contents($dir.'/logs/laravel-2026-08-22.log', logSourceErrorLine(Carbon::parse('2026-08-22 23:50:00', 'UTC')));

    Config::set('logging.default', 'daily');

    $snapshot = logSourceSnapshot();

    Carbon::setTestNow();
    logSourceCleanup($dir);

    // Ten minutes old, and comfortably inside 24h. A scan of only the current day's file
    // would have found an empty file and called the system healthy.
    expect($snapshot['sections']['logs']['status'])->toBe('WATCH')
        ->and($snapshot['sections']['logs']['metrics']['fresh_error_like_count'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| single — the shipped production shape
|--------------------------------------------------------------------------
*/

it('watches a fresh error in a single channel', function () {
    $dir = logSourceStorage();
    Carbon::setTestNow(Carbon::parse('2026-08-22 11:00:00', 'UTC'));
    file_put_contents($dir.'/logs/laravel.log', logSourceErrorLine(Carbon::parse('2026-08-22 10:30:00', 'UTC')));

    Config::set('logging.default', 'single');
    $snapshot = logSourceSnapshot();

    Carbon::setTestNow();
    logSourceCleanup($dir);

    expect($snapshot['sections']['logs']['status'])->toBe('WATCH')
        ->and($snapshot['sections']['logs']['metrics']['fresh_error_like_count'])->toBe(1)
        ->and($snapshot['sections']['logs']['metrics']['source_coverage_complete'])->toBeTrue();
});

it('reports OK for a single channel whose only error has aged out', function () {
    $dir = logSourceStorage();
    Carbon::setTestNow(Carbon::parse('2026-08-22 11:00:00', 'UTC'));
    file_put_contents($dir.'/logs/laravel.log', logSourceErrorLine(Carbon::parse('2026-08-20 10:30:00', 'UTC')));

    Config::set('logging.default', 'single');
    $snapshot = logSourceSnapshot();

    Carbon::setTestNow();
    logSourceCleanup($dir);

    expect($snapshot['sections']['logs']['status'])->toBe('OK')
        ->and($snapshot['sections']['logs']['metrics']['fresh_error_like_count'])->toBe(0)
        ->and($snapshot['sections']['logs']['metrics']['source_coverage_complete'])->toBeTrue();
});

it('fails closed when the configured single file is missing', function () {
    $dir = logSourceStorage();
    Config::set('logging.default', 'single');

    $snapshot = logSourceSnapshot();

    logSourceCleanup($dir);

    // A `single` file is never rotated away, so its absence means it was removed or the
    // channel moved — the exact drift that leaves a monitor reading a dead path.
    expect($snapshot['sections']['logs']['status'])->toBe('WATCH')
        ->and($snapshot['sections']['logs']['metrics']['source_coverage_complete'])->toBeFalse()
        ->and($snapshot['sections']['logs']['metrics']['fresh_error_like_count'])->toBeNull();
});

it('fails closed when the configured single file cannot be read', function () {
    if (posix_getuid() === 0) {
        $this->markTestSkipped('Running as root: file mode cannot make a file unreadable.');
    }

    $dir = logSourceStorage();
    Carbon::setTestNow(Carbon::parse('2026-08-22 11:00:00', 'UTC'));
    file_put_contents($dir.'/logs/laravel.log', logSourceErrorLine(Carbon::parse('2026-08-22 10:30:00', 'UTC')));
    chmod($dir.'/logs/laravel.log', 0o000);

    if (is_readable($dir.'/logs/laravel.log')) {
        chmod($dir.'/logs/laravel.log', 0o600);
        logSourceCleanup($dir);
        Carbon::setTestNow();
        $this->markTestSkipped('Filesystem does not enforce the mode.');
    }

    Config::set('logging.default', 'single');
    $snapshot = logSourceSnapshot();

    chmod($dir.'/logs/laravel.log', 0o600);
    Carbon::setTestNow();
    logSourceCleanup($dir);

    expect($snapshot['sections']['logs']['status'])->toBe('WATCH')
        ->and($snapshot['sections']['logs']['metrics']['log_sources_unreadable'])->toBe(1)
        ->and($snapshot['sections']['logs']['metrics']['source_coverage_complete'])->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| daily — rotation must not hide events inside the window
|--------------------------------------------------------------------------
*/

it('ignores a previous-day error that has already aged out of the window', function () {
    $dir = logSourceStorage();
    Carbon::setTestNow(Carbon::parse('2026-08-23 12:00:00', 'UTC'));

    file_put_contents($dir.'/logs/laravel-2026-08-23.log', '[2026-08-23 09:00:00] pilot.INFO: healthy'.PHP_EOL);
    // 26 hours old: in yesterday's file, but outside a 24h window.
    file_put_contents($dir.'/logs/laravel-2026-08-22.log', logSourceErrorLine(Carbon::parse('2026-08-22 10:00:00', 'UTC')));

    Config::set('logging.default', 'daily');
    $snapshot = logSourceSnapshot();

    Carbon::setTestNow();
    logSourceCleanup($dir);

    expect($snapshot['sections']['logs']['status'])->toBe('OK')
        ->and($snapshot['sections']['logs']['metrics']['fresh_error_like_count'])->toBe(0)
        ->and($snapshot['sections']['logs']['metrics']['historical_tail_error_like_count'])->toBe(1);
});

it('treats an absent day-file inside retention as an observed empty day', function () {
    $dir = logSourceStorage();
    Carbon::setTestNow(Carbon::parse('2026-08-23 12:00:00', 'UTC'));

    // Yesterday produced no log lines at all, so its file was never created. The directory
    // is readable and the day is well inside retention, so nothing could have removed it:
    // "not there" is an observation, not a blind spot.
    file_put_contents($dir.'/logs/laravel-2026-08-23.log', '[2026-08-23 09:00:00] pilot.INFO: healthy'.PHP_EOL);

    Config::set('logging.default', 'daily');
    $snapshot = logSourceSnapshot();

    Carbon::setTestNow();
    logSourceCleanup($dir);

    expect($snapshot['sections']['logs']['status'])->toBe('OK')
        ->and($snapshot['sections']['logs']['metrics']['source_coverage_complete'])->toBeTrue()
        ->and($snapshot['sections']['logs']['metrics']['log_sources_absent'])->toBe(1);
});

it('fails closed for daily when the log directory cannot be enumerated', function () {
    $dir = logSourceStorage();
    Carbon::setTestNow(Carbon::parse('2026-08-23 12:00:00', 'UTC'));

    // Point the channel at a directory that does not exist. Absence can no longer be
    // distinguished from "could not look", so coverage is unprovable.
    Config::set('logging.default', 'daily');
    Config::set('logging.channels.daily.path', $dir.'/absent-dir/laravel.log');

    $snapshot = logSourceSnapshot();

    Carbon::setTestNow();
    logSourceCleanup($dir);

    expect($snapshot['sections']['logs']['status'])->toBe('WATCH')
        ->and($snapshot['sections']['logs']['metrics']['source_coverage_complete'])->toBeFalse()
        ->and($snapshot['sections']['logs']['metrics']['fresh_error_like_count'])->toBeNull();
});

it('fails closed for daily when the day-file predates the retention horizon', function () {
    $dir = logSourceStorage();
    Carbon::setTestNow(Carbon::parse('2026-08-23 12:00:00', 'UTC'));

    file_put_contents($dir.'/logs/laravel-2026-08-23.log', '[2026-08-23 09:00:00] pilot.INFO: healthy'.PHP_EOL);

    Config::set('logging.default', 'daily');
    // Retention of one day means yesterday's file may already have been deleted by the
    // channel itself, so its absence proves nothing.
    Config::set('logging.channels.daily.days', 1);

    $snapshot = logSourceSnapshot('48h');

    Carbon::setTestNow();
    logSourceCleanup($dir);

    expect($snapshot['sections']['logs']['status'])->toBe('WATCH')
        ->and($snapshot['sections']['logs']['metrics']['source_coverage_complete'])->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| stack — every member counts
|--------------------------------------------------------------------------
*/

it('watches a fresh error through the production stack-of-single shape', function () {
    $dir = logSourceStorage();
    Carbon::setTestNow(Carbon::parse('2026-08-22 11:00:00', 'UTC'));
    file_put_contents($dir.'/logs/laravel.log', logSourceErrorLine(Carbon::parse('2026-08-22 10:30:00', 'UTC')));

    Config::set('logging.default', 'stack');
    Config::set('logging.channels.stack.channels', ['single']);

    $snapshot = logSourceSnapshot();

    Carbon::setTestNow();
    logSourceCleanup($dir);

    expect($snapshot['sections']['logs']['status'])->toBe('WATCH')
        ->and($snapshot['sections']['logs']['metrics']['fresh_error_like_count'])->toBe(1);
});

it('finds an error in either member of a mixed stack', function (string $target) {
    $dir = logSourceStorage();
    Carbon::setTestNow(Carbon::parse('2026-08-22 11:00:00', 'UTC'));

    $error = logSourceErrorLine(Carbon::parse('2026-08-22 10:30:00', 'UTC'));
    $clean = '[2026-08-22 09:00:00] pilot.INFO: healthy'.PHP_EOL;

    file_put_contents($dir.'/logs/laravel.log', $target === 'single' ? $error : $clean);
    file_put_contents($dir.'/logs/laravel-2026-08-22.log', $target === 'daily' ? $error : $clean);
    file_put_contents($dir.'/logs/laravel-2026-08-21.log', $clean);

    Config::set('logging.default', 'stack');
    Config::set('logging.channels.stack.channels', ['single', 'daily']);

    $snapshot = logSourceSnapshot();

    Carbon::setTestNow();
    logSourceCleanup($dir);

    // Whichever member carries it, the worst finding survives the fold — a healthy sibling
    // never averages away a failing one.
    expect($snapshot['sections']['logs']['status'])->toBe('WATCH')
        ->and($snapshot['sections']['logs']['metrics']['fresh_error_like_count'])->toBe(1);
})->with(['single', 'daily']);

it('fails closed when a stack member writes somewhere it cannot read', function () {
    $dir = logSourceStorage();
    Carbon::setTestNow(Carbon::parse('2026-08-22 11:00:00', 'UTC'));
    file_put_contents($dir.'/logs/laravel.log', '[2026-08-22 09:00:00] pilot.INFO: healthy'.PHP_EOL);

    Config::set('logging.default', 'stack');
    Config::set('logging.channels.stack.channels', ['single', 'syslog']);

    $snapshot = logSourceSnapshot();

    Carbon::setTestNow();
    logSourceCleanup($dir);

    // The readable member is clean, but syslog carries events this monitor cannot see.
    // Reporting OK here would be claiming coverage of a channel it never opened.
    expect($snapshot['sections']['logs']['status'])->toBe('WATCH')
        ->and($snapshot['sections']['logs']['metrics']['log_sources_unsupported'])->toBe(1)
        ->and($snapshot['sections']['logs']['metrics']['source_coverage_complete'])->toBeFalse()
        ->and(implode(' | ', $snapshot['warnings']))->toContain('which this monitor cannot read');
});

it('does not lose coverage to a null channel that discards everything', function () {
    $dir = logSourceStorage();
    Carbon::setTestNow(Carbon::parse('2026-08-22 11:00:00', 'UTC'));
    file_put_contents($dir.'/logs/laravel.log', '[2026-08-22 09:00:00] pilot.INFO: healthy'.PHP_EOL);

    Config::set('logging.default', 'stack');
    Config::set('logging.channels.stack.channels', ['single', 'null']);

    $snapshot = logSourceSnapshot();

    Carbon::setTestNow();
    logSourceCleanup($dir);

    // A `null` channel provably keeps nothing, so it costs no coverage — unlike syslog,
    // which really does carry events elsewhere.
    expect($snapshot['sections']['logs']['status'])->toBe('OK')
        ->and($snapshot['sections']['logs']['metrics']['source_coverage_complete'])->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Severity and coverage invariants carried forward
|--------------------------------------------------------------------------
*/

it('keeps a severe fresh burst severe when it is split across stack members', function () {
    $dir = logSourceStorage();
    Carbon::setTestNow(Carbon::parse('2026-08-22 11:00:00', 'UTC'));

    $burst = str_repeat(logSourceErrorLine(Carbon::parse('2026-08-22 10:30:00', 'UTC')), 75);
    file_put_contents($dir.'/logs/laravel.log', $burst);
    file_put_contents($dir.'/logs/laravel-2026-08-22.log', $burst);
    file_put_contents($dir.'/logs/laravel-2026-08-21.log', '');

    Config::set('logging.default', 'stack');
    Config::set('logging.channels.stack.channels', ['single', 'daily']);

    $snapshot = logSourceSnapshot();

    Carbon::setTestNow();
    logSourceCleanup($dir);

    // 150 fresh events must stay severe. Per-source aggregation must not let a large burst
    // be divided into two small ones and demoted.
    expect($snapshot['sections']['logs']['metrics']['fresh_error_like_count'])->toBe(150)
        ->and($snapshot['sections']['logs']['status'])->not->toBe('OK');
});

it('never claims full-window coverage from a partial scan of a rotated file', function () {
    $dir = logSourceStorage();
    Carbon::setTestNow(Carbon::parse('2026-08-22 23:00:00', 'UTC'));

    // A today-file far larger than the 2 MiB budget, whose scanned tail begins well after
    // the window opened. The unread head could be hiding an in-window error.
    $filler = str_repeat('[2026-08-22 22:59:00] pilot.INFO: padding to exceed the scan budget'.PHP_EOL, 40000);
    file_put_contents($dir.'/logs/laravel-2026-08-22.log', $filler);
    file_put_contents($dir.'/logs/laravel-2026-08-21.log', '[2026-08-21 23:30:00] pilot.INFO: healthy'.PHP_EOL);

    Config::set('logging.default', 'daily');
    $snapshot = logSourceSnapshot();

    Carbon::setTestNow();
    logSourceCleanup($dir);

    expect($snapshot['sections']['logs']['metrics']['tail_truncated'])->toBeTrue()
        ->and($snapshot['sections']['logs']['metrics']['source_coverage_complete'])->toBeFalse()
        ->and($snapshot['sections']['logs']['status'])->not->toBe('OK');
});

/*
|--------------------------------------------------------------------------
| Resolver unit behaviour
|--------------------------------------------------------------------------
*/

it('resolves the monitored source set from config, including nested and cyclic stacks', function () {
    Config::set('logging.channels.single.path', '/tmp/some/logs/laravel.log');
    Config::set('logging.default', 'stack');
    Config::set('logging.channels.stack.channels', ['inner']);
    Config::set('logging.channels.inner', ['driver' => 'stack', 'channels' => ['single', 'stack']]);

    $resolution = (new MonitoringLogSourceResolver)->resolve(
        Carbon::parse('2026-08-22 11:00:00', 'UTC'),
        Carbon::parse('2026-08-22 12:00:00', 'UTC'),
    );

    // The nested stack flattens to its leaf, and the self-reference back to `stack`
    // terminates instead of recursing forever.
    expect($resolution['monitored_channels'])->toBe(['single'])
        ->and($resolution['resolution_status'])->toBe('resolved')
        ->and($resolution['sources'][0]->path)->toBe('/tmp/some/logs/laravel.log');
});

it('derives rotated day filenames from the configured path', function () {
    Config::set('logging.default', 'daily');
    Config::set('logging.channels.daily.path', '/var/log/app/custom-name.log');

    $resolution = (new MonitoringLogSourceResolver)->resolve(
        Carbon::parse('2026-08-21 23:00:00', 'UTC'),
        Carbon::parse('2026-08-22 01:00:00', 'UTC'),
    );

    // Monolog's `{filename}-{date}` convention, applied to whatever path is configured
    // rather than to an assumed `laravel.log`.
    expect(array_map(fn ($s) => $s->path, $resolution['sources']))->toBe([
        '/var/log/app/custom-name-2026-08-21.log',
        '/var/log/app/custom-name-2026-08-22.log',
    ]);
});

it('reports an unresolvable configuration rather than defaulting to a guessed path', function () {
    Config::set('logging.default', 'syslog');

    $resolution = (new MonitoringLogSourceResolver)->resolve(
        Carbon::parse('2026-08-22 11:00:00', 'UTC'),
        Carbon::parse('2026-08-22 12:00:00', 'UTC'),
    );

    expect($resolution['resolution_status'])->toBe('unresolved')
        ->and($resolution['unsupported_channels'])->toBe(['syslog'])
        ->and($resolution['sources'][0]->isSupported())->toBeFalse();
});

it('follows configuration rather than environment variables', function () {
    // Production runs with a cached config, where env() is not the runtime authority. A
    // resolver reading env() would disagree with the running application in exactly the
    // deployment that matters.
    putenv('LOG_CHANNEL=single');
    Config::set('logging.default', 'daily');
    Config::set('logging.channels.daily.path', '/var/log/app/laravel.log');

    $resolution = (new MonitoringLogSourceResolver)->resolve(
        Carbon::parse('2026-08-22 11:00:00', 'UTC'),
        Carbon::parse('2026-08-22 12:00:00', 'UTC'),
    );

    putenv('LOG_CHANNEL');

    expect($resolution['monitored_channels'])->toBe(['daily'])
        ->and($resolution['sources'][0]->driver)->toBe('daily');
});

it('bounds the rotated file set and refuses to claim coverage when the cap bites', function () {
    $dir = logSourceStorage();
    Carbon::setTestNow(Carbon::parse('2026-08-23 12:00:00', 'UTC'));

    file_put_contents($dir.'/logs/laravel-2026-08-23.log', '[2026-08-23 09:00:00] pilot.INFO: healthy'.PHP_EOL);

    Config::set('logging.default', 'daily');
    Config::set('logging.channels.daily.days', 400);

    // The lookback duration is caller-supplied and effectively unbounded. Without a cap,
    // one snapshot would open a year of rotated files at up to the scan budget each.
    $snapshot = logSourceSnapshot('365d');

    Carbon::setTestNow();
    logSourceCleanup($dir);

    expect(count($snapshot['sections']['logs']['metrics']['log_sources']))->toBeLessThanOrEqual(31)
        ->and($snapshot['sections']['logs']['metrics']['source_coverage_complete'])->toBeFalse()
        ->and($snapshot['sections']['logs']['status'])->not->toBe('OK')
        ->and(implode(' | ', $snapshot['warnings']))->toContain('spans more rotated log files than are scanned in one pass');
});

it('never accepts a monitored log path from anything but trusted configuration', function () {
    // The `log_path` seam is internal and test-only. If it ever became a CLI flag or a
    // request parameter, the monitor would read attacker-chosen files.
    $command = new ReflectionClass(PilotPerformanceSnapshotCommand::class);
    $signature = $command->getProperty('signature');
    $signature->setAccessible(true);

    expect($signature->getValue($command->newInstanceWithoutConstructor()))
        ->not->toContain('log-path')
        ->not->toContain('log_path');
});
