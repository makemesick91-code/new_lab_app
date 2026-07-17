<?php

use App\Models\User;
use App\Modules\Satusehat\Models\SatusehatBranchPilotProfile;
use App\Modules\Satusehat\Models\SatusehatUatRun;
use App\Modules\Satusehat\Services\Pilot\SatusehatBranchReadinessProfileService;
use App\Modules\Satusehat\Services\Pilot\SatusehatIncidentDrillService;
use App\Modules\Satusehat\Services\Pilot\SatusehatMultiBranchRehearsalService;
use App\Modules\Satusehat\Services\Pilot\SatusehatRolloutWaveService;
use App\Modules\Satusehat\Services\Pilot\SatusehatUatService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    config()->set('satusehat.candidate.auto_generate', false);
    config()->set('satusehat.enabled', false);
    config()->set('satusehat.send_enabled', false);
    config()->set('satusehat.production_enabled', false);
    config()->set('satusehat.production_approved', false);
    config()->set('satusehat.environment', 'sandbox');
    Http::preventStrayRequests();
    seedAccessControl();
});

it('signs off a UAT run only when all required roles approve and no scenario fails', function () {
    $uat = app(SatusehatUatService::class);
    $wave = app(SatusehatRolloutWaveService::class);
    $actor = User::factory()->create();
    $a = ssMakeVisit(['visit_date' => now()->toDateString()]);

    $w = $wave->createWave(['name' => 'UAT Wave'], $actor);
    $wave->enrollBranch($w, $a['branch'], $actor);
    // Branches are profiled before a UAT session runs.
    app(SatusehatBranchReadinessProfileService::class)
        ->recalculate($a['branch']->id);

    $run = $uat->createRun(['title' => 'Pilot UAT', 'rollout_wave_id' => $w->id], $actor);
    $uat->recordScenario($run, ['scenario_code' => 'AK-01', 'role' => 'admin_klinik', 'outcome' => 'pass', 'operator_name' => 'Op A'], $actor);

    // Cannot finalize before all required roles sign off.
    expect(fn () => $uat->finalize($run, $actor))->toThrow(ValidationException::class);

    foreach (config('satusehat_pilot.uat.required_signoff_roles') as $role) {
        $uat->recordSignoff($run, ['role' => $role, 'decision' => 'approved', 'operator_name' => 'Operator '.$role], $actor);
    }

    $final = $uat->finalize($run, $actor);
    expect($final->status)->toBe(SatusehatUatRun::STATUS_SIGNED_OFF);

    // Enrolled branch profile stamped UAT-passed.
    $profile = SatusehatBranchPilotProfile::where('branch_id', $a['branch']->id)->first();
    expect($profile?->uat_status)->toBe('passed')
        ->and($profile?->last_uat_signed_off_at)->not->toBeNull();

    Http::assertNothingSent();
});

it('blocks UAT sign-off when one operator attests multiple roles (segregation of duties)', function () {
    $uat = app(SatusehatUatService::class);
    $actor = User::factory()->create();

    $run = $uat->createRun(['title' => 'SoD UAT'], $actor);
    $uat->recordScenario($run, ['scenario_code' => 'X', 'role' => 'admin_klinik', 'outcome' => 'pass', 'operator_name' => 'Solo'], $actor);
    // Same operator name for every required role → SoD violation at finalize.
    foreach (config('satusehat_pilot.uat.required_signoff_roles') as $role) {
        $uat->recordSignoff($run, ['role' => $role, 'decision' => 'approved', 'operator_name' => 'Solo Operator'], $actor);
    }

    expect(fn () => $uat->finalize($run, $actor))->toThrow(ValidationException::class);
});

it('blocks UAT sign-off when a scenario failed', function () {
    $uat = app(SatusehatUatService::class);
    $actor = User::factory()->create();

    $run = $uat->createRun(['title' => 'Failing UAT'], $actor);
    $uat->recordScenario($run, ['scenario_code' => 'D-01', 'role' => 'doctor', 'outcome' => 'fail', 'finding_severity' => 'high', 'operator_name' => 'Dr X'], $actor);
    foreach (config('satusehat_pilot.uat.required_signoff_roles') as $role) {
        $uat->recordSignoff($run, ['role' => $role, 'decision' => 'approved', 'operator_name' => 'Op '.$role], $actor);
    }

    expect(fn () => $uat->finalize($run, $actor))->toThrow(ValidationException::class);
});

it('runs a multi-branch synthetic rehearsal with no network and no external submission', function () {
    $wave = app(SatusehatRolloutWaveService::class);
    $actor = User::factory()->create();
    $a = ssMakeVisit(['visit_date' => now()->toDateString()]);
    $b = ssMakeVisit(['visit_date' => now()->toDateString()]);

    $w = $wave->createWave(['name' => 'Rehearsal Wave'], $actor);
    $wave->enrollBranch($w, $a['branch'], $actor);
    $wave->enrollBranch($w, $b['branch'], $actor);

    $result = app(SatusehatMultiBranchRehearsalService::class)->run($w, $actor, true);

    expect($result['branch_results'])->toHaveCount(2)
        ->and($result['external_submitted'])->toBeFalse()
        ->and($result['final_wave_state'])->toBeIn([
            SatusehatMultiBranchRehearsalService::WAVE_BLOCKED_EXTERNAL_CREDENTIAL,
            SatusehatMultiBranchRehearsalService::WAVE_INCOMPLETE,
        ]);

    Http::assertNothingSent();
});

it('governance audit is GO/WATCH when safe and FAIL when a kill switch is off', function () {
    // Safe posture → GO or WATCH (exit 0 non-strict).
    expect(Artisan::call('satusehat:governance-audit'))->toBe(0);

    // Flip external send on → FAIL, non-zero exit.
    config()->set('satusehat.send_enabled', true);
    expect(Artisan::call('satusehat:governance-audit'))->toBe(1);
    $out = Artisan::output();
    expect($out)->toContain('FAIL');
});

it('incident-drill safety invariants pass while kill switches are off', function () {
    $result = app(SatusehatIncidentDrillService::class)->runSafetyInvariants(User::factory()->create());
    expect($result['overall'])->toBe('PASS')
        ->and($result['drills'])->toHaveCount(3);

    Http::assertNothingSent();
});
