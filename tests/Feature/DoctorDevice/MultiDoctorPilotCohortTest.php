<?php

/**
 * DOCTOR-PWA-MULTI-DOCTOR-PILOT-1 — the pilot became a list.
 *
 * WHAT CHANGED, AND WHY IT NEEDED ITS OWN SUITE
 *
 * Until this sprint `AndroidDoctorEnforcementScope` held one `?int` and
 * compared it with `===`, so "a pilot" and "one doctor" were the same
 * statement. A second doctor could only be added by going fleet-wide, which is
 * Phase 5 and is refused. The cohort separates the two.
 *
 * That separation is the dangerous kind. Every prior guard on this path was
 * written against a scope that could only ever name one person, so a bug that
 * over-covers now has somewhere to go that it did not have before. The three
 * properties below are the ones that stop it, and each is asserted here rather
 * than inferred:
 *
 *   1. COVERED MEANS NAMED.       No wildcard, no role, no branch, no "all".
 *   2. A LIST HAS A CEILING.      An explicit list long enough to name every
 *                                 doctor is fleet-wide denial wearing a pilot's
 *                                 label, and it would pass every check written
 *                                 to watch for fleet-wide denial.
 *   3. UNREADABLE COVERS NOBODY.  One bad entry voids the cohort rather than
 *                                 being dropped from it.
 *
 * WHAT THIS SUITE DELIBERATELY DOES NOT CLAIM
 *
 * Being in the cohort is not admission. It decides only that enforcement
 * APPLIES to a doctor. Whether that doctor can then get in still needs an
 * active device, an active authorization and a usable device-bound credential,
 * re-asserted on every protected request — and those live in the WebAuthn and
 * device suites, which this sprint did not touch. The test at the bottom pins
 * the boundary so a future reader does not mistake cohort membership for a
 * login.
 */

use App\Models\User;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Support\Android\AndroidDoctorEnforcementScope;
use App\Support\Android\Phase4aPilotScopeResolutionReport;
use Illuminate\Http\Request;

/**
 * Set the runtime pilot scope. Named uniquely: Pest shares these functions
 * across files, and colliding with a sibling suite's helper breaks whichever
 * file loads second.
 */
function cohortScope(array $pilot, ?int $maximum = null): void
{
    config()->set('doctor_device_enforcement.scope', array_replace_recursive(
        (array) config('doctor_device_enforcement.scope'),
        ['mode' => AndroidDoctorEnforcementScope::MODE_PILOT, 'pilot' => $pilot],
    ));

    if ($maximum !== null) {
        config()->set('android_release.enforcement.scope', array_merge(
            (array) config('android_release.enforcement.scope'),
            ['pilot_cohort_maximum' => $maximum],
        ));
    }
}

function cohortResolver(): AndroidDoctorEnforcementScope
{
    return app(AndroidDoctorEnforcementScope::class);
}

function cohortDoctor(string $name): User
{
    $user = User::factory()->create(['name' => $name]);
    $user->assignRole('Doctor');

    return $user;
}

function cohortArm(bool $armed): void
{
    $flags = config('feature_flags.flags', []);
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['default'] = $armed;
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['env_value'] = $armed;
    config()->set('feature_flags.flags', $flags);
}

// ---------------------------------------------------------------------------
// 1. COVERED MEANS NAMED
// ---------------------------------------------------------------------------

it('covers every doctor named in the cohort and nobody else', function () {
    cohortScope(['doctor_user_ids' => '18,19,20']);

    $scope = cohortResolver();

    expect($scope->isUsable())->toBeTrue();
    expect($scope->pilotDoctorUserIds())->toBe([18, 19, 20]);

    foreach ([18, 19, 20] as $covered) {
        expect($scope->coversUser($covered))->toBeTrue();
    }

    // The neighbours on either side of the cohort, and an unrelated id. A
    // membership test that drifted into a range check would pass the first two.
    foreach ([17, 21, 1, 999] as $outside) {
        expect($scope->coversUser($outside))->toBeFalse();
    }
});

it('returns the cohort in ascending order however it was written', function () {
    cohortScope(['doctor_user_ids' => '20,18,19']);

    // Not cosmetic. The resolution report compares the covered set against this
    // one, and an unordered cohort would make that comparison depend on the
    // order somebody happened to type ids into an environment variable.
    expect(cohortResolver()->pilotDoctorUserIds())->toBe([18, 19, 20]);
});

it('treats whitespace after a separator as syntax, not as a malformed entry', function () {
    cohortScope(['doctor_user_ids' => '18, 19 , 20']);

    expect(cohortResolver()->pilotDoctorUserIds())->toBe([18, 19, 20]);
});

it('still refuses a padded id in the singular key, which is not a list', function () {
    // The list tolerates spaces because a comma separated list is normally
    // written that way. A lone scalar has no separator, so padding there is a
    // mistake — and ' 4242' has resolved to "no target" since Phase 4A.
    cohortScope(['doctor_user_id' => ' 4242', 'doctor_user_ids' => '']);

    $scope = cohortResolver();

    expect($scope->pilotDoctorUserIds())->toBe([]);
    expect($scope->coversUser(4242))->toBeFalse();
});

it('never expands a wildcard, a role name or the word all into a cohort', function () {
    foreach (['*', 'all', 'doctors', 'Doctor', '*,18', '18,*'] as $wildcard) {
        cohortScope(['doctor_user_ids' => $wildcard]);

        $scope = cohortResolver();

        expect($scope->isUsable())->toBeFalse();
        expect($scope->pilotDoctorUserIds())->toBe([]);

        // Including the id that WAS written next to the wildcard: a cohort
        // containing an unreadable entry covers nobody, not the readable half.
        foreach ([1, 18, 4242] as $anyone) {
            expect($scope->coversUser($anyone))->toBeFalse();
        }
    }
});

it('returns genuine integers, never numeric strings, in the cohort', function () {
    // The precondition that makes membership comparison safe. `coversUser()`
    // takes a typed int and compares against this list, so as long as every
    // entry is a real int, strict and loose comparison cannot disagree.
    //
    // Pinned because a mutation campaign flipped `in_array(..., true)` to a
    // loose comparison and NOTHING failed — correctly, since the two are
    // identical over two integers. That mutant is equivalent only while this
    // guarantee holds. If a later change lets a numeric string into the list,
    // strictness starts to matter again and this test is what notices.
    cohortScope(['doctor_user_id' => '18', 'doctor_user_ids' => '19, 20']);

    $ids = cohortResolver()->pilotDoctorUserIds();

    expect($ids)->toBe([18, 19, 20]);

    foreach ($ids as $id) {
        expect($id)->toBeInt();
    }
});

// ---------------------------------------------------------------------------
// 2. DEPLOYING THE COHORT CHANGES NOBODY
// ---------------------------------------------------------------------------

it('resolves a deployment that sets only the old singular key exactly as before', function () {
    // The property that made this change safe to ship to a live pilot. On
    // production `ANDROID_PILOT_ENFORCEMENT_DOCTOR_USER_ID` is 18 and the
    // cohort variable is unset, so deploying the mechanism must resolve to the
    // same one doctor it resolved to before, and enforce nobody new.
    cohortScope(['doctor_user_id' => 18, 'doctor_user_ids' => '']);

    $scope = cohortResolver();

    expect($scope->pilotDoctorUserIds())->toBe([18]);
    expect($scope->pilotDoctorUserId())->toBe(18);
    expect($scope->coversUser(18))->toBeTrue();
    expect($scope->coversUser(19))->toBeFalse();
});

it('unions the singular key with the cohort key rather than letting one win', function () {
    cohortScope(['doctor_user_id' => 18, 'doctor_user_ids' => '19,20']);

    expect(cohortResolver()->pilotDoctorUserIds())->toBe([18, 19, 20]);
});

it('normalises a repeated id instead of counting it twice', function () {
    cohortScope(['doctor_user_id' => 18, 'doctor_user_ids' => '18,18,19'], maximum: 2);

    // Three written entries, two doctors. If duplicates were counted the
    // ceiling of two would reject this, and a one-doctor pilot written as
    // `18,18` would be refused for being too large.
    expect(cohortResolver()->pilotDoctorUserIds())->toBe([18, 19]);
});

it('reports no single declared target once the cohort names more than one doctor', function () {
    cohortScope(['doctor_user_ids' => '18,19']);

    // Inventing one — the first id, the lowest — would print a real doctor's
    // id in a field that claims to name the whole scope.
    expect(cohortResolver()->pilotDoctorUserId())->toBeNull();
});

// ---------------------------------------------------------------------------
// 3. A LIST HAS A CEILING
// ---------------------------------------------------------------------------

it('covers nobody when the cohort is larger than the reviewed maximum', function () {
    cohortScope(['doctor_user_ids' => '1,2,3,4,5,6'], maximum: 5);

    $scope = cohortResolver();

    expect($scope->isUsable())->toBeFalse();
    expect($scope->pilotDoctorUserIds())->toBe([]);
    expect($scope->invalidReasons())->toContain('pilot_cohort_exceeds_reviewed_maximum');

    foreach ([1, 6] as $anyone) {
        expect($scope->coversUser($anyone))->toBeFalse();
    }
});

it('accepts a cohort sitting exactly on the maximum', function () {
    cohortScope(['doctor_user_ids' => '1,2,3'], maximum: 3);

    expect(cohortResolver()->pilotDoctorUserIds())->toBe([1, 2, 3]);
});

it('keeps the ceiling out of the runtime file an operator can edit', function () {
    // Same reasoning as `global_permitted`: a bound an operator can raise on a
    // host is not a bound. Raising it is a source-control change.
    expect(config('android_release.enforcement.scope.pilot_cohort_maximum'))->toBeInt();
    expect(config('doctor_device_enforcement.scope'))->not->toHaveKey('pilot_cohort_maximum');
    expect((array) config('doctor_device_enforcement.scope.pilot'))
        ->not->toHaveKey('pilot_cohort_maximum');
});

it('falls back to a cohort of one when the ceiling is missing or unreadable', function () {
    foreach ([null, 0, -1, 'five', 2.5, true] as $unusable) {
        config()->set('android_release.enforcement.scope', array_merge(
            (array) config('android_release.enforcement.scope'),
            ['pilot_cohort_maximum' => $unusable],
        ));

        cohortScope(['doctor_user_ids' => '18,19']);

        // A ceiling that fails open is not a ceiling. An unreadable policy
        // value must narrow to the single-doctor pilot, never to unlimited.
        expect(cohortResolver()->pilotCohortMaximum())->toBe(1);
        expect(cohortResolver()->pilotDoctorUserIds())->toBe([]);
    }
});

// ---------------------------------------------------------------------------
// 4. UNREADABLE COVERS NOBODY
// ---------------------------------------------------------------------------

it('voids the whole cohort when a single entry cannot be read', function () {
    // `2O` is a letter O. Dropping it would leave a working two-doctor pilot
    // with the third doctor silently unenforced behind a list that still reads
    // correctly; voiding covers nobody and trips armed_but_covers_nobody.
    foreach (['18,19,2O', '18,,19', '18,-1,19', '18,0,19', '18,19.0'] as $broken) {
        cohortScope(['doctor_user_ids' => $broken]);

        $scope = cohortResolver();

        expect($scope->isUsable())->toBeFalse();
        expect($scope->pilotDoctorUserIds())->toBe([]);
        expect($scope->invalidReasons())->toContain('pilot_cohort_contains_unusable_entry');
        expect($scope->coversUser(18))->toBeFalse();
        expect($scope->coversUser(19))->toBeFalse();
    }
});

it('distinguishes nothing declared from something unreadable', function () {
    cohortScope(['doctor_user_id' => null, 'doctor_user_ids' => '']);
    expect(cohortResolver()->invalidReasons())->toContain('pilot_mode_without_doctor_user_id');

    cohortScope(['doctor_user_id' => null, 'doctor_user_ids' => 'abc']);
    expect(cohortResolver()->invalidReasons())->toContain('pilot_cohort_contains_unusable_entry');
});

it('refuses a scalar sitting in the key documented as a list', function () {
    foreach ([true, 4242.0, 18.5] as $scalar) {
        cohortScope(['doctor_user_id' => null, 'doctor_user_ids' => $scalar]);

        // `(string) true` is '1' and `(string) 4242.0` is '4242'. A parser that
        // cast before checking would turn each of these into a real, wrong,
        // covered doctor — user 1 being the most valuable account on the
        // deployment to accidentally shut out of a browser.
        $scope = cohortResolver();

        expect($scope->pilotDoctorUserIds())->toBe([]);
        expect($scope->coversUser(1))->toBeFalse();
        expect($scope->coversUser(18))->toBeFalse();
        expect($scope->coversUser(4242))->toBeFalse();
    }
});

it('accepts a genuine array of ids, which is a list however it was spelled', function () {
    // The environment delivers a comma separated string, but the key holds a
    // LIST and a PHP array is one. Refusing it would be pedantry rather than
    // safety: no coercion happens, every entry is still checked individually.
    cohortScope(['doctor_user_id' => null, 'doctor_user_ids' => ['18', 19]]);

    expect(cohortResolver()->pilotDoctorUserIds())->toBe([18, 19]);

    // And one bad entry still voids the whole array, exactly as in a string.
    cohortScope(['doctor_user_id' => null, 'doctor_user_ids' => ['18', true]]);

    expect(cohortResolver()->pilotDoctorUserIds())->toBe([]);
    expect(cohortResolver()->coversUser(18))->toBeFalse();
});

// ---------------------------------------------------------------------------
// 5. THE COHORT NEVER REACHES FLEET-WIDE
// ---------------------------------------------------------------------------

it('leaves global enforcement off no matter how large the pilot cohort is', function () {
    cohortScope(['doctor_user_ids' => '1,2,3,4,5'], maximum: 5);

    $scope = cohortResolver();

    expect($scope->isUnscopedMode())->toBeFalse();
    expect($scope->globalPermitted())->toBeFalse();
    expect(config('android_release.enforcement.scope.global_permitted'))->toBeFalse();
});

it('keeps fleet-wide permission unreachable from the runtime scope file', function () {
    expect(config('doctor_device_enforcement.scope'))->not->toHaveKey('global_permitted');
});

// ---------------------------------------------------------------------------
// 6. THE GATE, WHICH IS THE ONLY THING THAT ACTS ON ANY OF THIS
// ---------------------------------------------------------------------------

it('denies the browser to every cohort doctor and to no other doctor', function () {
    seedAccessControl();

    $a = cohortDoctor('Cohort A');
    $b = cohortDoctor('Cohort B');
    $outside = cohortDoctor('Outside Doctor');

    cohortArm(true);
    cohortScope(['doctor_user_ids' => $a->id.','.$b->id], maximum: 5);

    $gate = app(DoctorAppLoginGate::class);
    $browser = Request::create('/login', 'POST');

    foreach ([$a, $b] as $covered) {
        expect($gate->inEnforcementScope($covered))->toBeTrue();
        expect($gate->denyBrowserSessionReason($covered, $browser))->not->toBeNull();
    }

    expect($gate->inEnforcementScope($outside))->toBeFalse();
    expect($gate->denyBrowserSessionReason($outside, $browser))->toBeNull();
});

it('never covers a non-doctor account that happens to be named in the cohort', function () {
    seedAccessControl();

    $doctor = cohortDoctor('Real Doctor');
    $notADoctor = User::factory()->create(['name' => 'Cashier']);

    cohortArm(true);
    cohortScope(['doctor_user_ids' => $doctor->id.','.$notADoctor->id], maximum: 5);

    $gate = app(DoctorAppLoginGate::class);

    // The role predicate still has to hold. A cohort is a narrowing, never a
    // grant, so writing an id down cannot pull a non-doctor into the rules.
    expect($gate->inEnforcementScope($doctor))->toBeTrue();
    expect($gate->inEnforcementScope($notADoctor))->toBeFalse();
});

// ---------------------------------------------------------------------------
// 7. THE REPORT MUST NOT CALL A COHORT A FAILURE, NOR A WRONG ONE A PASS
// ---------------------------------------------------------------------------

it('passes a three-doctor cohort that resolves to exactly the declared doctors', function () {
    seedAccessControl();

    $doctors = collect(['A', 'B', 'C'])->map(fn (string $n) => cohortDoctor("Cohort {$n}"));
    $ids = $doctors->pluck('id')->sort()->values()->all();

    cohortArm(true);
    cohortScope(['doctor_user_ids' => implode(',', $ids)], maximum: 5);

    $result = app(Phase4aPilotScopeResolutionReport::class)->build();

    expect($result['covered_doctor_user_ids'])->toEqualCanonicalizing($ids);
    expect($result['declared_pilot_doctor_user_ids'])->toBe($ids);
    expect($result['covered_doctor_count'])->toBe(3);
    expect($result['findings'])->toBe([]);
    expect($result['verdict'])->toBe(Phase4aPilotScopeResolutionReport::VERDICT_GO);
    expect($result['global_enforcement_active'])->toBeFalse();
});

it('fails a cohort of the right size built from ids that are not all doctors', function () {
    seedAccessControl();

    $doctor = cohortDoctor('Real Doctor');

    cohortArm(true);
    // Two ids declared, one of which names nobody. The count is not one, so a
    // cardinality check would have said nothing useful; the set comparison
    // says the pilot is not running on the doctors it claims.
    cohortScope(['doctor_user_ids' => $doctor->id.',987654'], maximum: 5);

    $result = app(Phase4aPilotScopeResolutionReport::class)->build();

    expect($result['findings'])->toContain('declared_pilot_target_is_not_covered');
    expect($result['verdict'])->toBe(Phase4aPilotScopeResolutionReport::VERDICT_FAIL);
});

it('still fails when armed while an over-large cohort leaves nobody covered', function () {
    seedAccessControl();

    cohortDoctor('Some Doctor');

    cohortArm(true);
    cohortScope(['doctor_user_ids' => '1,2,3,4,5,6'], maximum: 5);

    $result = app(Phase4aPilotScopeResolutionReport::class)->build();

    expect($result['enforcement_scope_usable'])->toBeFalse();
    expect($result['covered_doctor_count'])->toBe(0);
    expect($result['findings'])->toContain('armed_but_covers_nobody');
    expect($result['verdict'])->toBe(Phase4aPilotScopeResolutionReport::VERDICT_FAIL);
});

// ---------------------------------------------------------------------------
// 8. WHAT COHORT MEMBERSHIP IS NOT
// ---------------------------------------------------------------------------

it('does not admit a cohort doctor, it only decides that the rules apply', function () {
    seedAccessControl();

    $doctor = cohortDoctor('Cohort Doctor');

    cohortArm(true);
    cohortScope(['doctor_user_ids' => (string) $doctor->id], maximum: 5);

    $gate = app(DoctorAppLoginGate::class);

    // Being named is the whole of what the cohort does. With no device-bound
    // session this doctor is DENIED, and adding ids to a list is therefore
    // never a way to get anybody in — it is only a way to shut a browser.
    expect($gate->inEnforcementScope($doctor))->toBeTrue();
    expect($gate->denyBrowserSessionReason($doctor, Request::create('/login', 'POST')))
        ->toBe(DoctorAppLoginGate::DENY_NO_DEVICE_SESSION);
});

it('creates no device, authorization or credential by naming a doctor', function () {
    seedAccessControl();

    $doctor = cohortDoctor('Cohort Doctor');

    $before = [
        'devices' => DoctorDevice::count(),
        'authorizations' => DoctorDeviceAuthorization::count(),
        'credentials' => DoctorDeviceWebAuthnCredential::count(),
    ];

    cohortArm(true);
    cohortScope(['doctor_user_ids' => (string) $doctor->id], maximum: 5);

    app(Phase4aPilotScopeResolutionReport::class)->build();

    // Scope is configuration, not provisioning. Expanding it must never
    // manufacture the identities a doctor would need to actually log in, and
    // contracting it must never revoke them.
    expect(DoctorDevice::count())->toBe($before['devices']);
    expect(DoctorDeviceAuthorization::count())
        ->toBe($before['authorizations']);
    expect(DoctorDeviceWebAuthnCredential::count())
        ->toBe($before['credentials']);
});
