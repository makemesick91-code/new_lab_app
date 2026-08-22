<?php

use App\Console\Commands\PilotPerformanceSnapshotCommand;
use App\Services\Monitoring\PilotPerformanceSnapshotClassifier;
use App\Services\Monitoring\PilotPerformanceSnapshotService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('registers pilot performance snapshot command in artisan list', function () {
    $commands = Artisan::all();

    expect($commands)->toHaveKey('pilot:performance-snapshot');
});

it('blocks production environment without force flag', function () {
    $originalEnv = app()->environment();
    $originalConfig = config('app.env');

    app()->instance('env', 'production');
    config(['app.env' => 'production']);

    try {
        $exitCode = Artisan::call('pilot:performance-snapshot', ['--no-http' => true, '--no-db' => true]);

        expect($exitCode)->toBe(10)
            ->and(Artisan::output())->toContain('production');
    } finally {
        app()->instance('env', $originalEnv);
        config(['app.env' => $originalConfig]);
    }
});

it('outputs valid JSON with overall_status', function () {
    $exitCode = Artisan::call('pilot:performance-snapshot', [
        '--json' => true,
        '--no-http' => true,
        '--no-db' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload)->toHaveKeys(['checked_at', 'environment', 'overall_status', 'sections'])
        ->and($payload['overall_status'])->toBeString();
});

it('outputs markdown heading without secrets or PII markers', function () {
    Artisan::call('pilot:performance-snapshot', [
        '--markdown' => true,
        '--no-http' => true,
        '--no-db' => true,
    ]);

    $output = Artisan::output();

    expect($output)->toContain('# Pilot Performance Snapshot')
        ->and($output)->toContain('Overall status')
        ->and($output)->not->toContain('DB_PASSWORD')
        ->and($output)->not->toContain('.env')
        ->and($output)->not->toContain('KTP')
        ->and($output)->not->toContain('NIK');
});

it('skips database section with no-db flag', function () {
    Artisan::call('pilot:performance-snapshot', [
        '--json' => true,
        '--no-db' => true,
        '--no-http' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['skipped_checks'])->toContain('database')
        ->and($payload['sections']['database']['metrics']['skipped'])->toBeTrue();
});

it('skips http section with no-http flag', function () {
    Artisan::call('pilot:performance-snapshot', [
        '--json' => true,
        '--no-http' => true,
        '--no-db' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['skipped_checks'])->toContain('http')
        ->and($payload['sections']['http']['metrics']['skipped'])->toBeTrue();
});

it('does not leak secrets or patient-identifying fields in json output', function () {
    Artisan::call('pilot:performance-snapshot', [
        '--json' => true,
        '--no-http' => true,
        '--no-db' => true,
    ]);

    $output = Artisan::output();

    expect($output)->not->toContain('DB_PASSWORD')
        ->and($output)->not->toContain('.env')
        ->and($output)->not->toContain('KTP')
        ->and($output)->not->toContain('NIK')
        ->and($output)->not->toContain('patient_name')
        ->and($output)->not->toContain('phone')
        ->and($output)->not->toContain('email');
});

it('returns non-zero exit for watch when fail-on-watch is set', function () {
    Http::fake([
        '*' => Http::response('', 500),
    ]);

    config(['app.url' => 'http://snapshot-test.test']);

    $exitCode = Artisan::call('pilot:performance-snapshot', [
        '--fail-on-watch' => true,
        '--no-db' => true,
    ]);

    expect($exitCode)->toBeGreaterThanOrEqual(1);
});

it('defines expected command options', function () {
    $command = new PilotPerformanceSnapshotCommand;

    expect($command->getDefinition()->getOption('json')->getDefault())->toBeFalse()
        ->and($command->getDefinition()->getOption('markdown')->getDefault())->toBeFalse()
        ->and($command->getDefinition()->getOption('no-db')->getDefault())->toBeFalse()
        ->and($command->getDefinition()->getOption('no-http')->getDefault())->toBeFalse()
        ->and($command->getDefinition()->getOption('fail-on-watch')->getDefault())->toBeFalse();
});

it('rejects invalid since duration with exit code 10', function () {
    $exitCode = Artisan::call('pilot:performance-snapshot', [
        '--since' => 'bad',
        '--no-http' => true,
        '--no-db' => true,
    ]);

    expect($exitCode)->toBe(10)
        ->and(Artisan::output())->toContain('Invalid --since duration');
});

it('includes fresh and historical log metrics in json output', function () {
    Artisan::call('pilot:performance-snapshot', [
        '--json' => true,
        '--no-http' => true,
        '--no-db' => true,
        '--since' => '24h',
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $logs = $payload['sections']['logs'];

    expect($logs['metrics'])->toHaveKeys([
        'lookback_window',
        'fresh_error_like_count',
        'historical_tail_error_like_count',
        'unparseable_error_like_count',
        'fresh_stack_trace_line_count',
        'historical_stack_trace_line_count',
        'orphan_unparseable_error_like_count',
        'attached_unparseable_line_count',
        'log_grouping_status',
        'timestamp_parse_status',
    ]);
});

it('includes grouped stack trace summary in markdown output', function () {
    Artisan::call('pilot:performance-snapshot', [
        '--markdown' => true,
        '--no-http' => true,
        '--no-db' => true,
    ]);

    $output = Artisan::output();

    expect($output)->toContain('fresh=')
        ->and($output)->toContain('historical=')
        ->and($output)->toContain('historical_stack_lines=')
        ->and($output)->toContain('orphan_unparseable=')
        ->and($output)->toContain('lookback=');
});

it('includes grouped stack trace summary in console output without raw log lines', function () {
    Artisan::call('pilot:performance-snapshot', [
        '--no-http' => true,
        '--no-db' => true,
    ]);

    $output = Artisan::output();

    expect($output)->toContain('Fresh error events')
        ->and($output)->toContain('Historical error events')
        ->and($output)->toContain('Orphan unparseable error-like lines')
        ->and($output)->not->toContain('DB_PASSWORD')
        ->and($output)->not->toContain('KTP')
        ->and($output)->not->toContain('NIK');
});

it('returns zero fail-on-watch when only historical logs would have previously caused watch', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

    $lines = [];

    for ($i = 0; $i < 30; $i++) {
        $lines[] = sprintf('[2026-06-01 10:%02d:00] production.ERROR: historical exception %d', $i % 60, $i);
    }

    $logPath = tempnam(sys_get_temp_dir(), 'pilot-log-fow-');
    file_put_contents($logPath, implode(PHP_EOL, $lines));

    // Disk pinned clear of the 20 GB WATCH boundary: the exit code below must come
    // from the historical log verdict, not from whatever disk the host happens to have.
    $service = new PilotPerformanceSnapshotService(diskProbe: pilotSnapshotDiskProbe(100.0));
    $snapshot = $service->collect([
        'skip_db' => true,
        'skip_http' => true,
        'since' => '24h',
        'log_path' => $logPath,
    ]);

    @unlink($logPath);

    expect($snapshot['overall_status'])->toBe('OK')
        ->and(PilotPerformanceSnapshotClassifier::exitCodeForStatus($snapshot['overall_status']))->toBe(0);
});

it('returns fail-on-watch exit code 1 for fresh log watch status', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

    $logPath = tempnam(sys_get_temp_dir(), 'pilot-log-fow-fresh-');
    file_put_contents($logPath, '[2026-07-01 11:00:00] production.ERROR: fresh exception'.PHP_EOL);

    // Every other section is pinned healthy — database and http are skipped, the disk
    // is well clear of its boundary — so the WATCH and the exit code 1 can only have
    // come from the fresh log event reaching the aggregate. Without the pin this
    // assertion also passes on a host whose own disk is low, which would let the log
    // status stop reaching `overall_status` entirely without failing anything.
    $service = new PilotPerformanceSnapshotService(diskProbe: pilotSnapshotDiskProbe(100.0));
    $snapshot = $service->collect([
        'skip_db' => true,
        'skip_http' => true,
        'since' => '24h',
        'log_path' => $logPath,
    ]);

    @unlink($logPath);

    expect($snapshot['sections']['logs']['status'])->toBe('WATCH')
        ->and($snapshot['overall_status'])->toBe('WATCH')
        ->and(PilotPerformanceSnapshotClassifier::exitCodeForStatus($snapshot['overall_status']))->toBe(1);
});

afterEach(function () {
    Carbon::setTestNow();
});
