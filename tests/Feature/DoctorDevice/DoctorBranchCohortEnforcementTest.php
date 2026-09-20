<?php

/**
 * DAENGTISIAMS-SUNU-FINAL-RELEASE-CANDIDATE-1 / D10 — enforcing PWA-only for
 * every doctor of ONE branch, with the rest of the fleet untouched.
 *
 * The requirement was "PWA-only for all doctors, Cabang Sunu first, the other
 * branches to follow". It is deliberately NOT implemented as a branch scope at
 * the gate: `DoctorAppLoginGate::inEnforcementScope()` documents that it is
 * "decided from the user id alone. No query", and resolving a home branch
 * there would put a database read on every doctor request, on the login path,
 * forever.
 *
 * So a branch cohort is expressed the way every other cohort already is — a
 * list of user ids — and the only thing that had to change was the
 * source-controlled ceiling (5 -> 12), which is a reviewed change by design.
 *
 * Production, measured: SPN4 has 10 home-locked doctors (users 18-27), LDK2
 * has 3 (9, 12, 28), TLK1 has 2 (14, 15). Fifteen in service.
 *
 * What these tests defend is the property the ceiling exists for: a branch can
 * be enforced, and the FLEET still cannot be, from a host value alone.
 */

use App\Support\Android\AndroidDoctorEnforcementScope;

/** The ten doctors home-locked to Cabang Sunu, as measured on production. */
const D10_SUNU_COHORT = [18, 19, 20, 21, 22, 23, 24, 25, 26, 27];

/** Every Doctor-role account in service. */
const D10_WHOLE_FLEET = [9, 12, 14, 15, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28];

function d10Scope(): AndroidDoctorEnforcementScope
{
    return app(AndroidDoctorEnforcementScope::class);
}

function d10Cohort(array $ids): void
{
    config()->set('doctor_device_enforcement.scope', array_merge(
        (array) config('doctor_device_enforcement.scope'),
        ['mode' => 'pilot', 'pilot' => ['doctor_user_ids' => implode(',', $ids)]],
    ));
}

it('enforces every Sunu doctor while the cohort names exactly that branch', function () {
    d10Cohort(D10_SUNU_COHORT);

    expect(d10Scope()->isUsable())->toBeTrue()
        ->and(d10Scope()->pilotDoctorUserIds())->toHaveCount(10);

    foreach (D10_SUNU_COHORT as $sunuDoctor) {
        expect(d10Scope()->coversUser($sunuDoctor))->toBeTrue("Sunu doctor {$sunuDoctor} was not enforced");
    }
});

it('leaves every doctor of every other branch on browser login', function () {
    d10Cohort(D10_SUNU_COHORT);

    // LDK2 and TLK1 — "sisanya menyusul" means they are untouched TODAY.
    foreach ([9, 12, 28, 14, 15] as $elsewhere) {
        expect(d10Scope()->coversUser($elsewhere))->toBeFalse("doctor {$elsewhere} was enforced but is not at Sunu");
    }
});

it('still refuses to let a host enforce the whole fleet through the cohort', function () {
    // THE POINT OF THE CEILING. Twelve carries the largest branch; fifteen is
    // the fleet. Listing everyone must still cover NOBODY rather than
    // everybody, so fleet-wide stays reachable only via global_permitted.
    d10Cohort(D10_WHOLE_FLEET);

    expect(d10Scope()->isUsable())->toBeFalse()
        ->and(d10Scope()->invalidReasons())
        ->toContain(AndroidDoctorEnforcementScope::REASON_PILOT_COHORT_EXCEEDS_MAXIMUM);

    foreach (D10_WHOLE_FLEET as $anyone) {
        expect(d10Scope()->coversUser($anyone))->toBeFalse('the fleet was enforced from a host value');
    }
});

it('leaves headroom above the largest branch without reaching the fleet', function () {
    $ceiling = (int) config('android_release.enforcement.scope.pilot_cohort_maximum');

    expect($ceiling)->toBeGreaterThanOrEqual(
        count(D10_SUNU_COHORT),
        'the ceiling cannot carry the largest branch',
    )->and($ceiling)->toBeLessThan(
        count(D10_WHOLE_FLEET),
        'the ceiling reaches the whole fleet, so a host could widen enforcement without review',
    );
});

it('can add the next branch without touching source, up to the ceiling', function () {
    // "Sisanya menyusul": adding TLK1's two doctors is a host change only.
    d10Cohort([...D10_SUNU_COHORT, 14, 15]);

    expect(d10Scope()->isUsable())->toBeTrue()
        ->and(d10Scope()->coversUser(14))->toBeTrue()
        ->and(d10Scope()->coversUser(15))->toBeTrue()
        // LDK2 still untouched.
        ->and(d10Scope()->coversUser(9))->toBeFalse();
});

it('covers nobody if the cohort is emptied by mistake', function () {
    d10Cohort([]);

    expect(d10Scope()->isUsable())->toBeFalse();

    foreach (D10_SUNU_COHORT as $sunuDoctor) {
        expect(d10Scope()->coversUser($sunuDoctor))->toBeFalse();
    }
});
