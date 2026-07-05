<?php

use App\Services\Foundation\ReportingSummaryGovernanceService;
use App\Services\Foundation\ReportingSummaryRefreshService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Foundation', 'ReportingSummary', 'Rpt1');

it('reporting summary governance config exists and defines safe defaults', function () {
    $config = config('reporting_summary_governance');

    expect($config)->toBeArray()
        ->and($config['metadata']['sprint'])->toBe('RPT-1')
        ->and($config['summary_runtime_policy']['runtime_reads_from_summary_enabled'])->toBeFalse()
        ->and($config['summary_runtime_policy']['auto_refresh_enabled'])->toBeFalse()
        ->and($config['summary_runtime_policy']['scheduled_refresh_allowed'])->toBeFalse()
        ->and($config['summary_runtime_policy']['queue_refresh_allowed'])->toBeFalse()
        ->and($config['materialized_view_readiness']['unique_index_required_for_concurrent'])->toBeTrue()
        ->and($config['materialized_view_readiness']['refresh_concurrently_default'])->toBeFalse();
});

it('allowed categories define source, branch, refresh, freshness, reconciliation, and no PII', function () {
    foreach (config('reporting_summary_governance.allowed_summary_categories') as $key => $category) {
        expect($category['source_tables'] ?? [])->not->toBeEmpty("{$key} source tables")
            ->and($category['branch_scope'] ?? null)->not->toBeNull("{$key} branch scope")
            ->and($category['refresh_strategy'] ?? null)->not->toBeNull("{$key} refresh strategy")
            ->and($category['freshness_sla'] ?? null)->not->toBeNull("{$key} freshness")
            ->and($category['pii_allowed'] ?? true)->toBeFalse("{$key} pii")
            ->and($category['reconciliation_required'] ?? false)->toBeTrue("{$key} reconciliation")
            ->and($category['runtime_source_switch_allowed'] ?? true)->toBeFalse("{$key} runtime switch");
    }
});

it('denied reporting summary categories include PII and raw sensitive payloads', function () {
    $denied = config('reporting_summary_governance.denied_summary_categories');

    expect($denied)->toContain('patient.identity_pii')
        ->and($denied)->toContain('rme.medical_record_content')
        ->and($denied)->toContain('cashier.raw_payment_sensitive_payload')
        ->and($denied)->toContain('document.private_scan_payload');
});

it('foundation reporting summary check returns GO and JSON includes status', function () {
    $exitCode = Artisan::call('foundation:reporting-summary-check', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['summary']['decision'])->toBe('GO')
        ->and($payload['runtime_reads_from_summary_enabled'])->toBeFalse()
        ->and($payload['auto_refresh_enabled'])->toBeFalse()
        ->and($payload['privacy']['privacy_safe'])->toBeTrue();
});

it('db inventory mode is safe and non-blocking', function () {
    $report = app(ReportingSummaryGovernanceService::class)->collect(includeDbInventory: true);

    expect($report['summary']['decision'])->toBeIn(['GO', 'WATCH'])
        ->and($report['db_inventory_requested'])->toBeTrue()
        ->and($report['privacy']['row_level_data'])->toBeFalse();
});

it('refresh command defaults to dry-run and does not write', function () {
    $exitCode = Artisan::call('foundation:reporting-summary-refresh', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['mode'])->toBe('dry_run')
        ->and($payload['writes_performed'])->toBeFalse()
        ->and($payload['summary']['decision'])->toBe('GO');
});

it('execute refresh requires execute and confirm', function () {
    $report = app(ReportingSummaryRefreshService::class)->preview('reporting.owner_daily_payment_trend', execute: true, confirm: false);

    expect($report['summary']['decision'])->toBe('FAIL')
        ->and($report['writes_performed'])->toBeFalse();
});
