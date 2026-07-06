<?php

use App\Services\Foundation\LoadTestBaselineGovernanceService;
use Illuminate\Support\Facades\Artisan;

uses()->group('Foundation', 'FoundationGovernance', 'EnterpriseFoundation', 'LoadTestBaseline');

it('passes with GO on the repo default configuration', function () {
    $report = app(LoadTestBaselineGovernanceService::class)->collect();

    expect($report['decision'])->toBe('GO')
        ->and($report['readiness_status'])->toBe('load_test_baseline_ready')
        ->and($report['load_test_baseline_enabled'])->toBeTrue()
        ->and($report['harness_script_ok'])->toBeTrue()
        ->and($report['harness_fail_fast'])->toBeTrue()
        ->and($report['harness_non_production_guard'])->toBeTrue()
        ->and($report['harness_runs_runner'])->toBeTrue()
        ->and($report['harness_no_destructive_command'])->toBeTrue()
        ->and($report['runner_ok'])->toBeTrue()
        ->and($report['runner_registered'])->toBeTrue()
        ->and($report['dataset_ok'])->toBeTrue()
        ->and($report['target_patients_min'])->toBe(60000)
        ->and($report['target_patients_max'])->toBe(70000)
        ->and($report['scenarios_ok'])->toBeTrue()
        ->and($report['bottlenecks_ok'])->toBeTrue()
        ->and($report['objectives_ok'])->toBeTrue()
        ->and($report['p50_target_ms'])->toBe(200)
        ->and($report['p95_target_ms'])->toBe(300)
        ->and($report['branch_count'])->toBe(5)
        ->and($report['evidence_profiles_ok'])->toBeTrue()
        ->and($report['release_safety_ok'])->toBeTrue()
        ->and($report['backup_dr_decision'])->toBe('GO')
        ->and($report['deployment_rollback_decision'])->toBe('GO');

    expect(Artisan::call('foundation:load-test-baseline-check'))->toBe(0)
        ->and(Artisan::call('foundation:load-test-baseline-check', ['--strict' => true]))->toBe(0);
});

it('publishes the twelve ENT13 rules', function () {
    $ids = array_column(LoadTestBaselineGovernanceService::rules(), 'id');

    foreach (range(1, 12) as $n) {
        expect($ids)->toContain(sprintf('ENT13-LT%03d', $n));
    }
});

it('emits privacy-safe JSON without a long digit run', function () {
    Artisan::call('foundation:load-test-baseline-check', ['--json' => true]);
    $output = Artisan::output();

    $decoded = json_decode($output, true);

    expect($decoded)->toBeArray()
        ->and($decoded['sprint'])->toBe('ENT-13')
        ->and($decoded['privacy']['privacy_safe'])->toBeTrue()
        ->and(preg_match('/\d{13,}/', $output))->toBe(0);
});

it('fails when the load-test harness script is missing', function () {
    config(['load_test_baseline.harness_files.load_test_script' => 'scripts/this-harness-never-exists.sh']);

    $report = app(LoadTestBaselineGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and($report['harness_script_ok'])->toBeFalse();

    expect(Artisan::call('foundation:load-test-baseline-check'))->toBe(1)
        ->and(Artisan::call('foundation:load-test-baseline-check', ['--strict' => true]))->toBe(1);
});

it('fails when the release-safety pre-deploy gate loses the ENT-13 command', function () {
    config(['load_test_baseline.required_pre_deploy_gate_command' => 'foundation:this-command-never-exists']);

    $report = app(LoadTestBaselineGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and($report['release_safety_ok'])->toBeFalse();
});

it('fails when a required evidence artifact is missing from a profile', function () {
    config(['load_test_baseline.evidence.artifact' => 'this-artifact-is-not-required.json']);

    $report = app(LoadTestBaselineGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and($report['evidence_profiles_ok'])->toBeFalse();
});

it('fails when a scenario domain is missing', function () {
    config(['load_test_baseline.scenarios' => [
        'rme_only' => ['domain' => 'rme', 'label' => 'RME only'],
    ]]);

    $report = app(LoadTestBaselineGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and($report['scenarios_ok'])->toBeFalse();
});

it('fails when the latency targets are out of order', function () {
    config(['load_test_baseline.latency_targets.p95_target_ms' => 100]);

    $report = app(LoadTestBaselineGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and($report['objectives_ok'])->toBeFalse();
});

it('fails when a destructive command is present in the harness script', function () {
    config(['load_test_baseline.forbidden_destructive_patterns' => ['php artisan']]);

    $report = app(LoadTestBaselineGovernanceService::class)->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and($report['harness_no_destructive_command'])->toBeFalse();
});
