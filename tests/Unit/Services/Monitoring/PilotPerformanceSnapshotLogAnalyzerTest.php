<?php

use App\Services\Monitoring\PilotPerformanceSnapshotLogAnalyzer;
use App\Services\Monitoring\PilotPerformanceSnapshotService;
use Carbon\Carbon;
use Tests\TestCase;

uses(TestCase::class);

it('parses supported since durations', function () {
    expect(PilotPerformanceSnapshotLogAnalyzer::parseSinceDuration('24h'))
        ->toMatchArray(['seconds' => 86400, 'label' => '24h'])
        ->and(PilotPerformanceSnapshotLogAnalyzer::parseSinceDuration('7d'))
        ->toMatchArray(['seconds' => 604800, 'label' => '7d'])
        ->and(PilotPerformanceSnapshotLogAnalyzer::parseSinceDuration('48h'))
        ->toMatchArray(['seconds' => 172800, 'label' => '48h']);
});

it('rejects invalid since durations', function () {
    expect(PilotPerformanceSnapshotLogAnalyzer::parseSinceDuration('bad'))->toBeNull()
        ->and(PilotPerformanceSnapshotLogAnalyzer::parseSinceDuration(''))->toBeNull()
        ->and(PilotPerformanceSnapshotLogAnalyzer::parseSinceDuration('0h'))->toBeNull();
});

it('separates fresh and historical error-like lines by lookback window', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

    $analyzer = new PilotPerformanceSnapshotLogAnalyzer;
    $tail = implode(PHP_EOL, [
        '[2026-06-20 10:00:00] production.ERROR: historical SQLSTATE failure',
        '[2026-06-30 11:00:00] production.ERROR: still historical exception',
        '[2026-07-01 11:30:00] production.ERROR: fresh timeout in queue',
    ]);

    $result = $analyzer->analyzeTail($tail, '24h', 86400, now());

    expect($result['fresh_error_like_count'])->toBe(1)
        ->and($result['historical_tail_error_like_count'])->toBe(2)
        ->and($result['lookback_window'])->toBe('24h')
        ->and($result['timestamp_parse_status'])->toBe('ok');
});

it('counts many fresh errors for investigate and fix thresholds via service integration', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

    $lines = [];

    for ($i = 0; $i < 25; $i++) {
        $lines[] = sprintf('[2026-07-01 11:%02d:00] production.ERROR: fresh exception %d', $i, $i);
    }

    $logPath = tempnam(sys_get_temp_dir(), 'pilot-log-');
    file_put_contents($logPath, implode(PHP_EOL, $lines));

    $service = new PilotPerformanceSnapshotService;
    $snapshot = $service->collect([
        'skip_db' => true,
        'skip_http' => true,
        'since' => '24h',
        'log_path' => $logPath,
    ]);

    @unlink($logPath);

    expect($snapshot['sections']['logs']['status'])->toBe('INVESTIGATE')
        ->and($snapshot['sections']['logs']['metrics']['fresh_error_like_count'])->toBe(25);
});

it('treats unparseable error-like lines as safe watch without raw output', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

    $lines = [];

    for ($i = 0; $i < 25; $i++) {
        $lines[] = 'stack trace exception without timestamp line '.$i;
    }

    $logPath = tempnam(sys_get_temp_dir(), 'pilot-log-');
    file_put_contents($logPath, implode(PHP_EOL, $lines));

    $service = new PilotPerformanceSnapshotService;
    $snapshot = $service->collect([
        'skip_db' => true,
        'skip_http' => true,
        'since' => '24h',
        'log_path' => $logPath,
    ]);

    $encoded = json_encode($snapshot);

    @unlink($logPath);

    expect($snapshot['sections']['logs']['status'])->toBe('WATCH')
        ->and($snapshot['sections']['logs']['metrics']['timestamp_parse_status'])->toBe('failed')
        ->and($snapshot['sections']['logs']['metrics']['unparseable_error_like_count'])->toBe(25)
        ->and($encoded)->not->toContain('stack trace exception');
});

it('keeps overall status ok when only historical log errors exist', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

    $lines = [];

    for ($i = 0; $i < 66; $i++) {
        $lines[] = sprintf('[2026-06-01 10:%02d:00] production.ERROR: historical exception %d', $i % 60, $i);
    }

    $logPath = tempnam(sys_get_temp_dir(), 'pilot-log-');
    file_put_contents($logPath, implode(PHP_EOL, $lines));

    $service = new PilotPerformanceSnapshotService;
    $snapshot = $service->collect([
        'skip_db' => true,
        'skip_http' => true,
        'since' => '24h',
        'log_path' => $logPath,
    ]);

    @unlink($logPath);

    expect($snapshot['sections']['logs']['status'])->toBe('OK')
        ->and($snapshot['sections']['logs']['metrics']['fresh_error_like_count'])->toBe(0)
        ->and($snapshot['sections']['logs']['metrics']['historical_tail_error_like_count'])->toBe(66)
        ->and($snapshot['overall_status'])->toBe('OK');
});

afterEach(function () {
    Carbon::setTestNow();
});
