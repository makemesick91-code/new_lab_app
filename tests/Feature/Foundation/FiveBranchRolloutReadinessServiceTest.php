<?php

use App\Modules\Branch\Models\Branch;
use App\Services\Foundation\FiveBranchRolloutReadinessService;
use Spatie\Permission\Models\Role;

uses()->group('Foundation', 'RolloutReadiness', 'RollFive');

beforeEach(function () {
    seedAccessControl();
});

function rolloutService(): FiveBranchRolloutReadinessService
{
    return app(FiveBranchRolloutReadinessService::class);
}

it('produces a report with a decision, stages, categories and signals', function () {
    $report = rolloutService()->collect();

    expect($report)->toBeArray()
        ->and($report['sprint'])->toBe('ROLL-5-1')
        ->and($report['decision'])->toBeIn(['GO', 'WATCH', 'FAIL', 'UNKNOWN'])
        ->and($report['stages'])->toHaveCount(3)
        ->and($report['signals'])->not->toBeEmpty()
        ->and($report['categories'])->toHaveKey('app_health');
});

it('decide() follows FAIL > WATCH > GO > UNKNOWN precedence', function () {
    $svc = rolloutService();

    expect($svc->decide([['status' => 'GO'], ['status' => 'FAIL'], ['status' => 'WATCH']]))->toBe('FAIL')
        ->and($svc->decide([['status' => 'GO'], ['status' => 'WATCH']]))->toBe('WATCH')
        ->and($svc->decide([['status' => 'GO'], ['status' => 'GO']]))->toBe('GO')
        ->and($svc->decide([['status' => 'UNKNOWN']]))->toBe('UNKNOWN')
        ->and($svc->decide([]))->toBe('UNKNOWN');
});

it('marks branch readiness WATCH (never 500) when fewer than target branches exist', function () {
    // No RME-enabled branches seeded.
    $report = rolloutService()->collect();
    $branch = collect($report['signals'])->firstWhere('key', 'branch_data_readiness');

    expect($branch['status'])->toBe('WATCH')
        ->and($branch['unsafe'])->toBeFalse()
        ->and($branch['details']['rme_enabled_active_branches'])->toBe(0);
});

it('marks branch readiness GO when the target of five RME-enabled branches exists', function () {
    Branch::factory()->count(5)->create(['is_rme_enabled' => true]);

    $report = rolloutService()->collect();
    $branch = collect($report['signals'])->firstWhere('key', 'branch_data_readiness');

    expect($branch['status'])->toBe('GO')
        ->and($branch['details']['rme_enabled_active_branches'])->toBe(5);
});

it('computes per-stage branch-count readiness against the branch target', function () {
    Branch::factory()->count(3)->create(['is_rme_enabled' => true]);

    $stages = collect(rolloutService()->collect()['stages'])->keyBy('key');

    // ROLL-5-1A: the branch-count gate is now surfaced as branch_status; the
    // overall stage status also folds in base readiness (restore drill etc.).
    expect($stages['stage_1']['branch_status'])->toBe('GO')   // needs 1, have 3
        ->and($stages['stage_2']['branch_status'])->toBe('GO') // needs 3, have 3
        ->and($stages['stage_3']['branch_status'])->toBe('WATCH'); // needs 5, have 3
});

it('focuses branch readiness on a requested stage target', function () {
    Branch::factory()->count(1)->create(['is_rme_enabled' => true]);

    $stage1 = rolloutService()->collect(['stage' => 1]);
    $stage3 = rolloutService()->collect(['stage' => 3]);

    $b1 = collect($stage1['signals'])->firstWhere('key', 'branch_data_readiness');
    $b3 = collect($stage3['signals'])->firstWhere('key', 'branch_data_readiness');

    expect($stage1['stage'])->toBe(1)
        ->and($b1['status'])->toBe('GO')      // stage 1 needs 1, have 1
        ->and($stage3['stage'])->toBe(3)
        ->and($b3['status'])->toBe('WATCH');  // stage 3 needs 5, have 1
});

it('flags a role-permission leak as an unsafe FAIL', function () {
    Role::findByName('Kepala Cabang')->givePermissionTo('manage_purchase_order');

    $report = rolloutService()->collect();
    $role = collect($report['signals'])->firstWhere('key', 'role_permission_readiness');

    expect($role['status'])->toBe('FAIL')
        ->and($role['unsafe'])->toBeTrue()
        ->and($report['decision'])->toBe('FAIL');
});

it('keeps role readiness GO when required roles exist without a leak', function () {
    $report = rolloutService()->collect();
    $role = collect($report['signals'])->firstWhere('key', 'role_permission_readiness');

    expect($role['status'])->toBe('GO')
        ->and($role['details']['missing_roles'])->toBe([]);
});

it('returns WATCH for restore drill evidence when none is present', function () {
    $report = rolloutService()->collect();
    $drill = collect($report['signals'])->firstWhere('key', 'restore_drill_evidence');

    expect($drill['status'])->toBe('WATCH')
        ->and($drill['unsafe'])->toBeFalse()
        ->and($drill['details']['evidence_present'])->toBeFalse();
});

it('does not run the capacity smoke unless opted in', function () {
    $off = collect(rolloutService()->collect()['signals'])->firstWhere('key', 'capacity_smoke');
    expect($off['status'])->toBe('UNKNOWN')
        ->and($off['details']['executed'])->toBeFalse();

    $on = collect(rolloutService()->collect(['capacity_smoke' => true])['signals'])->firstWhere('key', 'capacity_smoke');
    expect($on['details']['executed'])->toBeTrue()
        ->and($on['status'])->toBeIn(['GO', 'WATCH', 'FAIL']);
});

it('reuses MON-1 monitoring rather than duplicating it', function () {
    $report = rolloutService()->collect();

    // The app_health signal is derived from MON-1 signals, and the report
    // surfaces MON-1's own decision — proving reuse, not re-implementation.
    $appHealth = collect($report['signals'])->firstWhere('key', 'app_health');

    expect($report)->toHaveKey('monitoring_decision')
        ->and($report['monitoring_decision'])->toBeIn(['GO', 'WATCH', 'FAIL', 'UNKNOWN'])
        ->and($appHealth['details'])->toHaveKey('from_monitoring');
});

it('propagates an unsafe FAIL from the app runtime (debug on in a production-like env)', function () {
    config(['app.debug' => true, 'foundation_monitoring.production_like_environments' => ['testing']]);

    $report = rolloutService()->collect();

    expect($report['decision'])->toBe('FAIL')
        ->and($report['unsafe'])->toBeTrue();
});
