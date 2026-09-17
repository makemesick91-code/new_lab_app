<?php

/**
 * DOCTOR-ACCESS-GLOBAL-DEVICE-ENFORCEMENT-READINESS-1 — the two Half-B
 * blockers that were real, and the one that was not.
 *
 * WHAT THIS SUITE IS GUARDING.
 *
 * `Phase4aPilotPreparationScanner::globalPrerequisiteCheck()` compares two
 * lists in config and asserts that each of the five declared Half-B
 * prerequisites carries a signature. It reads no measurement of any kind. So
 * the single artifact standing between this deployment and a fleet-wide
 * clinical lockout could be satisfied by typing `true` five times — and two of
 * the five were known FALSE in the estate on the day the signature block
 * shipped.
 *
 * Every test below exists to hold one property:
 *
 *     A SIGNATURE NEVER OVERRIDES A MEASUREMENT.
 *
 * and its corollary, which this codebase has had to re-learn in three separate
 * sprints: an input the system does not have produces UNVERIFIED, never a PASS.
 *
 * WHAT A GREEN RUN HERE DOES NOT PROVE. That the fleet may be enforced. The
 * machinery verdict and the world verdict are deliberately two fields, and a
 * test that let them collapse would be re-introducing the defect.
 */

use App\Models\User;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorAccess\Services\DoctorEffectiveBranchResolver;
use App\Modules\DoctorAccess\Services\DoctorGlobalEnforcementReadinessService;
use App\Modules\DoctorAccess\Services\DoctorHalfBRollbackProofService;
use App\Modules\DoctorAccess\Support\DoctorGlobalEnforcementPrerequisite as Prerequisite;
use App\Modules\DoctorDevice\Interfaces\DoctorDeviceRolloutReadinessRepositoryInterface;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Services\Foundation\FeatureFlagService;
use App\Support\Android\AndroidDoctorEnforcementScope;
use App\Support\Android\Phase4aPilotPreparationScanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses()->group('DoctorAccess', 'DoctorDevice', 'Security');

function hbDoctor(string $name = 'drg Half B'): User
{
    $user = User::factory()->create(['name' => $name]);
    $user->assignRole('Doctor');

    Doctor::factory()->create([
        'user_id' => $user->id,
        'name' => $name,
        'is_active' => true,
    ]);

    return $user;
}

/** Put the deployment in the pilot posture this programme actually runs. */
function hbPilotCohort(array $userIds): void
{
    config()->set('doctor_device_enforcement.scope.mode', AndroidDoctorEnforcementScope::MODE_PILOT);
    config()->set('doctor_device_enforcement.scope.pilot.doctor_user_id', null);
    config()->set('doctor_device_enforcement.scope.pilot.doctor_user_ids', implode(',', $userIds));
    config()->set('android_release.enforcement.scope.global_permitted', false);
}

function hbAttest(array $signatures): void
{
    config()->set(
        'android_release.enforcement.global_prerequisites_attested',
        array_merge(
            (array) config('android_release.enforcement.global_prerequisites_attested'),
            $signatures,
        ),
    );
}

function hbReport(): array
{
    return app(DoctorGlobalEnforcementReadinessService::class)->build();
}

function hbRow(array $report, string $prerequisite): array
{
    expect($report['prerequisites'])->toHaveKey($prerequisite);

    return $report['prerequisites'][$prerequisite];
}

/**
 * A flag entry, fetched by ARRAY INDEX rather than by dot path.
 *
 * Flag keys contain dots — `doctor.trusted_device_enforcement` — so
 * `config('feature_flags.flags.doctor.trusted_device_enforcement')` traverses
 * into keys that do not exist and returns null. An assertion written that way
 * compares null to null and passes no matter what the code does.
 * `FeatureFlagService::definitions()` carries the same warning for the same
 * reason.
 *
 * @return array<string,mixed>|null
 */
function hbFlagEntry(string $flag = DoctorAppLoginGate::ENFORCEMENT_FLAG): ?array
{
    return ((array) config('feature_flags.flags'))[$flag] ?? null;
}

/** Row counts for every foundation this engine must never touch. */
function hbFoundationCounts(): array
{
    return [
        'devices' => DoctorDevice::query()->count(),
        'authorizations' => DoctorDeviceAuthorization::query()->count(),
        'credentials' => DoctorDeviceWebAuthnCredential::query()->count(),
        'users' => User::query()->count(),
    ];
}

// ---------------------------------------------------------------------------
// A. THE ROLLBACK PRODUCER — executed, not described
// ---------------------------------------------------------------------------

it('proves the rollback by denying an out-of-cohort doctor under the global posture and admitting them after', function () {
    seedAccessControl();

    $inCohort = hbDoctor('drg In Cohort');
    $outside = hbDoctor('drg Outside Cohort');

    hbPilotCohort([$inCohort->id]);

    $proof = app(DoctorHalfBRollbackProofService::class)->prove();

    expect($proof['measured'])->toBe(Prerequisite::PASS);

    // THE SUBJECT IS THE DOCTOR HALF B WOULD NEWLY CAPTURE. A doctor already
    // inside the cohort is denied before AND after, so they can demonstrate
    // nothing about rolling a widening back.
    expect($proof['subject_user_id'])->toBe($outside->id);

    $steps = collect($proof['steps'])->keyBy('step');

    expect($steps[DoctorHalfBRollbackProofService::STEP_GLOBAL_DENIES]['status'])->toBe(Prerequisite::PASS);
    expect($steps[DoctorHalfBRollbackProofService::STEP_ROLLBACK_RESTORES]['status'])->toBe(Prerequisite::PASS);
    expect($steps[DoctorHalfBRollbackProofService::STEP_GLOBAL_NOT_EFFECTIVE]['status'])->toBe(Prerequisite::PASS);
    expect($steps[DoctorHalfBRollbackProofService::STEP_CONFIG_RESTORED]['status'])->toBe(Prerequisite::PASS);
});

it('restores every posture key it moved, so the deployment is left exactly as it was found', function () {
    seedAccessControl();

    $inCohort = hbDoctor('drg In Cohort');
    hbDoctor('drg Outside Cohort');
    hbPilotCohort([$inCohort->id]);

    $service = app(DoctorHalfBRollbackProofService::class);

    $before = $service->capture();
    $countsBefore = hbFoundationCounts();

    $service->prove();

    // Not "close enough": the same values, key for key. A rehearsal that
    // leaves the posture even slightly moved has armed something.
    expect($service->capture())->toEqual($before);
    expect(hbFoundationCounts())->toEqual($countsBefore);
    expect(config('android_release.enforcement.scope.global_permitted'))->toBeFalse();
});

it('restores the raw feature flag entry, not the boolean it happens to resolve to', function () {
    seedAccessControl();

    $inCohort = hbDoctor('drg In Cohort');
    hbDoctor('drg Outside Cohort');
    hbPilotCohort([$inCohort->id]);

    // An entry with NO recorded override. "No override" and "an override
    // recorded as off" resolve identically and are different facts.
    $flags = (array) config('feature_flags.flags');
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['default'] = false;
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['env_value'] = null;
    config()->set('feature_flags.flags', $flags);

    $entryBefore = hbFlagEntry();

    app(DoctorHalfBRollbackProofService::class)->prove();

    // An earlier form wrote the RESOLVED boolean back into both `default` and
    // `env_value`, so a null override came back as `false`. The resolved value
    // survived, so a value-level comparison saw nothing wrong — the restore was
    // quietly rewriting the fact it was restoring.
    expect(hbFlagEntry())->toEqual($entryBefore);
    expect(hbFlagEntry()['env_value'])->toBeNull();
});

it('restores the captured posture even when the rehearsal throws midway', function () {
    seedAccessControl();

    $inCohort = hbDoctor('drg In Cohort');
    hbDoctor('drg Outside Cohort');
    hbPilotCohort([$inCohort->id]);

    $gate = Mockery::mock(DoctorAppLoginGate::class);
    $gate->shouldReceive('denyBrowserSessionReason')->andThrow(new RuntimeException('probe exploded'));

    $service = new DoctorHalfBRollbackProofService(
        app(DoctorDeviceRolloutReadinessRepositoryInterface::class),
        $gate,
        app(AndroidDoctorEnforcementScope::class),
        app(FeatureFlagService::class),
    );

    $before = $service->capture();
    $proof = $service->prove();

    // THE `finally` IS THE ONLY THING HOLDING THIS.
    //
    // On the happy path the posture is restored mid-rehearsal, so a test that
    // only walks the happy path stays green with the `finally` deleted — this
    // one was found by mutation, not by reading. The throw path is the one
    // where a missing restore would leave a production process running with
    // global enforcement simulated in its own config.
    expect($service->capture())->toEqual($before);
    expect(config('android_release.enforcement.scope.global_permitted'))->toBeFalse();
    expect($proof['measured'])->toBe(Prerequisite::FAIL);
});

it('reports UNVERIFIED rather than PASS when no doctor exists outside the cohort', function () {
    seedAccessControl();

    $only = hbDoctor('drg Only');
    hbPilotCohort([$only->id]);

    $proof = app(DoctorHalfBRollbackProofService::class)->prove();

    // An unmeasurable rollback is UNVERIFIED. This is the property three
    // sibling gates in this programme were shipped without.
    expect($proof['measured'])->toBe(Prerequisite::UNVERIFIED);
    expect($proof['subject_user_id'])->toBeNull();
});

it('reports UNVERIFIED when the live posture already covers every doctor, because a restoration cannot be observed', function () {
    seedAccessControl();

    hbDoctor('drg Anyone');

    config()->set('doctor_device_enforcement.scope.mode', AndroidDoctorEnforcementScope::MODE_UNSCOPED);
    config()->set('android_release.enforcement.scope.global_permitted', true);

    $proof = app(DoctorHalfBRollbackProofService::class)->prove();

    expect($proof['measured'])->toBe(Prerequisite::UNVERIFIED);
});

it('fails when the captured posture is not a coherent rollback target', function () {
    seedAccessControl();

    hbDoctor('drg Outside');

    // A pilot posture whose cohort does not resolve. Restoring it would restore
    // "covers nobody", which is a different posture from the one running.
    hbPilotCohort([]);

    $proof = app(DoctorHalfBRollbackProofService::class)->prove();

    $steps = collect($proof['steps'])->keyBy('step');

    expect($steps[DoctorHalfBRollbackProofService::STEP_POSTURE_CAPTURED]['status'])->toBe(Prerequisite::FAIL);
    expect($proof['measured'])->toBe(Prerequisite::FAIL);
});

it('leaves a non-doctor account untouched by the enforcement it rehearses', function () {
    seedAccessControl();

    $inCohort = hbDoctor('drg In Cohort');
    hbDoctor('drg Outside Cohort');
    hbPilotCohort([$inCohort->id]);

    $kasir = User::factory()->create(['name' => 'Kasir Satu']);
    $kasir->assignRole('Kasir');

    app(DoctorHalfBRollbackProofService::class)->prove();

    // Half B is a DOCTOR device rule. `appliesTo()` is the Doctor role, so no
    // posture this rehearsal simulates can reach any other account — and the
    // rehearsal must not have left one denied on the way out.
    $gate = app(DoctorAppLoginGate::class);

    expect($gate->appliesTo($kasir))->toBeFalse();
    expect($gate->inEnforcementScope($kasir))->toBeFalse();
});

// ---------------------------------------------------------------------------
// A2. THE FOLD — where "zero rows, zero problems" gets in
// ---------------------------------------------------------------------------

it('folds an empty set of statuses to UNVERIFIED and never to PASS', function () {
    // THE DEFECT THIS PINS HAS SHIPPED IN THIS PROGRAMME BEFORE.
    // `spare_device_available_per_branch` took worst() over every branch, and
    // an estate made only of branches that home no doctor reported the named
    // gate PASS while holding zero usable tablets. Arithmetically a pass;
    // operationally a broken query.
    expect(Prerequisite::worst([]))->toBe(Prerequisite::UNVERIFIED);
});

it('fails closed on any status outside its vocabulary', function (mixed $status) {
    // An allow-list, not an exclusion list. A sibling returned PASS by
    // fallthrough for 'BANANA', 'pass' and null.
    expect(Prerequisite::worst([Prerequisite::PASS, $status]))->toBe(Prerequisite::FAIL);
})->with(['BANANA', 'pass', 'Pass', '', 'NOT_APPLICABLE']);

it('ranks UNVERIFIED below PASS and FAIL below everything', function () {
    expect(Prerequisite::worst([Prerequisite::PASS, Prerequisite::PASS]))->toBe(Prerequisite::PASS);
    expect(Prerequisite::worst([Prerequisite::PASS, Prerequisite::UNVERIFIED]))->toBe(Prerequisite::UNVERIFIED);
    expect(Prerequisite::worst([Prerequisite::UNVERIFIED, Prerequisite::FAIL]))->toBe(Prerequisite::FAIL);
});

// ---------------------------------------------------------------------------
// B. CONTRADICTION COVERAGE — a signature may not stand against a measurement
// ---------------------------------------------------------------------------

it('gives every declared global prerequisite a producer, so each one can be contradicted', function () {
    seedAccessControl();

    $report = hbReport();

    foreach ((array) config(Prerequisite::CONFIG_DECLARED) as $name) {
        $row = hbRow($report, (string) $name);

        // The blocker this sprint existed to close: four of five had nothing
        // measuring them, so a signature against them could never be wrong.
        expect($row['has_producer'])->toBeTrue("{$name} has no producer");
        expect($row['measured'])->toBeIn(Prerequisite::STATUSES);
    }

    expect($report['findings'])
        ->not->toContain(DoctorGlobalEnforcementReadinessService::FINDING_NO_PRODUCER);
});

it('fails the gate when a prerequisite is attested true while it measures otherwise', function (string $prerequisite) {
    seedAccessControl();

    // No doctors and no estate, so every measurement is honestly not-PASS.
    hbPilotCohort([]);
    hbAttest([$prerequisite => true]);

    $report = hbReport();
    $row = hbRow($report, $prerequisite);

    expect($row['measured'])->not->toBe(Prerequisite::PASS);
    expect($row['contradiction'])->toBeTrue();
    expect($report['contradicting_prerequisites'])->toContain($prerequisite);
    expect($report['verdict'])->toBe(DoctorGlobalEnforcementReadinessService::VERDICT_BLOCKED);
})->with([
    Prerequisite::REAL_DEVICE_PILOT_PASSED,
    Prerequisite::EVERY_DOCTOR_HAS_ACTIVE_DEVICE,
    Prerequisite::SPARE_DEVICE_PER_BRANCH,
    Prerequisite::DEVICE_LOSS_RUNBOOK_REHEARSED,
    Prerequisite::ROLLBACK_TO_BROWSER_LOGIN_PROVEN,
]);

it('treats UNVERIFIED as contradicting a signature exactly as FAIL does', function () {
    seedAccessControl();

    Storage::fake('local');

    // Nobody has rehearsed a device loss, so the measurement is UNVERIFIED —
    // honestly absent, not failed. Signing it anyway still records something
    // untrue, and "nobody measured it" is not evidence that it holds.
    hbAttest([Prerequisite::DEVICE_LOSS_RUNBOOK_REHEARSED => true]);

    $row = hbRow(hbReport(), Prerequisite::DEVICE_LOSS_RUNBOOK_REHEARSED);

    expect($row['measured'])->toBe(Prerequisite::UNVERIFIED);
    expect($row['contradiction'])->toBeTrue();
});

it('does not invent a contradiction when nothing is signed', function () {
    seedAccessControl();

    $report = hbReport();

    // The ABSENCE of a signature fails nothing. A gate that demanded signatures
    // would be red for the whole of a rollout, and a gate that is red for
    // months gets deleted rather than fixed.
    expect($report['contradicting_prerequisites'])->toBe([]);

    foreach ($report['prerequisites'] as $row) {
        expect($row['contradiction'])->toBeFalse();
    }
});

it('surfaces a declared prerequisite that nothing measures instead of letting it vanish', function () {
    seedAccessControl();

    config()->set(Prerequisite::CONFIG_DECLARED, array_merge(
        (array) config(Prerequisite::CONFIG_DECLARED),
        ['a_prerequisite_nobody_implemented'],
    ));

    $report = hbReport();

    // It iterates the DECLARED list, never the measurement map. A prerequisite
    // somebody adds to config and nobody implements must surface as a producer
    // gap — that is precisely how this whole family of gates went unmeasured
    // for five phases.
    expect($report['prerequisites'])->toHaveKey('a_prerequisite_nobody_implemented');
    expect($report['verdict'])->toBe(DoctorGlobalEnforcementReadinessService::VERDICT_BLOCKED);
    expect(collect($report['findings'])->pluck('finding'))
        ->toContain(DoctorGlobalEnforcementReadinessService::FINDING_NO_PRODUCER);
});

it('reports a declared prerequisite whose signature slot has drifted away', function () {
    seedAccessControl();

    $attested = (array) config(Prerequisite::CONFIG_ATTESTED);
    unset($attested[Prerequisite::ROLLBACK_TO_BROWSER_LOGIN_PROVEN]);
    config()->set(Prerequisite::CONFIG_ATTESTED, $attested);

    $report = hbReport();

    // "No slot" reads identically to "nobody has signed yet" and is in fact
    // "the list and the signatures have drifted apart". Only one of those can
    // be fixed by signing.
    expect(hbRow($report, Prerequisite::ROLLBACK_TO_BROWSER_LOGIN_PROVEN)['signature_slot_missing'])->toBeTrue();
    expect(collect($report['findings'])->pluck('finding'))
        ->toContain(DoctorGlobalEnforcementReadinessService::FINDING_SIGNATURE_SLOT_MISSING);
});

// ---------------------------------------------------------------------------
// C. THE DEVICE-LOSS REHEARSAL EVIDENCE
// ---------------------------------------------------------------------------

it('reads a well-formed device-loss rehearsal as a pass', function () {
    seedAccessControl();
    Storage::fake('local');

    Storage::disk('local')->put(
        (string) config('doctor_global_enforcement_readiness.device_loss_rehearsal.evidence_path'),
        (string) json_encode([
            'schema_version' => 1,
            'drill_id' => 'DL-2026-01',
            'environment' => 'staging',
            'performed_at' => now()->subDays(3)->toIso8601String(),
            'runbook' => 'docs/runbooks/doctor-device-loss.md',
            'outcome' => 'passed',
            'clinician_regained_access' => true,
        ]),
    );

    expect(hbRow(hbReport(), Prerequisite::DEVICE_LOSS_RUNBOOK_REHEARSED)['measured'])
        ->toBe(Prerequisite::PASS);
});

it('refuses a rehearsal that ran and did not restore a clinician', function () {
    seedAccessControl();
    Storage::fake('local');

    Storage::disk('local')->put(
        (string) config('doctor_global_enforcement_readiness.device_loss_rehearsal.evidence_path'),
        (string) json_encode([
            'schema_version' => 1,
            'drill_id' => 'DL-2026-02',
            'environment' => 'staging',
            'performed_at' => now()->subDay()->toIso8601String(),
            'runbook' => 'docs/runbooks/doctor-device-loss.md',
            'outcome' => 'passed',
            'clinician_regained_access' => false,
        ]),
    );

    // A rehearsal that completed and left a clinician locked out is evidence
    // of the opposite thing, so it FAILS rather than being absent.
    expect(hbRow(hbReport(), Prerequisite::DEVICE_LOSS_RUNBOOK_REHEARSED)['measured'])
        ->toBe(Prerequisite::FAIL);
});

it('refuses an unfilled template and a rehearsal dated in the future', function (string $drillId, string $performedAt, string $expected) {
    seedAccessControl();
    Storage::fake('local');

    Storage::disk('local')->put(
        (string) config('doctor_global_enforcement_readiness.device_loss_rehearsal.evidence_path'),
        (string) json_encode([
            'schema_version' => 1,
            'drill_id' => $drillId,
            'environment' => 'staging',
            'performed_at' => $performedAt,
            'runbook' => 'docs/runbooks/doctor-device-loss.md',
            'outcome' => 'passed',
            'clinician_regained_access' => true,
        ]),
    );

    expect(hbRow(hbReport(), Prerequisite::DEVICE_LOSS_RUNBOOK_REHEARSED)['measured'])->toBe($expected);
})->with([
    // A placeholder that validates is a placeholder that gets signed.
    'template' => ['DL-TEMPLATE-001', '2026-09-01T09:00:00+08:00', Prerequisite::UNVERIFIED],

    // A rehearsal that has not happened yet is not evidence that it went well.
    // Written as an explicit direction check because diffInDays() is absolute
    // in some Carbon versions and signed in others.
    'future' => ['DL-2027-01', '2027-01-01T09:00:00+08:00', Prerequisite::FAIL],
]);

// ---------------------------------------------------------------------------
// D. THE SCANNER — where the false green actually lived
// ---------------------------------------------------------------------------

it('passes the contradiction check without measuring anything while nothing is signed', function () {
    seedAccessControl();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $checks = collect(app(Phase4aPilotPreparationScanner::class)->scan()['checks'])->keyBy('id');
    $queries = count(DB::getQueryLog());

    DB::disableQueryLog();

    $row = $checks['global_prerequisite_attestations_do_not_contradict_measurement'];

    expect($row['status'])->toBe('PASS');

    // ZERO COST TODAY, AND THAT IS LOAD-BEARING. This scanner runs in CI and in
    // the release-evidence chain; a governance gate that acquires a database
    // dependency acquires the ability to redden for reasons that have nothing
    // to do with governance.
    expect($queries)->toBe(0);
});

it('fails the scanner when a signature stands against a measurement', function () {
    seedAccessControl();

    hbPilotCohort([]);
    hbAttest([Prerequisite::ROLLBACK_TO_BROWSER_LOGIN_PROVEN => true]);

    $checks = collect(app(Phase4aPilotPreparationScanner::class)->scan()['checks'])->keyBy('id');

    expect($checks['global_prerequisite_attestations_do_not_contradict_measurement']['status'])->toBe('FAIL');
});

it('evaluates the contradiction check in phase 4a, where its signature-only sibling is not applicable', function () {
    seedAccessControl();

    config()->set('android_release.enforcement.governance_phase', Phase4aPilotPreparationScanner::PHASE_4A);

    $checks = collect(app(Phase4aPilotPreparationScanner::class)->scan()['checks'])->keyBy('id');

    // A false signature is not a Phase-5 event: it is recorded NOW, months
    // before the phase moves. So the sibling correctly goes quiet and this one
    // must not — and NOT_APPLICABLE is never a PASS.
    expect($checks['global_prerequisites_attested']['status'])
        ->toBe(Phase4aPilotPreparationScanner::STATUS_NOT_APPLICABLE);
    expect($checks['global_prerequisite_attestations_do_not_contradict_measurement']['status'])->toBe('PASS');
});

// ---------------------------------------------------------------------------
// E. THE TWO VERDICTS, AND HALF A
// ---------------------------------------------------------------------------

it('keeps the machinery verdict and the world verdict as two separate fields', function () {
    seedAccessControl();

    $report = hbReport();

    // The expected and correct state of this deployment is a green machinery
    // verdict beside a not-green world verdict: the instruments are honest and
    // they are saying do not activate. Collapsing the two would either redden
    // the machinery over a purchase order or let a green headline imply the
    // fleet is ready.
    expect($report)->toHaveKeys(['verdict', 'activation_prerequisites']);
    expect($report['authorizes_activation'])->toBeFalse();
});

it('never arms half b and never disturbs half a', function () {
    seedAccessControl();

    $inCohort = hbDoctor('drg In Cohort');
    hbDoctor('drg Outside Cohort');
    hbPilotCohort([$inCohort->id]);

    $singleSession = app(DoctorAppLoginGate::class);

    // Fetched by array index. Written as a dot path — which is how this test
    // first shipped — both sides read null and the loop below asserted nothing
    // at all. Flag keys contain dots; `config()` cannot address them.
    $flags = [
        DoctorEffectiveBranchResolver::FLAG_SINGLE_ACTIVE_SESSION => hbFlagEntry(
            DoctorEffectiveBranchResolver::FLAG_SINGLE_ACTIVE_SESSION,
        ),
        DoctorEffectiveBranchResolver::FLAG_BRANCH_LOCK => hbFlagEntry(
            DoctorEffectiveBranchResolver::FLAG_BRANCH_LOCK,
        ),
    ];

    // The fixture has to actually contain them, or the comparison is vacuous.
    foreach ($flags as $key => $before) {
        expect($before)->not->toBeNull("flag {$key} absent from the fixture");
    }

    hbReport();

    // Half A rides `single_active_session`, which this engine never reads and
    // must never move. Half B stays unarmed: global_permitted is a
    // source-controlled false and nothing here can reach it.
    foreach ($flags as $key => $before) {
        expect(hbFlagEntry($key))->toEqual($before);
    }

    expect(config('android_release.enforcement.scope.global_permitted'))->toBeFalse();
    expect($singleSession->enforcementEnabled())->toBeFalse();
});
