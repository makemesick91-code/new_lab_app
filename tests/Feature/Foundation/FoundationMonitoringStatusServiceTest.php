<?php

use App\Services\Foundation\FoundationMonitoringStatusService;
use Illuminate\Support\Facades\Schema;

uses()->group('Foundation', 'FoundationMonitoring', 'EnterpriseFoundation');

function monitoringService(): FoundationMonitoringStatusService
{
    return app(FoundationMonitoringStatusService::class);
}

// ---------------------------------------------------------------------------
// Pure decision aggregation — GO / WATCH / FAIL / UNKNOWN
// ---------------------------------------------------------------------------

it('decides GO when every signal is GO', function () {
    $decision = monitoringService()->decide([
        ['status' => 'GO'], ['status' => 'GO'],
    ]);

    expect($decision)->toBe('GO');
});

it('decides WATCH when any signal is WATCH and none FAIL', function () {
    $decision = monitoringService()->decide([
        ['status' => 'GO'], ['status' => 'WATCH'], ['status' => 'UNKNOWN'],
    ]);

    expect($decision)->toBe('WATCH');
});

it('decides FAIL when any signal FAILs regardless of others', function () {
    $decision = monitoringService()->decide([
        ['status' => 'GO'], ['status' => 'WATCH'], ['status' => 'FAIL'],
    ]);

    expect($decision)->toBe('FAIL');
});

it('decides UNKNOWN when there is no GO and nothing worse', function () {
    expect(monitoringService()->decide([['status' => 'UNKNOWN']]))->toBe('UNKNOWN')
        ->and(monitoringService()->decide([]))->toBe('UNKNOWN');
});

// ---------------------------------------------------------------------------
// collect() — read-only, structured, graceful
// ---------------------------------------------------------------------------

it('collects a structured consolidated report', function () {
    $report = monitoringService()->collect();

    expect($report)->toHaveKeys(['decision', 'unsafe', 'generated_at', 'summary', 'reasons', 'signals'])
        ->and($report['decision'])->toBeIn(['GO', 'WATCH', 'FAIL', 'UNKNOWN'])
        ->and($report['signals'])->not->toBeEmpty();

    foreach ($report['signals'] as $signal) {
        expect($signal)->toHaveKeys(['key', 'label', 'status', 'summary'])
            ->and($signal['status'])->toBeIn(['GO', 'WATCH', 'FAIL', 'UNKNOWN']);
    }
});

it('exposes runtime env/debug/version details without secrets', function () {
    $runtime = collect(monitoringService()->collect()['signals'])->firstWhere('key', 'app_runtime');

    expect($runtime['details'])->toHaveKeys(['environment', 'debug', 'maintenance', 'php_version', 'laravel_version', 'commit', 'tag']);
});

it('flags a FAIL and unsafe runtime when debug is on in a production-like environment', function () {
    config(['app.debug' => true, 'foundation_monitoring.production_like_environments' => ['testing']]);

    $report = monitoringService()->collect();
    $runtime = collect($report['signals'])->firstWhere('key', 'app_runtime');

    expect($runtime['status'])->toBe('FAIL')
        ->and($runtime['unsafe'])->toBeTrue()
        ->and($report['decision'])->toBe('FAIL')
        ->and($report['unsafe'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// Scope E — storage / cache writability
// ---------------------------------------------------------------------------

it('reports GO when all writable paths are writable', function () {
    $signal = collect(monitoringService()->collect()['signals'])->firstWhere('key', 'storage_cache_writable');

    expect($signal['status'])->toBe('GO')
        ->and($signal['details']['paths'])->not->toBeEmpty();
});

it('reports FAIL when a required writable path is missing', function () {
    config(['foundation_monitoring.writable_paths' => ['storage/definitely-missing-'.uniqid()]]);

    $signal = collect(monitoringService()->collect()['signals'])->firstWhere('key', 'storage_cache_writable');

    expect($signal['status'])->toBe('FAIL')
        ->and($signal['unsafe'])->toBeTrue()
        ->and($signal['remediation'])->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Graceful handling of missing sources
// ---------------------------------------------------------------------------

it('handles a missing failed_jobs table as UNKNOWN, not a crash', function () {
    Schema::dropIfExists('failed_jobs');

    $signal = collect(monitoringService()->collect()['signals'])->firstWhere('key', 'queue_failed_jobs');

    expect($signal['status'])->toBe('UNKNOWN')
        ->and($signal['details']['table_exists'])->toBeFalse();
});

it('handles a missing backup directory as WATCH, not FAIL', function () {
    config(['foundation_monitoring.paths.backup_directory' => 'storage/app/backups/missing-'.uniqid()]);

    $signal = collect(monitoringService()->collect()['signals'])->firstWhere('key', 'deploy_backup');

    expect($signal['status'])->toBe('WATCH')
        ->and($signal['unsafe'])->toBeFalse();
});

it('never auto-runs an expensive audit command on collect()', function () {
    $report = monitoringService()->collect(); // include_audits defaults false

    $audit = collect($report['signals'])->firstWhere('key', 'audit_inventory_procurement');

    expect($audit['details']['executed'])->toBeFalse();
});
