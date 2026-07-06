<?php

use App\Services\Foundation\SecurityComplianceGovernanceService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Foundation', 'FoundationGovernance', 'EnterpriseFoundation');

it('passes with GO on the repo default configuration', function () {
    $report = app(SecurityComplianceGovernanceService::class)->collect();

    expect($report['decision'])->toBe('GO')
        ->and($report['readiness_status'])->toBe('security_compliance_ready')
        ->and($report['security_compliance_enabled'])->toBeTrue()
        ->and($report['masking_helpers_ok'])->toBeTrue()
        ->and($report['developer_console_masking_enabled'])->toBeTrue()
        ->and($report['view_scan_ok'])->toBeTrue()
        ->and($report['view_scan_findings'])->toBe([])
        ->and($report['export_gating_ok'])->toBeTrue()
        ->and($report['ungated_export_routes'])->toBe([])
        ->and($report['audit_table_exists'])->toBeTrue()
        ->and($report['branch_context_exists'])->toBeTrue()
        ->and($report['never_trust_request_branch_id'])->toBeTrue()
        ->and($report['queue_retry_decision'])->toBe('GO')
        ->and($report['idempotency_outbox_decision'])->toBe('GO')
        ->and($report['developer_console_decision'])->toBe('GO')
        ->and($report['health_check_decision'])->toBe('GO');

    expect(Artisan::call('foundation:security-compliance-check'))->toBe(0)
        ->and(Artisan::call('foundation:security-compliance-check', ['--strict' => true]))->toBe(0);
});

it('publishes the twelve ENT9 rules', function () {
    $ids = array_column(SecurityComplianceGovernanceService::rules(), 'id');

    foreach (range(1, 12) as $n) {
        expect($ids)->toContain(sprintf('ENT9-SEC%03d', $n));
    }
});

it('emits privacy-safe JSON without a long digit run', function () {
    Artisan::call('foundation:security-compliance-check', ['--json' => true]);
    $output = Artisan::output();

    $decoded = json_decode($output, true);

    expect($decoded)->toBeArray()
        ->and($decoded['sprint'])->toBe('ENT-9')
        ->and($decoded['privacy']['privacy_safe'])->toBeTrue()
        ->and(preg_match('/\d{13,}/', $output))->toBe(0);
});

it('fails when a data-export route loses its permission gate', function () {
    config(['security_compliance.export_gating.required_middleware_fragments' => ['this-middleware-never-exists']]);

    $report = app(SecurityComplianceGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and($report['export_gating_ok'])->toBeFalse()
        ->and($report['ungated_export_routes'])->not->toBe([]);

    expect(Artisan::call('foundation:security-compliance-check'))->toBe(1)
        ->and(Artisan::call('foundation:security-compliance-check', ['--strict' => true]))->toBe(1);
});

it('fails when a masking helper is removed', function () {
    config(['security_compliance.masking.helpers' => [
        ['class' => 'App\\Does\\Not\\Exist', 'method' => 'mask'],
    ]]);

    $report = app(SecurityComplianceGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and($report['masking_helpers_ok'])->toBeFalse();
});

it('fails when developer-console masking is disabled', function () {
    config(['developer_console.masking.enabled' => false]);

    $report = app(SecurityComplianceGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and($report['developer_console_masking_enabled'])->toBeFalse();
});
