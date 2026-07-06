<?php

use App\Services\Foundation\CicdEnterpriseGateGovernanceService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Foundation', 'FoundationGovernance', 'EnterpriseFoundation', 'Cicd');

it('passes with GO on the repo default configuration', function () {
    $report = app(CicdEnterpriseGateGovernanceService::class)->collect();

    expect($report['decision'])->toBe('GO')
        ->and($report['readiness_status'])->toBe('cicd_enterprise_gate_ready')
        ->and($report['cicd_enterprise_gate_enabled'])->toBeTrue()
        ->and($report['deploy_script_ok'])->toBeTrue()
        ->and($report['deploy_no_destructive_command'])->toBeTrue()
        ->and($report['deploy_migrate_force_present'])->toBeTrue()
        ->and($report['deploy_backup_before_migrate'])->toBeTrue()
        ->and($report['deploy_cache_order_preserved'])->toBeTrue()
        ->and($report['deploy_cache_rebuild_present'])->toBeTrue()
        ->and($report['ci_ok'])->toBeTrue()
        ->and($report['ci_pull_request_trigger'])->toBeTrue()
        ->and($report['ci_fail_fast'])->toBeTrue()
        ->and($report['ci_no_destructive_command'])->toBeTrue()
        ->and($report['evidence_profiles_ok'])->toBeTrue()
        ->and($report['pre_deploy_gate_ok'])->toBeTrue()
        ->and($report['queue_retry_decision'])->toBe('GO')
        ->and($report['idempotency_outbox_decision'])->toBe('GO')
        ->and($report['developer_console_decision'])->toBe('GO')
        ->and($report['health_check_decision'])->toBe('GO')
        ->and($report['security_compliance_decision'])->toBe('GO');

    expect(Artisan::call('foundation:cicd-enterprise-gate-check'))->toBe(0)
        ->and(Artisan::call('foundation:cicd-enterprise-gate-check', ['--strict' => true]))->toBe(0);
});

it('publishes the twelve ENT10 rules', function () {
    $ids = array_column(CicdEnterpriseGateGovernanceService::rules(), 'id');

    foreach (range(1, 12) as $n) {
        expect($ids)->toContain(sprintf('ENT10-CICD%03d', $n));
    }
});

it('emits privacy-safe JSON without a long digit run', function () {
    Artisan::call('foundation:cicd-enterprise-gate-check', ['--json' => true]);
    $output = Artisan::output();

    $decoded = json_decode($output, true);

    expect($decoded)->toBeArray()
        ->and($decoded['sprint'])->toBe('ENT-10')
        ->and($decoded['privacy']['privacy_safe'])->toBeTrue()
        ->and(preg_match('/\d{13,}/', $output))->toBe(0);
});

it('fails when the release-safety pre-deploy gate loses the enterprise gate command', function () {
    config(['cicd_enterprise_gate.required_pre_deploy_gate_commands' => ['foundation:this-command-never-exists']]);

    $report = app(CicdEnterpriseGateGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and($report['pre_deploy_gate_ok'])->toBeFalse();

    expect(Artisan::call('foundation:cicd-enterprise-gate-check'))->toBe(1)
        ->and(Artisan::call('foundation:cicd-enterprise-gate-check', ['--strict' => true]))->toBe(1);
});

it('fails when a required evidence artifact is missing from a profile', function () {
    config(['cicd_enterprise_gate.evidence.artifact' => 'this-artifact-is-not-required.json']);

    $report = app(CicdEnterpriseGateGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and($report['evidence_profiles_ok'])->toBeFalse();
});

it('fails when a destructive command is present in the deploy script scan', function () {
    // Simulate a destructive command sneaking in by forcing the scanner to look
    // for a pattern the deploy script legitimately contains (migrate) — proves
    // the destructive-command guard flips the decision to FAIL.
    config(['cicd_enterprise_gate.forbidden_destructive_patterns' => ['php artisan migrate']]);

    $report = app(CicdEnterpriseGateGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and($report['deploy_no_destructive_command'])->toBeFalse();
});
