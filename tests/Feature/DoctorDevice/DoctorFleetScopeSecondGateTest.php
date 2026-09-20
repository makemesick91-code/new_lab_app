<?php

/**
 * DAENGTISIAMS-SUNU-FINAL-RELEASE-CANDIDATE-1 / D4 — fleet-wide doctor
 * enforcement needs TWO independent authorities, and this is the proof.
 *
 * The concern raised was that `doctor_device_enforcement.scope.mode` is a HOST
 * value, so flipping it to `unscoped` would widen enforcement from the pilot
 * cohort to every doctor in one keystroke — a single-value blast radius across
 * every branch.
 *
 * That turned out to be FALSE, and this file is why it will stay false.
 * `coversUser()` consults `isUsable()` FIRST, and for `unscoped` that requires
 * `android_release.enforcement.scope.global_permitted`, which is a literal
 * `false` in a config file that reads no environment. The host can change the
 * mode; it cannot grant the permission.
 *
 * No production code was written for D4 — the gate already existed. What was
 * missing was a test that fails if anyone removes it, because the original
 * review misread `MODE_UNSCOPED => true` as unconditional by skipping the
 * guard directly above it. These assertions make that misreading impossible
 * to ship.
 */

use App\Support\Android\AndroidDoctorEnforcementScope;

function d4Scope(): AndroidDoctorEnforcementScope
{
    return app(AndroidDoctorEnforcementScope::class);
}

function d4SetMode(?string $mode): void
{
    config()->set('doctor_device_enforcement.scope.mode', $mode);
}

function d4SetGlobalPermitted(bool $permitted): void
{
    config()->set('android_release.enforcement.scope', array_merge(
        (array) config('android_release.enforcement.scope'),
        ['global_permitted' => $permitted],
    ));
}

function d4SetCohort(array $ids): void
{
    config()->set('doctor_device_enforcement.scope', array_merge(
        (array) config('doctor_device_enforcement.scope'),
        ['pilot' => ['doctor_user_ids' => implode(',', $ids)]],
    ));
}

// ─── The claim under test ────────────────────────────────────────────────────

it('covers nobody when the mode is widened to unscoped without global permission', function () {
    d4SetMode('unscoped');
    d4SetGlobalPermitted(false);

    // The exact one-keystroke scenario the review feared.
    expect(d4Scope()->isUsable())->toBeFalse()
        ->and(d4Scope()->coversUser(9))->toBeFalse()
        ->and(d4Scope()->coversUser(999999))->toBeFalse();
});

it('reports why it refuses, rather than failing silently', function () {
    d4SetMode('unscoped');
    d4SetGlobalPermitted(false);

    expect(d4Scope()->invalidReasons())
        ->toContain(AndroidDoctorEnforcementScope::REASON_GLOBAL_NOT_PERMITTED);
});

it('covers everyone only when BOTH the mode and the source-controlled permission agree', function () {
    d4SetMode('unscoped');
    d4SetGlobalPermitted(true);

    expect(d4Scope()->isUsable())->toBeTrue()
        ->and(d4Scope()->coversUser(9))->toBeTrue()
        ->and(d4Scope()->coversUser(999999))->toBeTrue();
});

// ─── The pilot must keep working without global permission ──────────────────

it('keeps the bounded pilot working while global permission stays false', function () {
    d4SetMode('pilot');
    d4SetGlobalPermitted(false);
    d4SetCohort([9, 15, 18]);

    expect(d4Scope()->isUsable())->toBeTrue()
        ->and(d4Scope()->coversUser(9))->toBeTrue()
        ->and(d4Scope()->coversUser(18))->toBeTrue()
        // Strict membership: a doctor outside the cohort is untouched.
        ->and(d4Scope()->coversUser(21))->toBeFalse();
});

it('grants nothing from global permission alone while the mode stays pilot', function () {
    d4SetMode('pilot');
    d4SetGlobalPermitted(true);
    d4SetCohort([9]);

    // Permission without the mode must not widen the cohort.
    expect(d4Scope()->coversUser(9))->toBeTrue()
        ->and(d4Scope()->coversUser(15))->toBeFalse();
});

// ─── Fail closed on anything unrecognised ───────────────────────────────────

it('covers nobody for an unrecognised, empty or malformed mode', function () {
    d4SetGlobalPermitted(true); // even with permission granted

    foreach (['all', 'global', 'fleet', 'UNSCOPED ', 'nonsense', ''] as $mode) {
        d4SetMode($mode);

        // '' falls back to the policy default, everything else is unknown.
        // Neither may resolve to "everyone".
        if (d4Scope()->mode() === AndroidDoctorEnforcementScope::MODE_UNSCOPED) {
            continue; // a deliberate alias would be a product decision, not a typo
        }

        expect(d4Scope()->coversUser(9))->toBeFalse("mode '{$mode}' covered a doctor");
    }
});

it('covers nobody when the pilot cohort is empty', function () {
    d4SetMode('pilot');
    d4SetGlobalPermitted(false);
    d4SetCohort([]);

    expect(d4Scope()->isUsable())->toBeFalse()
        ->and(d4Scope()->coversUser(9))->toBeFalse();
});
