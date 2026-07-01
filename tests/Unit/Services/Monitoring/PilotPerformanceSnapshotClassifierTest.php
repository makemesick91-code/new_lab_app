<?php

use App\Services\Monitoring\PilotPerformanceSnapshotClassifier;

it('maps SQL runtime thresholds correctly', function () {
    expect(PilotPerformanceSnapshotClassifier::classifySqlRuntimeMs(50))->toBe('OK')
        ->and(PilotPerformanceSnapshotClassifier::classifySqlRuntimeMs(150))->toBe('WATCH')
        ->and(PilotPerformanceSnapshotClassifier::classifySqlRuntimeMs(400))->toBe('WATCH')
        ->and(PilotPerformanceSnapshotClassifier::classifySqlRuntimeMs(750))->toBe('INVESTIGATE')
        ->and(PilotPerformanceSnapshotClassifier::classifySqlRuntimeMs(1500))->toBe('FIX');
});

it('maps HTTP check thresholds correctly', function () {
    expect(PilotPerformanceSnapshotClassifier::classifyHttpCheck(['code' => 302, 'avg_ms' => 50]))->toBe('OK')
        ->and(PilotPerformanceSnapshotClassifier::classifyHttpCheck(['code' => 200, 'avg_ms' => 150]))->toBe('WATCH')
        ->and(PilotPerformanceSnapshotClassifier::classifyHttpCheck(['code' => 500, 'avg_ms' => 50]))->toBe('FIX')
        ->and(PilotPerformanceSnapshotClassifier::classifyHttpCheck(['error' => 'timeout']))->toBe('INVESTIGATE');
});

it('maps disk free thresholds correctly', function () {
    expect(PilotPerformanceSnapshotClassifier::classifyDiskFreeGb(25))->toBe('OK')
        ->and(PilotPerformanceSnapshotClassifier::classifyDiskFreeGb(15))->toBe('WATCH')
        ->and(PilotPerformanceSnapshotClassifier::classifyDiskFreeGb(5))->toBe('FIX');
});

it('selects worst status among candidates', function () {
    expect(PilotPerformanceSnapshotClassifier::worst('OK', 'WATCH', 'OK'))->toBe('WATCH')
        ->and(PilotPerformanceSnapshotClassifier::worst('OK', 'INVESTIGATE', 'WATCH'))->toBe('INVESTIGATE')
        ->and(PilotPerformanceSnapshotClassifier::worst('WATCH', 'FIX'))->toBe('FIX');
});

it('maps status to exit codes', function () {
    expect(PilotPerformanceSnapshotClassifier::exitCodeForStatus('OK'))->toBe(0)
        ->and(PilotPerformanceSnapshotClassifier::exitCodeForStatus('WATCH'))->toBe(1)
        ->and(PilotPerformanceSnapshotClassifier::exitCodeForStatus('INVESTIGATE'))->toBe(2)
        ->and(PilotPerformanceSnapshotClassifier::exitCodeForStatus('FIX'))->toBe(3);
});

it('classifies payment row counts with escalation', function () {
    expect(PilotPerformanceSnapshotClassifier::classifyPaymentCount(100))->toBe('OK')
        ->and(PilotPerformanceSnapshotClassifier::classifyPaymentCount(50_000))->toBe('WATCH')
        ->and(PilotPerformanceSnapshotClassifier::classifyPaymentCount(2_000_000, 'INVESTIGATE', 'OK'))->toBe('INVESTIGATE');
});

it('flags debug on pilot and maintenance mode', function () {
    expect(PilotPerformanceSnapshotClassifier::classifyDebugMode(true, 'pilot'))->toBe('WATCH')
        ->and(PilotPerformanceSnapshotClassifier::classifyDebugMode(true, 'local'))->toBe('OK')
        ->and(PilotPerformanceSnapshotClassifier::classifyMaintenanceMode(true))->toBe('INVESTIGATE');
});

it('classifies fresh log errors with thresholds and critical escalation', function () {
    expect(PilotPerformanceSnapshotClassifier::classifyFreshLogErrors(0, 0, 'ok', 0, 66))
        ->toMatchArray([
            'status' => 'OK',
            'reason' => 'No fresh error-like entries within lookback window; historical entries are informational only.',
        ])
        ->and(PilotPerformanceSnapshotClassifier::classifyFreshLogErrors(5, 0, 'ok', 0, 0)['status'])->toBe('WATCH')
        ->and(PilotPerformanceSnapshotClassifier::classifyFreshLogErrors(25, 0, 'ok', 0, 0)['status'])->toBe('INVESTIGATE')
        ->and(PilotPerformanceSnapshotClassifier::classifyFreshLogErrors(120, 0, 'ok', 0, 0)['status'])->toBe('FIX')
        ->and(PilotPerformanceSnapshotClassifier::classifyFreshLogErrors(2, 10, 'ok', 0, 0)['status'])->toBe('FIX');
});

it('returns watch when timestamp freshness cannot be determined', function () {
    $result = PilotPerformanceSnapshotClassifier::classifyFreshLogErrors(0, 0, 'failed', 25, 0);

    expect($result['status'])->toBe('WATCH')
        ->and($result['reason'])->toContain('Unable to determine freshness');
});
