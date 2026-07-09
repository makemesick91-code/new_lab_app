<?php

use App\Modules\Branch\Models\Branch;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;
use Symfony\Component\Console\Output\BufferedOutput;

uses()->group('Foundation', 'RolloutReadiness', 'RollFive');

beforeEach(function () {
    seedAccessControl();
});

function rolloutCommandJson(array $params = ['--json' => true]): string
{
    $output = new BufferedOutput;
    Artisan::call('rollout:five-branch-readiness', $params, $output);

    return $output->fetch();
}

it('runs the default lightweight check and exits 0', function () {
    $this->artisan('rollout:five-branch-readiness')
        ->assertExitCode(0)
        ->expectsOutputToContain('ROLL-5-1');
});

it('emits parseable JSON with a decision, stages and signals', function () {
    $this->artisan('rollout:five-branch-readiness --json')->assertExitCode(0);

    $json = json_decode(rolloutCommandJson(), true);

    expect($json)->toBeArray()
        ->and($json)->toHaveKeys(['decision', 'unsafe', 'summary', 'stages', 'signals'])
        ->and($json['decision'])->toBeIn(['GO', 'WATCH', 'FAIL', 'UNKNOWN'])
        ->and($json['stages'])->toHaveCount(3);
});

it('exits 0 on a WATCH state even with --strict (WATCH is not unsafe)', function () {
    // Default test state has no branches/backups => at worst WATCH, never FAIL.
    $this->artisan('rollout:five-branch-readiness --strict')->assertExitCode(0);
});

it('exits non-zero on --strict when a real unsafe FAIL is present', function () {
    config(['app.debug' => true, 'foundation_monitoring.production_like_environments' => ['testing']]);

    $this->artisan('rollout:five-branch-readiness --strict')->assertExitCode(1);
});

it('exits non-zero on --fail-on-warning when the state is WATCH', function () {
    // No branches seeded => branch readiness WATCH => overall WATCH.
    $this->artisan('rollout:five-branch-readiness --fail-on-warning')->assertExitCode(1);
});

it('exits non-zero on --strict when a role-permission leak is present', function () {
    Role::findByName('Kepala Cabang')->givePermissionTo('manage_purchase_order');

    $this->artisan('rollout:five-branch-readiness --strict')->assertExitCode(1);
});

it('honours the --stage option for branch-count focus', function () {
    Branch::factory()->count(1)->create(['is_rme_enabled' => true]);

    $stage1 = json_decode(rolloutCommandJson(['--json' => true, '--stage' => 1]), true);
    $stage3 = json_decode(rolloutCommandJson(['--json' => true, '--stage' => 3]), true);

    expect($stage1['stage'])->toBe(1)
        ->and($stage3['stage'])->toBe(3);

    $b1 = collect($stage1['signals'])->firstWhere('key', 'branch_data_readiness');
    $b3 = collect($stage3['signals'])->firstWhere('key', 'branch_data_readiness');

    expect($b1['status'])->toBe('GO')       // stage 1 needs 1, have 1
        ->and($b3['status'])->toBe('WATCH'); // stage 3 needs 5, have 1
});

it('runs the capacity smoke only when --capacity-smoke is passed', function () {
    $json = json_decode(rolloutCommandJson(['--json' => true, '--capacity-smoke' => true]), true);

    $probe = collect($json['signals'])->firstWhere('key', 'capacity_smoke');

    expect($json['capacity_smoke'])->toBeTrue()
        ->and($probe['details']['executed'])->toBeTrue();
});

it('reports existing audit command status with --include-audits without replacing them', function () {
    $this->artisan('rollout:five-branch-readiness --include-audits')->assertExitCode(0);

    $json = json_decode(rolloutCommandJson(['--include-audits' => true, '--json' => true]), true);

    // MON-1's own audit signals (doctor-performance + inventory) execute under
    // include-audits and their exit status is surfaced — never re-implemented.
    $audit = collect($json['signals'])->firstWhere('key', 'audit_command_readiness');

    expect($json['include_audits'])->toBeTrue()
        ->and($audit)->not->toBeNull()
        ->and($audit['details']['commands_registered'])->toHaveKey('inventory:procurement-workflow-audit')
        ->and($audit['details']['commands_registered'])->toHaveKey('rme:doctor-performance-access-audit');
});
