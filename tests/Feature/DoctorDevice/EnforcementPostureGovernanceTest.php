<?php

/**
 * DOCTOR-PWA-GLOBAL-ROLLOUT-READINESS-1 — enforcement stopped being a boolean.
 *
 * WHY THIS FILE EXISTS SEPARATELY FROM Phase4aPilotPreparationTest
 *
 * That suite guards the preparation gate as a whole and, crucially, still
 * guards the case this sprint must NOT weaken: the flag armed over a scope
 * that covers nobody. This file guards the change itself — that a correctly
 * configured, owner-approved, bounded pilot stopped being reported as a
 * failure, and that nothing else moved with it.
 *
 * THE DEFECT
 *
 * `enforcement_inactive` failed on `$armed` alone. That was right for a sprint
 * whose claim was "we shipped nothing armed", and wrong the moment a pilot went
 * live: on production the gate printed NOT READY and exited 1 while the pilot it
 * was describing was working exactly as designed. A gate that reddens on the
 * outcome the programme exists to reach is a gate operators learn to ignore.
 *
 * THE TRAP IN FIXING IT
 *
 * The obvious fix — pass whenever the flag is armed — deletes a real security
 * case. So the breadth that was dropped is replaced, not discarded: the posture
 * check asserts that what is running is what a reviewer declared IN SOURCE
 * CONTROL, and the declaration is a CEILING rather than an equality, so the same
 * reviewed code can run quiet in CI and armed on production without either
 * being a lie.
 *
 * WHAT A PASS HERE DOES NOT PROVE
 *
 * That the cohort is correct or that anyone in it can log in. This is about
 * whether the report tells the truth about the state, not about the state.
 */

use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Support\Android\AndroidDoctorEnforcementScope;
use App\Support\Android\Phase4aPilotPreparationScanner;

/** File-unique `posture` prefix — Pest registers helpers globally per run. */
function postureScan(): array
{
    return app(Phase4aPilotPreparationScanner::class)->scan();
}

function postureCheckNamed(string $id): array
{
    $check = collect(postureScan()['checks'])->firstWhere('id', $id);

    expect($check)->not->toBeNull("Check {$id} disappeared from the preparation scanner");

    return $check;
}

function postureArm(bool $armed): void
{
    $flags = config('feature_flags.flags', []);
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['default'] = $armed;
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['env_value'] = $armed;
    config()->set('feature_flags.flags', $flags);
}

function postureCohort(array $pilot): void
{
    config()->set('doctor_device_enforcement.scope', array_replace_recursive(
        (array) config('doctor_device_enforcement.scope'),
        ['mode' => AndroidDoctorEnforcementScope::MODE_PILOT, 'pilot' => $pilot],
    ));
}

function postureDeclare(string $declared): void
{
    config()->set('android_release.enforcement.expected_posture', $declared);
}

function postureGoGlobal(): void
{
    config()->set('doctor_device_enforcement.scope', array_replace_recursive(
        (array) config('doctor_device_enforcement.scope'),
        ['mode' => AndroidDoctorEnforcementScope::MODE_UNSCOPED],
    ));
    config()->set('android_release.enforcement.scope', array_merge(
        (array) config('android_release.enforcement.scope'),
        ['global_permitted' => true],
    ));
}

// ---------------------------------------------------------------------------
// The defect, and the case it must not take with it
// ---------------------------------------------------------------------------

it('stops failing a live, bounded, owner-approved pilot', function () {
    // Exactly production's shape: armed, pilot mode, a resolvable cohort.
    postureArm(true);
    postureCohort(['doctor_user_ids' => '9,15,18']);
    postureDeclare(Phase4aPilotPreparationScanner::POSTURE_BOUNDED_PILOT);

    expect(postureCheckNamed('enforcement_inactive')['status'])->toBe('PASS');
    expect(postureCheckNamed('enforcement_posture')['status'])->toBe('PASS');
    expect(postureScan()['status'])->toBe('GO');
});

it('still fails the flag armed over a scope that covers nobody', function () {
    // The security case the original breadth was really protecting. Enforcement
    // that denies no one reads as protection while providing none, so it has to
    // be loud somewhere — and this is where.
    postureArm(true);
    postureCohort(['doctor_user_id' => null, 'doctor_user_ids' => '']);
    postureDeclare(Phase4aPilotPreparationScanner::POSTURE_BOUNDED_PILOT);

    expect(postureCheckNamed('enforcement_inactive')['status'])->toBe('FAIL');
    expect(postureScan()['status'])->toBe('FAIL');
});

it('never lets a row carry a message that argues with its own verdict', function () {
    // Found on production immediately after deploy: the narrowing made a live
    // pilot PASS, but the detail chain still said "A preparation sprint must
    // ship it off". The verdict was right and the sentence under it told the
    // operator the opposite, which is the self-contradicting governance text
    // this whole sprint set out to remove.
    //
    // Asserted as a property rather than as one string: every branch of the
    // chain is checked against the status it ships with.
    postureDeclare(Phase4aPilotPreparationScanner::POSTURE_BOUNDED_PILOT);

    // A live, bounded, owner-approved pilot: PASS, and the text must say so.
    postureArm(true);
    postureCohort(['doctor_user_ids' => '9,15,18']);
    $live = postureCheckNamed('enforcement_inactive');
    expect($live['status'])->toBe('PASS');
    expect($live['detail'])->not->toContain('must ship it off');
    expect($live['detail'])->toContain('intended state');
    expect($live['detail'])->toContain('3 named doctor account');

    // Armed over nobody: FAIL, and the text must name that specific danger.
    postureCohort(['doctor_user_id' => null, 'doctor_user_ids' => '']);
    $overNobody = postureCheckNamed('enforcement_inactive');
    expect($overNobody['status'])->toBe('FAIL');
    expect($overNobody['detail'])->toContain('covers nobody');

    // Off: PASS, and the text must not imply anything is running.
    postureArm(false);
    $off = postureCheckNamed('enforcement_inactive');
    expect($off['status'])->toBe('PASS');
    expect($off['detail'])->toContain('is off');
    expect($off['detail'])->not->toContain('live');
});

it('keeps the check id the rest of the codebase asserts by', function () {
    // Ten-plus documents and a sibling suite address this check by name. A
    // rename would silently stop all of them asserting anything.
    expect(postureCheckNamed('enforcement_inactive')['id'])->toBe('enforcement_inactive');
});

// ---------------------------------------------------------------------------
// The posture, which is what replaces the dropped breadth
// ---------------------------------------------------------------------------

it('observes each of the four postures from the running state, not the declaration', function () {
    postureArm(false);
    expect(app(Phase4aPilotPreparationScanner::class)->observedPosture())
        ->toBe(Phase4aPilotPreparationScanner::POSTURE_OFF);

    postureArm(true);
    postureCohort(['doctor_user_ids' => '18']);
    expect(app(Phase4aPilotPreparationScanner::class)->observedPosture())
        ->toBe(Phase4aPilotPreparationScanner::POSTURE_BOUNDED_PILOT);

    postureCohort(['doctor_user_id' => null, 'doctor_user_ids' => '']);
    expect(app(Phase4aPilotPreparationScanner::class)->observedPosture())
        ->toBe(Phase4aPilotPreparationScanner::POSTURE_INDETERMINATE);

    postureGoGlobal();
    expect(app(Phase4aPilotPreparationScanner::class)->observedPosture())
        ->toBe(Phase4aPilotPreparationScanner::POSTURE_GLOBAL);
});

it('accepts a deployment quieter than the declared ceiling', function () {
    // The same reviewed code runs with enforcement off on a developer machine
    // and armed on production. Demanding they match would either redden CI or
    // force the declaration down to the weakest deployment, which would stop it
    // auditing production at all.
    postureArm(false);
    postureDeclare(Phase4aPilotPreparationScanner::POSTURE_BOUNDED_PILOT);

    expect(postureCheckNamed('enforcement_posture')['status'])->toBe('PASS');
});

it('fails a deployment enforcing more than anyone reviewed', function () {
    postureArm(true);
    postureGoGlobal();
    postureDeclare(Phase4aPilotPreparationScanner::POSTURE_BOUNDED_PILOT);

    $check = postureCheckNamed('enforcement_posture');

    expect($check['status'])->toBe('FAIL');
    expect($check['detail'])->toContain('bounded_pilot');
    expect($check['detail'])->toContain('global');
});

it('fails an armed pilot running under a declaration that says enforcement is off', function () {
    // The ceiling comparison itself, reached for the first time here.
    //
    // The "more than reviewed" test above never gets this far: it goes global, and
    // the global branch fires first. A mutation campaign found that gap — the
    // comparison could be deleted entirely and every test stayed green.
    //
    // This is also the realistic drift. Nobody declares `global` by accident; what
    // happens is a pilot gets armed on a host while source control still says the
    // deployment enforces nobody.
    postureArm(true);
    postureCohort(['doctor_user_ids' => '9,15,18']);
    postureDeclare(Phase4aPilotPreparationScanner::POSTURE_OFF);

    $check = postureCheckNamed('enforcement_posture');

    expect($check['status'])->toBe('FAIL');
    expect($check['detail'])->toContain('off');
    expect($check['detail'])->toContain('bounded_pilot');
    expect(postureScan()['status'])->toBe('FAIL');
});

it('refuses a global declaration outright, whatever is running', function () {
    postureArm(false);
    postureDeclare(Phase4aPilotPreparationScanner::POSTURE_GLOBAL);

    expect(postureCheckNamed('enforcement_posture')['status'])->toBe('FAIL');
    expect(postureScan()['status'])->toBe('FAIL');
});

it('refuses an undeclared or unrecognised posture rather than assuming a safe one', function () {
    postureDeclare('probably_fine');

    $check = postureCheckNamed('enforcement_posture');

    expect($check['status'])->toBe('FAIL');
    expect($check['detail'])->toContain('probably_fine');
});

it('treats rollout readiness as measurement, not as a stronger rung', function () {
    // Measuring the fleet for a widening enforces nobody new. If readiness ever
    // outranked the pilot it describes, declaring it would BE an activation.
    postureArm(true);
    postureCohort(['doctor_user_ids' => '9,15,18']);
    postureDeclare(Phase4aPilotPreparationScanner::POSTURE_GLOBAL_ROLLOUT_READINESS);

    expect(postureCheckNamed('enforcement_posture')['status'])->toBe('PASS');
    expect(Phase4aPilotPreparationScanner::POSTURE_STRENGTH[Phase4aPilotPreparationScanner::POSTURE_GLOBAL_ROLLOUT_READINESS])
        ->toBe(Phase4aPilotPreparationScanner::POSTURE_STRENGTH[Phase4aPilotPreparationScanner::POSTURE_BOUNDED_PILOT]);
});

it('never lets indeterminate be something a reviewer can declare', function () {
    expect(Phase4aPilotPreparationScanner::POSTURES)
        ->not->toContain(Phase4aPilotPreparationScanner::POSTURE_INDETERMINATE);
});

// ---------------------------------------------------------------------------
// The global line, measured rather than recorded
// ---------------------------------------------------------------------------

it('reports fleet-wide enforcement by asking the scope, not by reading a stored false', function () {
    // The activation boundary carries a `global_enforcement_active` claim too,
    // and it is a hardcoded false. A safety line that is true because somebody
    // typed it is not a safety line, and the activation checklist reads this
    // one before arming anything.
    postureArm(false);
    expect(app(Phase4aPilotPreparationScanner::class)->globalEnforcementActiveLive())->toBeFalse();
    expect(postureCheckNamed('global_enforcement_not_active')['status'])->toBe('PASS');

    postureArm(true);
    postureGoGlobal();

    expect(app(Phase4aPilotPreparationScanner::class)->globalEnforcementActiveLive())->toBeTrue();
    expect(postureCheckNamed('global_enforcement_not_active')['status'])->toBe('FAIL');
});

it('does not call fleet-wide enforcement live merely because global is permitted', function () {
    // Permitted and active are different facts. A deployment that allows the
    // rung but has not climbed it is not enforcing anyone.
    postureArm(false);
    postureGoGlobal();

    expect(app(Phase4aPilotPreparationScanner::class)->globalEnforcementActiveLive())->toBeFalse();
});

// ---------------------------------------------------------------------------
// The summary agrees with the checks under it
// ---------------------------------------------------------------------------

it('derives the summary posture instead of asserting it separately', function () {
    postureArm(true);
    postureCohort(['doctor_user_ids' => '18']);
    postureDeclare(Phase4aPilotPreparationScanner::POSTURE_BOUNDED_PILOT);

    $scan = postureScan();

    expect($scan['summary']['enforcement_posture'])
        ->toBe(Phase4aPilotPreparationScanner::POSTURE_BOUNDED_PILOT);
    expect($scan['summary']['enforcement_posture_declared'])
        ->toBe(Phase4aPilotPreparationScanner::POSTURE_BOUNDED_PILOT);
    expect($scan['summary']['global_enforcement_active_live'])->toBeFalse();

    // A summary field that can disagree with the check under it is the false
    // green this whole family of gates exists to prevent.
    expect($scan['summary']['enforcement_posture'])
        ->toBe(app(Phase4aPilotPreparationScanner::class)->observedPosture());
});

it('ships the declared posture in source control, out of reach of a host', function () {
    // The same argument that keeps `global_permitted` and `pilot_cohort_maximum`
    // in this file: a declaration a host could edit would not audit the host
    // values, it would just be a second copy of them agreeing with itself.
    expect(config('android_release.enforcement.expected_posture'))
        ->toBe(Phase4aPilotPreparationScanner::POSTURE_BOUNDED_PILOT);

    expect((array) config('doctor_device_enforcement'))
        ->not->toHaveKey('expected_posture');
    expect((array) config('doctor_device_enforcement.scope'))
        ->not->toHaveKey('expected_posture');
});
