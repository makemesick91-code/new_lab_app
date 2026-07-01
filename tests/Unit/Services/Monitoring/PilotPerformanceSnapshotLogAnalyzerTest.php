<?php

use App\Services\Monitoring\PilotPerformanceSnapshotClassifier;
use App\Services\Monitoring\PilotPerformanceSnapshotLogAnalyzer;
use App\Services\Monitoring\PilotPerformanceSnapshotService;
use Carbon\Carbon;
use Tests\TestCase;

uses(TestCase::class);

it('groups historical error events with stack trace continuation lines', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

    $tail = implode(PHP_EOL, [
        '[2026-06-01 10:00:00] production.ERROR: historical exception',
        'Stack trace:',
        '#0 /var/www/app/Exception.php(50): App\\Services\\Example->run()',
        '#1 {main}',
        'thrown in /var/www/app/Exception.php on line 50',
    ]);

    $metrics = (new PilotPerformanceSnapshotLogAnalyzer)->analyzeTail($tail, '24h', 86400, now());

    expect($metrics['fresh_error_like_count'])->toBe(0)
        ->and($metrics['historical_tail_error_like_count'])->toBe(1)
        ->and($metrics['historical_stack_trace_line_count'])->toBe(4)
        ->and($metrics['unparseable_error_like_count'])->toBe(0)
        ->and($metrics['orphan_unparseable_error_like_count'])->toBe(0)
        ->and($metrics['attached_unparseable_line_count'])->toBe(0)
        ->and($metrics['log_grouping_status'])->toBe('grouped')
        ->and($metrics['timestamp_parse_status'])->toBe('ok');

    $classification = PilotPerformanceSnapshotClassifier::classifyFreshLogErrors(
        $metrics['fresh_error_like_count'],
        $metrics['critical_fresh_count'],
        $metrics['timestamp_parse_status'],
        $metrics['orphan_unparseable_error_like_count'],
        $metrics['historical_tail_error_like_count'],
        $metrics['historical_stack_trace_line_count'],
    );

    expect($classification['status'])->toBe('OK');
});

it('groups fresh error events with stack trace continuation lines without inflating event count', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

    $tail = implode(PHP_EOL, [
        '[2026-07-01 11:00:00] production.ERROR: fresh exception',
        '#0 /var/www/app/Exception.php(50): App\\Services\\Example->run()',
        '#1 {main}',
    ]);

    $metrics = (new PilotPerformanceSnapshotLogAnalyzer)->analyzeTail($tail, '24h', 86400, now());

    expect($metrics['fresh_error_like_count'])->toBe(1)
        ->and($metrics['fresh_stack_trace_line_count'])->toBe(2)
        ->and($metrics['unparseable_error_like_count'])->toBe(0);

    $classification = PilotPerformanceSnapshotClassifier::classifyFreshLogErrors(
        $metrics['fresh_error_like_count'],
        $metrics['critical_fresh_count'],
        $metrics['timestamp_parse_status'],
        $metrics['orphan_unparseable_error_like_count'],
        $metrics['historical_tail_error_like_count'],
        $metrics['historical_stack_trace_line_count'],
    );

    expect($classification['status'])->toBe('WATCH');
});

it('uses event counts for multiple fresh error thresholds not stack trace line counts', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

    $lines = [];

    for ($i = 0; $i < 25; $i++) {
        $lines[] = sprintf('[2026-07-01 11:%02d:00] production.ERROR: fresh exception %d', $i, $i);
        $lines[] = '#0 /var/www/app/Exception.php(50): run()';
        $lines[] = '#1 {main}';
    }

    $metrics = (new PilotPerformanceSnapshotLogAnalyzer)->analyzeTail(implode(PHP_EOL, $lines), '24h', 86400, now());

    expect($metrics['fresh_error_like_count'])->toBe(25)
        ->and($metrics['fresh_stack_trace_line_count'])->toBe(50);

    $classification = PilotPerformanceSnapshotClassifier::classifyFreshLogErrors(
        $metrics['fresh_error_like_count'],
        $metrics['critical_fresh_count'],
        $metrics['timestamp_parse_status'],
        $metrics['orphan_unparseable_error_like_count'],
        $metrics['historical_tail_error_like_count'],
        $metrics['historical_stack_trace_line_count'],
    );

    expect($classification['status'])->toBe('INVESTIGATE');
});

it('counts orphan stack trace lines without parent timestamped event', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

    $tail = implode(PHP_EOL, [
        '#0 /var/www/app/Exception.php(50): orphan trace',
        '#1 {main}',
        'Stack trace:',
    ]);

    $metrics = (new PilotPerformanceSnapshotLogAnalyzer)->analyzeTail($tail, '24h', 86400, now());

    expect($metrics['orphan_unparseable_error_like_count'])->toBe(3)
        ->and($metrics['unparseable_error_like_count'])->toBe(3)
        ->and($metrics['fresh_error_like_count'])->toBe(0)
        ->and($metrics['historical_tail_error_like_count'])->toBe(0)
        ->and($metrics['log_grouping_status'])->toBe('none');
});

it('handles mixed historical grouped events and orphan lines', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

    $tail = implode(PHP_EOL, [
        '#0 /var/www/app/Exception.php(10): orphan before header',
        '[2026-06-01 10:00:00] production.ERROR: historical exception',
        '#0 /var/www/app/Exception.php(50): grouped trace',
        '#1 orphan without parent after last event',
    ]);

    $metrics = (new PilotPerformanceSnapshotLogAnalyzer)->analyzeTail($tail, '24h', 86400, now());

    expect($metrics['historical_tail_error_like_count'])->toBe(1)
        ->and($metrics['historical_stack_trace_line_count'])->toBe(2)
        ->and($metrics['orphan_unparseable_error_like_count'])->toBe(1)
        ->and($metrics['log_grouping_status'])->toBe('partial');
});

it('keeps overall ok for historical-only grouped stack traces via service', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00'));

    $lines = [
        '[2026-06-01 10:00:00] production.ERROR: historical exception',
        'Stack trace:',
    ];

    for ($i = 0; $i < 51; $i++) {
        $lines[] = sprintf('#%d /var/www/app/Exception.php(%d): trace', $i, $i + 1);
    }

    $logPath = tempnam(sys_get_temp_dir(), 'pilot-log-stack-');
    file_put_contents($logPath, implode(PHP_EOL, $lines));

    $snapshot = (new PilotPerformanceSnapshotService)->collect([
        'skip_db' => true,
        'skip_http' => true,
        'since' => '24h',
        'log_path' => $logPath,
    ]);

    @unlink($logPath);

    $logs = $snapshot['sections']['logs'];

    expect($snapshot['overall_status'])->toBe('OK')
        ->and($logs['status'])->toBe('OK')
        ->and($logs['metrics']['historical_tail_error_like_count'])->toBe(1)
        ->and($logs['metrics']['historical_stack_trace_line_count'])->toBe(52)
        ->and($logs['metrics']['unparseable_error_like_count'])->toBe(0)
        ->and($logs['metrics']['orphan_unparseable_error_like_count'])->toBe(0);
});

afterEach(function () {
    Carbon::setTestNow();
});
