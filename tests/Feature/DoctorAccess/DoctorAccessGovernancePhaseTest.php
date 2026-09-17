<?php

use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Support\Android\AndroidDoctorEnforcementScope;
use App\Support\Android\AndroidReleaseGovernanceScanner;
use App\Support\Android\Phase4aPilotPreparationScanner;

uses()->group('DoctorAccess', 'DoctorDevice', 'Android', 'Security');

/**
 * DOCTOR-ACCESS-GLOBAL-ACTIVATION-BLOCKER-CLOSURE-1 (B2) — a gate that does not
 * redden on the outcome the programme was built to reach.
 *
 * THE DEFECT. Four checks in the preparation scanner, and three in its release
 * sibling, assert that fleet-wide doctor enforcement is neither permitted,
 * declared, nor live. Every one of them is correct for Phase 4A. Every one of
 * them would FAIL on the exact state a Phase 5 activation is supposed to reach.
 * A gate that fails on success is a gate operators learn to ignore, and an
 * ignored gate protects nothing — the same argument
 * DOCTOR-PWA-GLOBAL-ROLLOUT-READINESS-1 already made about `enforcement_inactive`
 * and then applied to only that one check, leaving its siblings behind.
 *
 * WHAT WAS DONE ABOUT IT. Not a deletion and not a PASS. The phase is declared
 * in source control, and the Phase-4A-exclusive checks report a distinct
 * NOT_APPLICABLE status outside it — visible in the report, counted separately,
 * and never contributing to `passed`. The halves that never stop mattering keep
 * evaluating in every phase, and the prohibition is replaced by a Phase-5
 * PRECONDITION rather than by nothing.
 *
 * WHAT THIS SUITE IS NOT. It is not an activation. Every phase other than
 * `phase_4a` exists here only in an in-memory config override inside a test.
 * The shipped declaration is `phase_4a`, pinned below, and nothing in this file
 * writes to a host, a flag, a cohort or the database.
 */
function govPhase(string $phase): void
{
    config()->set('android_release.enforcement.governance_phase', $phase);
}

function govScanner(): Phase4aPilotPreparationScanner
{
    return app(Phase4aPilotPreparationScanner::class);
}

function govCheck(string $id): ?array
{
    return collect(govScanner()->scan()['checks'])->firstWhere('id', $id);
}

/** Arm or disarm the enforcement flag the way a deployment would. */
function govArm(bool $on): void
{
    $flags = config('feature_flags.flags', []);
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['default'] = $on;
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['env_value'] = $on;
    config()->set('feature_flags.flags', $flags);
}

/**
 * Put the deployment in the shape a real fleet-wide activation would have:
 * unscoped mode, permission granted in source control, flag armed.
 */
function govGlobalLive(bool $live): void
{
    config()->set('android_release.enforcement.scope', array_merge(
        (array) config('android_release.enforcement.scope'),
        ['global_permitted' => $live],
    ));

    config()->set('doctor_device_enforcement.scope', array_merge(
        (array) config('doctor_device_enforcement.scope'),
        ['mode' => $live ? 'unscoped' : 'pilot'],
    ));

    govArm($live);
}

/** Record an attestation against every declared global prerequisite. */
function govAttestAll(bool $attested): void
{
    /*
     * BOTH prerequisite lists, because "attest everything" has to keep meaning
     * everything. REVISION-DOCTOR-TRUSTED-DEVICE-ESTATE-CAPACITY-POLICY-1 added
     * `activation_test_prerequisites` — the estate bar for controlled
     * activation TESTING, which unlike its Phase-5 sibling is evaluated INSIDE
     * phase_4a. A helper that signed only the older list would leave a genuine
     * FAIL standing in every scan it set up, and the tests that use it to prove
     * something ELSE about the verdict would start failing for a reason they do
     * not name.
     */
    foreach ([
        'android_release.enforcement.global_prerequisites' => 'android_release.enforcement.global_prerequisites_attested',
        'android_release.enforcement.activation_test_prerequisites' => 'android_release.enforcement.activation_test_prerequisites_attested',
    ] as $declaredPath => $attestedPath) {
        $declared = (array) config($declaredPath, []);

        config()->set(
            $attestedPath,
            array_fill_keys(array_map('strval', $declared), $attested),
        );
    }
}

// ---------------------------------------------------------------------------
// A. THE SHIPPED DECLARATION
// ---------------------------------------------------------------------------

it('ships declaring phase_4a, so every scoped check is fully evaluated as before', function () {
    expect(config('android_release.enforcement.governance_phase'))->toBe('phase_4a');
    expect(govScanner()->inPhase4a())->toBeTrue();
    expect(govScanner()->scan()['summary']['governance_phase'])->toBe('phase_4a');
});

it('ships every global prerequisite UNATTESTED, so declaring a later phase cannot pass on its own', function () {
    $attested = (array) config('android_release.enforcement.global_prerequisites_attested');

    expect($attested)->not->toBe([]);

    foreach ($attested as $name => $value) {
        expect($value)->toBeFalse("Prerequisite {$name} ships attested, which it must not.");
    }
});

it('falls back to the strictest phase when the declaration is unrecognised', function () {
    govPhase('phase_9_does_not_exist');

    // A typo must tighten the audit, never silently disable four checks.
    expect(govScanner()->governancePhase())->toBe(Phase4aPilotPreparationScanner::PHASE_4A);
    expect(govScanner()->inPhase4a())->toBeTrue();
});

// ---------------------------------------------------------------------------
// B. READINESS MODE — the rule is unchanged
// ---------------------------------------------------------------------------

it('passes in readiness mode while fleet-wide enforcement is not permitted', function () {
    govPhase(Phase4aPilotPreparationScanner::PHASE_4A);
    govGlobalLive(false);

    expect(govCheck('global_scope_not_permitted_in_phase_4a')['status'])->toBe('PASS');
    expect(govCheck('global_enforcement_not_active')['status'])->toBe('PASS');
});

it('still FAILS in readiness mode the moment fleet-wide enforcement is permitted', function () {
    govPhase(Phase4aPilotPreparationScanner::PHASE_4A);

    config()->set('android_release.enforcement.scope', array_merge(
        (array) config('android_release.enforcement.scope'),
        ['global_permitted' => true],
    ));

    // The permission alone, with nobody newly enforced, is still the reviewed
    // decision Phase 4A forbids. Scoping the check by phase must not have
    // softened it inside the phase it was written for.
    expect(govCheck('global_scope_not_permitted_in_phase_4a')['status'])->toBe('FAIL');
    expect(govScanner()->scan()['status'])->toBe('FAIL');
});

// ---------------------------------------------------------------------------
// C. ACTIVATION TARGET — prerequisites become the gate
// ---------------------------------------------------------------------------

it('does not redden on a permitted scope once the activation target phase is declared', function () {
    govPhase(Phase4aPilotPreparationScanner::PHASE_GLOBAL_ACTIVATION_TARGET);
    govGlobalLive(false);

    config()->set('android_release.enforcement.scope', array_merge(
        (array) config('android_release.enforcement.scope'),
        ['global_permitted' => true],
    ));

    $check = govCheck('global_scope_not_permitted_in_phase_4a');

    expect($check['status'])->toBe(Phase4aPilotPreparationScanner::STATUS_NOT_APPLICABLE);
    expect($check['detail'])->toContain('Not evaluated');
});

it('FAILS the activation target phase while any prerequisite is unattested', function () {
    govPhase(Phase4aPilotPreparationScanner::PHASE_GLOBAL_ACTIVATION_TARGET);
    govAttestAll(false);

    $check = govCheck('global_prerequisites_attested');

    expect($check['status'])->toBe('FAIL');
    expect($check['detail'])->toContain('spare_device_available_per_branch');
    expect(govScanner()->scan()['status'])->toBe('FAIL');
});

it('FAILS when only some prerequisites are attested, naming the ones that are not', function () {
    govPhase(Phase4aPilotPreparationScanner::PHASE_GLOBAL_ACTIVATION_TARGET);
    govAttestAll(true);

    config()->set('android_release.enforcement.global_prerequisites_attested', array_merge(
        (array) config('android_release.enforcement.global_prerequisites_attested'),
        ['spare_device_available_per_branch' => false],
    ));

    $check = govCheck('global_prerequisites_attested');

    expect($check['status'])->toBe('FAIL');
    expect($check['detail'])->toContain('spare_device_available_per_branch');
    expect($check['detail'])->not->toContain('real_device_pilot_passed');
});

it('PASSES the prerequisite gate only when every declared prerequisite is attested', function () {
    govPhase(Phase4aPilotPreparationScanner::PHASE_GLOBAL_ACTIVATION_TARGET);
    govAttestAll(true);

    expect(govCheck('global_prerequisites_attested')['status'])->toBe('PASS');
});

it('refuses to treat an empty prerequisite list as a satisfied one', function () {
    govPhase(Phase4aPilotPreparationScanner::PHASE_GLOBAL_ACTIVATION_TARGET);
    config()->set('android_release.enforcement.global_prerequisites', []);

    expect(govCheck('global_prerequisites_attested')['status'])->toBe('FAIL');
});

it('does not evaluate the prerequisite gate at all inside phase_4a', function () {
    govPhase(Phase4aPilotPreparationScanner::PHASE_4A);

    expect(govCheck('global_prerequisites_attested')['status'])
        ->toBe(Phase4aPilotPreparationScanner::STATUS_NOT_APPLICABLE);
});

// ---------------------------------------------------------------------------
// D. POST-ACTIVATION — the assertion inverts rather than switching off
// ---------------------------------------------------------------------------

it('PASSES once fleet-wide enforcement is live and the activated phase is declared', function () {
    govPhase(Phase4aPilotPreparationScanner::PHASE_GLOBAL_ACTIVATED);
    govGlobalLive(true);

    expect(govScanner()->globalEnforcementActiveLive())->toBeTrue();
    expect(govCheck('global_enforcement_active')['status'])->toBe('PASS');

    // The Phase-4A-shaped row is gone entirely rather than passing vacuously.
    expect(govCheck('global_enforcement_not_active'))->toBeNull();
});

it('FAILS a declared activation that did not actually take', function () {
    govPhase(Phase4aPilotPreparationScanner::PHASE_GLOBAL_ACTIVATED);
    govGlobalLive(false);

    // Declaring Phase 5 while enforcing nobody is a degraded deployment, not a
    // quiet pass. An activation that silently failed to take is exactly what
    // this direction of the assertion exists to surface.
    $check = govCheck('global_enforcement_active');

    expect($check['status'])->toBe('FAIL');
    expect($check['detail'])->toContain('does not have it');
});

// ---------------------------------------------------------------------------
// E. WHAT NEVER STOPS MATTERING
// ---------------------------------------------------------------------------

it('still FAILS an enforcement flag armed over a scope covering nobody, in every phase', function (string $phase) {
    govPhase($phase);

    // Armed, but the cohort resolves to nobody: protection that protects
    // nothing. This half of enforcement_inactive is never phase-scoped.
    config()->set('android_release.enforcement.scope', array_merge(
        (array) config('android_release.enforcement.scope'),
        ['global_permitted' => false],
    ));
    config()->set('doctor_device_enforcement.scope', array_merge(
        (array) config('doctor_device_enforcement.scope'),
        ['mode' => 'pilot', 'pilot' => ['doctor_user_id' => null, 'doctor_user_ids' => '', 'branch_code' => '']],
    ));
    govArm(true);

    expect(app(AndroidDoctorEnforcementScope::class)->isUsable())->toBeFalse();
    expect(govCheck('enforcement_inactive')['status'])->toBe('FAIL');
})->with([
    Phase4aPilotPreparationScanner::PHASE_4A,
    Phase4aPilotPreparationScanner::PHASE_GLOBAL_ACTIVATION_TARGET,
    Phase4aPilotPreparationScanner::PHASE_GLOBAL_ACTIVATED,
]);

it('still catches a deployment enforcing more than source control declares', function () {
    govPhase(Phase4aPilotPreparationScanner::PHASE_GLOBAL_ACTIVATION_TARGET);
    govGlobalLive(true);

    // Observed `global` against a declared `bounded_pilot` is the widening
    // nobody reviewed. Phase scoping removed the special case; it must not have
    // removed the ceiling underneath it.
    config()->set('android_release.enforcement.expected_posture', 'bounded_pilot');

    expect(govScanner()->observedPosture())->toBe(Phase4aPilotPreparationScanner::POSTURE_GLOBAL);
    expect(govCheck('enforcement_posture')['status'])->toBe('FAIL');
});

it('accepts a global posture only when source control declares global', function () {
    govPhase(Phase4aPilotPreparationScanner::PHASE_GLOBAL_ACTIVATED);
    govGlobalLive(true);
    config()->set('android_release.enforcement.expected_posture', Phase4aPilotPreparationScanner::POSTURE_GLOBAL);

    expect(govCheck('enforcement_posture')['status'])->toBe('PASS');
});

// ---------------------------------------------------------------------------
// F. NOT_APPLICABLE IS NOT A PASS
// ---------------------------------------------------------------------------

it('counts a skipped check separately and never folds it into passed', function () {
    govPhase(Phase4aPilotPreparationScanner::PHASE_GLOBAL_ACTIVATION_TARGET);
    govGlobalLive(false);

    $scan = govScanner()->scan();
    $summary = $scan['summary'];

    expect($summary['not_applicable'])->toBeGreaterThan(0);

    $statuses = collect($scan['checks'])->pluck('status');

    expect($summary['passed'])->toBe($statuses->filter(fn ($s) => $s === 'PASS')->count());
    expect($summary['passed'] + $summary['watch'] + $summary['failed'] + $summary['not_applicable'])
        ->toBe($summary['total']);

    // The rows stay visible. A skipped check that vanished from the report
    // would be indistinguishable from one that was never written.
    expect($statuses)->toContain(Phase4aPilotPreparationScanner::STATUS_NOT_APPLICABLE);
});

it('keeps a skipped check out of the verdict in both directions', function () {
    govPhase(Phase4aPilotPreparationScanner::PHASE_GLOBAL_ACTIVATION_TARGET);
    govGlobalLive(false);
    govAttestAll(true);

    $scan = govScanner()->scan();

    expect($scan['summary']['not_applicable'])->toBeGreaterThan(0);

    /*
     * RESTATED BY DOCTOR-ACCESS-GLOBAL-DEVICE-ENFORCEMENT-READINESS-1, and
     * restated rather than relaxed.
     *
     * This test's subject is NOT_APPLICABLE: a skipped check moves the verdict
     * in no direction at all. It used to express that as "the verdict is not
     * FAIL", which only held because `govAttestAll(true)` cleared the one gate
     * that would otherwise have failed — the signature-only
     * `global_prerequisites_attested`.
     *
     * Signing all five is no longer free. The contradiction check now measures
     * what was signed, and in this fixture nothing supports any of it, so the
     * scan FAILS — correctly, and for a reason that has nothing to do with
     * skipped checks. Asserting `not FAIL` here would have meant asserting that
     * five false signatures are acceptable, which is the exact false green that
     * sprint closed.
     *
     * So the property is now expressed directly: recompute the verdict over the
     * rows that were actually evaluated, and require it to be identical. That is
     * what "moves the verdict in no direction" means, and it holds whatever the
     * attestation state is.
     */
    $evaluated = collect($scan['checks'])
        ->reject(fn (array $c): bool => $c['status'] === Phase4aPilotPreparationScanner::STATUS_NOT_APPLICABLE);

    $withoutSkipped = $evaluated->contains(fn (array $c): bool => $c['status'] === 'FAIL')
        ? 'FAIL'
        : ($evaluated->contains(fn (array $c): bool => $c['status'] === 'WATCH') ? 'WATCH' : 'GO');

    expect($scan['status'])->toBe($withoutSkipped);
});

it('fails a later phase that signs every prerequisite without measuring any of them', function () {
    govPhase(Phase4aPilotPreparationScanner::PHASE_GLOBAL_ACTIVATION_TARGET);
    govGlobalLive(false);
    govAttestAll(true);

    $checks = collect(govScanner()->scan()['checks'])->keyBy('id');

    // The companion to the restatement above, and the reason it was needed.
    // `global_prerequisites_attested` passes on five signatures alone — that is
    // its documented contract. The contradiction row is what makes signing them
    // cost something.
    expect($checks['global_prerequisites_attested']['status'])->toBe('PASS');
    expect($checks['global_prerequisite_attestations_do_not_contradict_measurement']['status'])->toBe('FAIL');
});

// ---------------------------------------------------------------------------
// G. THE RELEASE SIBLING SCANNER
// ---------------------------------------------------------------------------

it('keeps the release scanner green in phase_4a and still green once the ladder moves', function () {
    $scanner = app(AndroidReleaseGovernanceScanner::class);

    govPhase(Phase4aPilotPreparationScanner::PHASE_4A);
    $before = collect($scanner->scan()['checks'])->keyBy('id');

    expect($before->get('global_enforcement_deferred')['status'])->toBe('PASS');
    expect($before->get('pilot_authority_recorded')['status'])->toBe('PASS');
    expect($before->get('preflight_unlocks_nothing')['status'])->toBe('PASS');

    // An honest Phase 5 moves the ladder and marks the pilot activated. Inside
    // phase_4a that is a FAIL; after it, those clauses retire while the
    // permanent ones — the bounds agreeing, the pilot authority recorded, the
    // preflight's own containment — keep being asserted.
    govPhase(Phase4aPilotPreparationScanner::PHASE_GLOBAL_ACTIVATED);
    config()->set('android_release.enforcement.current_stage', 'global_doctor_enforcement');
    config()->set('android_release.enforcement.owner_signoff', array_merge(
        (array) config('android_release.enforcement.owner_signoff'),
        ['pilot_activated' => true],
    ));
    config()->set('android_release.enforcement.active', true);

    $after = collect($scanner->scan()['checks'])->keyBy('id');

    expect($after->get('global_enforcement_deferred')['status'])->toBe('PASS');
    expect($after->get('pilot_authority_recorded')['status'])->toBe('PASS');
    expect($after->get('preflight_unlocks_nothing')['status'])->toBe('PASS');
});

it('still FAILS the release scanner ladder checks inside phase_4a when the ladder moves', function () {
    $scanner = app(AndroidReleaseGovernanceScanner::class);

    govPhase(Phase4aPilotPreparationScanner::PHASE_4A);
    config()->set('android_release.enforcement.current_stage', 'global_doctor_enforcement');

    $checks = collect($scanner->scan()['checks'])->keyBy('id');

    expect($checks->get('global_enforcement_deferred')['status'])->toBe('FAIL');
});

it('does not let a later phase excuse a preflight that actually unlocked something', function () {
    $scanner = app(AndroidReleaseGovernanceScanner::class);

    govPhase(Phase4aPilotPreparationScanner::PHASE_GLOBAL_ACTIVATED);

    // The preflight's own containment is a permanent fact about what that
    // preflight did, and no phase declaration retires it.
    config()->set('android_release.real_device_preflight.unlocks', [
        'device_owner' => true,
    ]);

    $checks = collect($scanner->scan()['checks'])->keyBy('id');

    expect($checks->get('preflight_unlocks_nothing')['status'])->toBe('FAIL');
});
