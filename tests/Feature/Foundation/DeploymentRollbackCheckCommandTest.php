<?php

use App\Services\Foundation\DeploymentRollbackGovernanceService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Foundation', 'FoundationGovernance', 'EnterpriseFoundation', 'DeploymentRollback');

it('passes with GO on the repo default configuration', function () {
    $report = app(DeploymentRollbackGovernanceService::class)->collect();

    expect($report['decision'])->toBe('GO')
        ->and($report['readiness_status'])->toBe('deployment_rollback_ready')
        ->and($report['deployment_rollback_enabled'])->toBeTrue()
        ->and($report['deploy_script_ok'])->toBeTrue()
        ->and($report['deploy_backup_before_migrate'])->toBeTrue()
        ->and($report['deploy_no_destructive_command'])->toBeTrue()
        ->and($report['deploy_cache_order_preserved'])->toBeTrue()
        ->and($report['rollback_script_ok'])->toBeTrue()
        ->and($report['rollback_present'])->toBeTrue()
        ->and($report['rollback_fail_fast'])->toBeTrue()
        ->and($report['rollback_records_current_ref'])->toBeTrue()
        ->and($report['rollback_backup_before_checkout'])->toBeTrue()
        ->and($report['rollback_no_destructive_command'])->toBeTrue()
        ->and($report['rollback_reverifies_foundation_gates'])->toBeTrue()
        ->and($report['evidence_profiles_ok'])->toBeTrue()
        ->and($report['release_safety_ok'])->toBeTrue()
        ->and($report['cicd_enterprise_gate_decision'])->toBe('GO')
        ->and($report['security_compliance_decision'])->toBe('GO')
        ->and($report['health_check_decision'])->toBe('GO');

    expect(Artisan::call('foundation:deployment-rollback-check'))->toBe(0)
        ->and(Artisan::call('foundation:deployment-rollback-check', ['--strict' => true]))->toBe(0);
});

it('publishes the twelve ENT11 rules', function () {
    $ids = array_column(DeploymentRollbackGovernanceService::rules(), 'id');

    foreach (range(1, 12) as $n) {
        expect($ids)->toContain(sprintf('ENT11-DR%03d', $n));
    }
});

it('emits privacy-safe JSON without a long digit run', function () {
    Artisan::call('foundation:deployment-rollback-check', ['--json' => true]);
    $output = Artisan::output();

    $decoded = json_decode($output, true);

    expect($decoded)->toBeArray()
        ->and($decoded['sprint'])->toBe('ENT-11')
        ->and($decoded['privacy']['privacy_safe'])->toBeTrue()
        ->and(preg_match('/\d{13,}/', $output))->toBe(0);
});

it('fails when the rollback script is missing', function () {
    config(['deployment_rollback.automation_files.rollback_script' => 'scripts/this-rollback-script-never-exists.sh']);

    $report = app(DeploymentRollbackGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and($report['rollback_script_ok'])->toBeFalse()
        ->and($report['rollback_present'])->toBeFalse();

    expect(Artisan::call('foundation:deployment-rollback-check'))->toBe(1)
        ->and(Artisan::call('foundation:deployment-rollback-check', ['--strict' => true]))->toBe(1);
});

it('fails when the release-safety pre-deploy gate loses the ENT-11 command', function () {
    config(['deployment_rollback.required_pre_deploy_gate_command' => 'foundation:this-command-never-exists']);

    $report = app(DeploymentRollbackGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and($report['release_safety_ok'])->toBeFalse();
});

it('fails when a required evidence artifact is missing from a profile', function () {
    config(['deployment_rollback.evidence.artifact' => 'this-artifact-is-not-required.json']);

    $report = app(DeploymentRollbackGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and($report['evidence_profiles_ok'])->toBeFalse();
});

it('fails when a destructive command is present in the rollback script scan', function () {
    // Force the scanner to look for a pattern the rollback script legitimately
    // contains (git checkout) — proves the destructive-command guard flips the
    // decision to FAIL for the rollback script specifically.
    config(['deployment_rollback.forbidden_destructive_patterns' => ['git checkout']]);

    $report = app(DeploymentRollbackGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and($report['rollback_no_destructive_command'])->toBeFalse();
});
