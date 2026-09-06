<?php

/**
 * PHASE4A-DOCTOR-ANDROID-PILOT-ACTIVATION-1 — who does the configured scope
 * actually cover?
 *
 * WHY A COMMAND EXISTS FOR THIS
 *
 * The activation order says to prove the scope resolves to exactly the intended
 * pilot doctor BEFORE arming enforcement, and to prove afterwards that every
 * other doctor still has their browser. Neither was answerable on production.
 * `android:phase4a-pilot-readiness` reports the scope MODE and whether it is
 * USABLE — it never reports who is inside it. "Usable" is true for any positive
 * integer, including the wrong one.
 *
 * That gap has a specific shape on this pilot. The enforcement target is a
 * `users.id`. The pilot doctor is `users.id` 18 and `mst_doctors.id` 21, and
 * `users.id` 21 is a different doctor. A one-field mix-up is therefore not a
 * typo that fails loudly: it leaves the pilot doctor unenforced AND denies an
 * unrelated doctor her browser, which is the exact outcome the runbook's F8
 * check exists to catch — discovered, without this, only by someone failing to
 * log in during a clinic session.
 *
 * WHY IT READS THE GATE INSTEAD OF LOGGING IN
 *
 * Proving "another doctor can still log in" by logging in as them needs their
 * password, and nobody should be handling a doctor's password to run a check.
 * The gate decides from the user id and the absence of a device-bound session,
 * so a request with no session reproduces the browser decision exactly. That
 * makes the check credential-free, non-destructive, and — unlike one login —
 * able to answer for every doctor at once.
 */

use App\Models\User;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Support\Android\AndroidDoctorEnforcementScope;
use Illuminate\Support\Facades\Artisan;

function scopeCmdArm(bool $armed, array $scope = [], bool $globalPermitted = false): void
{
    $flags = config('feature_flags.flags', []);
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['default'] = $armed;
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['env_value'] = $armed;
    config()->set('feature_flags.flags', $flags);

    config()->set('doctor_device_enforcement.scope', array_replace_recursive(
        (array) config('doctor_device_enforcement.scope'),
        $scope,
    ));

    config()->set('android_release.enforcement.scope', array_merge(
        (array) config('android_release.enforcement.scope'),
        ['global_permitted' => $globalPermitted],
    ));
}

/** Run the command and return [exitCode, output]. */
function scopeCmdRun(array $args = []): array
{
    $code = Artisan::call('android:phase4a-pilot-scope', $args);

    return [$code, Artisan::output()];
}

function scopeCmdDoctor(string $name): User
{
    $user = User::factory()->create(['name' => $name]);
    $user->assignRole('Doctor');

    return $user;
}

// ---------------------------------------------------------------------------
// It has to name the covered doctor, not merely say "usable"
// ---------------------------------------------------------------------------

it('names exactly the covered doctor and leaves every other doctor out', function () {
    seedAccessControl();

    $pilot = scopeCmdDoctor('Pilot Doctor');
    $other = scopeCmdDoctor('Other Doctor');
    $third = scopeCmdDoctor('Third Doctor');

    scopeCmdArm(false, [
        'mode' => AndroidDoctorEnforcementScope::MODE_PILOT,
        'pilot' => ['doctor_user_id' => $pilot->id],
    ]);

    [$code, $out] = scopeCmdRun();

    expect($code)->toBe(0);
    expect($out)->toContain('COVERED_DOCTOR_COUNT=1');
    expect($out)->toContain('COVERED_DOCTOR_USER_IDS='.$pilot->id);
    expect($out)->toContain('SCOPE_VERDICT=GO');

    // This is the pre-arm state the activation order calls for: the scope is
    // already narrowed to exactly one doctor while the flag is still off, so
    // the target can be confirmed before anything is enforced. Nothing is armed
    // yet, so nobody is denied a browser — all three, including the covered
    // one, are still working.
    expect($out)->toContain('BROWSER_DENIED_DOCTOR_COUNT=0');
    expect($out)->toContain('BROWSER_ALLOWED_DOCTOR_COUNT=3');
    expect($out)->not->toContain('COVERED_DOCTOR_USER_IDS='.$other->id);
    expect($out)->not->toContain('COVERED_DOCTOR_USER_IDS='.$third->id);
});

it('distinguishes a usable scope pointing at the wrong doctor from the right one', function () {
    seedAccessControl();

    $pilot = scopeCmdDoctor('Pilot Doctor');
    $wrong = scopeCmdDoctor('Wrong Doctor');

    // The mix-up this pilot is exposed to: a perfectly usable id, for someone
    // else. `enforcement_scope_usable` is true here, which is why "usable" was
    // never enough of an answer.
    scopeCmdArm(false, [
        'mode' => AndroidDoctorEnforcementScope::MODE_PILOT,
        'pilot' => ['doctor_user_id' => $wrong->id],
    ]);

    [, $out] = scopeCmdRun();

    expect($out)->toContain('ENFORCEMENT_SCOPE_USABLE=true');
    expect($out)->toContain('COVERED_DOCTOR_USER_IDS='.$wrong->id);
    expect($out)->not->toContain('COVERED_DOCTOR_USER_IDS='.$pilot->id);
});

// ---------------------------------------------------------------------------
// The states that must fail, and the one that must not
// ---------------------------------------------------------------------------

it('fails strict when the flag is armed while the scope covers nobody', function () {
    seedAccessControl();
    scopeCmdDoctor('Pilot Doctor');

    // Armed, and enforcing nothing. The runbook calls this out by name: it
    // looks like protection and is not.
    scopeCmdArm(true, [
        'mode' => AndroidDoctorEnforcementScope::MODE_PILOT,
        'pilot' => ['doctor_user_id' => null],
    ]);

    [$code, $out] = scopeCmdRun(['--strict' => true]);

    expect($code)->not->toBe(0);
    expect($out)->toContain('SCOPE_VERDICT=FAIL');
    expect($out)->toContain('armed_but_covers_nobody');
});

it('fails strict when the scope is fleet-wide, which Phase 4A does not permit', function () {
    seedAccessControl();
    scopeCmdDoctor('A');
    scopeCmdDoctor('B');

    scopeCmdArm(true, ['mode' => AndroidDoctorEnforcementScope::MODE_UNSCOPED], globalPermitted: true);

    [$code, $out] = scopeCmdRun(['--strict' => true]);

    expect($code)->not->toBe(0);
    expect($out)->toContain('SCOPE_VERDICT=FAIL');
    expect($out)->toContain('fleet_wide_scope_not_permitted_in_phase_4a');
});

it('reports an unconfigured deployment as WATCH rather than GO or FAIL', function () {
    seedAccessControl();
    scopeCmdDoctor('A');

    // Production before activation: nothing set, nothing armed. That is not a
    // failure, and it is not a configured pilot either.
    scopeCmdArm(false, [
        'mode' => AndroidDoctorEnforcementScope::MODE_PILOT,
        'pilot' => ['doctor_user_id' => null],
    ]);

    [$code, $out] = scopeCmdRun();

    expect($code)->toBe(0);
    expect($out)->toContain('SCOPE_VERDICT=WATCH');
    expect($out)->toContain('COVERED_DOCTOR_COUNT=0');
});

it('passes strict for the exact activation state: one doctor covered and armed', function () {
    seedAccessControl();

    $pilot = scopeCmdDoctor('Pilot Doctor');
    scopeCmdDoctor('Other Doctor');

    scopeCmdArm(true, [
        'mode' => AndroidDoctorEnforcementScope::MODE_PILOT,
        'pilot' => ['doctor_user_id' => $pilot->id],
    ]);

    [$code, $out] = scopeCmdRun(['--strict' => true]);

    expect($code)->toBe(0);
    expect($out)->toContain('SCOPE_VERDICT=GO');
    expect($out)->toContain('ENFORCEMENT_FLAG_ARMED=true');
    expect($out)->toContain('BROWSER_DENIED_DOCTOR_COUNT=1');
    expect($out)->toContain('BROWSER_ALLOWED_DOCTOR_COUNT=1');
});

// ---------------------------------------------------------------------------
// It runs on production, so it must not print anything production cannot afford
// ---------------------------------------------------------------------------

it('prints no email, password hash, remember token or identity number', function () {
    seedAccessControl();

    $pilot = scopeCmdDoctor('Pilot Doctor');

    scopeCmdArm(true, [
        'mode' => AndroidDoctorEnforcementScope::MODE_PILOT,
        'pilot' => ['doctor_user_id' => $pilot->id],
    ]);

    [, $out] = scopeCmdRun();

    expect($out)->not->toContain($pilot->email);
    expect($out)->not->toContain($pilot->password);
    expect($out)->not->toContain('@');
    expect(strtolower($out))->not->toContain('ktp');
    expect(strtolower($out))->not->toContain('nik');
});

it('never counts a non-doctor account as covered, whatever the scope says', function () {
    seedAccessControl();

    $kasir = User::factory()->create(['name' => 'Kasir Account']);
    $kasir->assignRole('Kasir');

    // Point the pilot scope straight at a non-doctor. Role and rollout scope
    // are separate predicates and the role one still has to hold.
    scopeCmdArm(true, [
        'mode' => AndroidDoctorEnforcementScope::MODE_PILOT,
        'pilot' => ['doctor_user_id' => $kasir->id],
    ]);

    [$code, $out] = scopeCmdRun(['--strict' => true]);

    expect($out)->toContain('COVERED_DOCTOR_COUNT=0');
    expect($code)->not->toBe(0);
    expect($out)->toContain('armed_but_covers_nobody');

    // Both facts, separately. "Covers nobody" says enforcement is inert;
    // "target is not covered" says the id in the environment does not name a
    // doctor. An operator fixes those two by doing different things.
    expect($out)->toContain('declared_pilot_target_is_not_covered');
});

it('flags a declared target that names no doctor before anything is armed', function () {
    seedAccessControl();

    scopeCmdDoctor('Pilot Doctor');

    $kasir = User::factory()->create(['name' => 'Kasir Account']);
    $kasir->assignRole('Kasir');

    // Unarmed, so "armed but covers nobody" cannot fire and this finding has
    // to stand on its own. The scope is USABLE — a positive integer is sitting
    // in the target — and still points at nobody who is a doctor. This is the
    // shape of the users.id / mst_doctors.id mix-up, caught before arming.
    scopeCmdArm(false, [
        'mode' => AndroidDoctorEnforcementScope::MODE_PILOT,
        'pilot' => ['doctor_user_id' => $kasir->id],
    ]);

    [$code, $out] = scopeCmdRun();

    expect($out)->toContain('ENFORCEMENT_SCOPE_USABLE=true');
    expect($out)->toContain('declared_pilot_target_is_not_covered');
    expect($out)->toContain('SCOPE_VERDICT=FAIL');

    // FAIL exits non-zero without --strict: a scope naming the wrong person is
    // not a warning.
    expect($code)->not->toBe(0);
});

it('counts only Doctor-role accounts, not every account on the deployment', function () {
    seedAccessControl();

    $pilot = scopeCmdDoctor('Pilot Doctor');
    scopeCmdDoctor('Other Doctor');

    foreach (['Kasir', 'Perawat', 'Admin Klinik'] as $role) {
        User::factory()->create()->assignRole($role);
    }

    scopeCmdArm(true, [
        'mode' => AndroidDoctorEnforcementScope::MODE_PILOT,
        'pilot' => ['doctor_user_id' => $pilot->id],
    ]);

    [, $out] = scopeCmdRun();

    // Two doctors, and the three other accounts are not doctors and are not
    // counted as ones. The operator reads these totals to decide whether the
    // blast radius is what they expect, so an inflated denominator is not a
    // cosmetic error.
    expect($out)->toContain('DOCTOR_ROLE_ACCOUNT_COUNT=2');
    expect($out)->toContain('BROWSER_DENIED_DOCTOR_COUNT=1');
    expect($out)->toContain('BROWSER_ALLOWED_DOCTOR_COUNT=1');
});

it('emits machine readable json when asked', function () {
    seedAccessControl();

    $pilot = scopeCmdDoctor('Pilot Doctor');

    scopeCmdArm(true, [
        'mode' => AndroidDoctorEnforcementScope::MODE_PILOT,
        'pilot' => ['doctor_user_id' => $pilot->id],
    ]);

    [, $out] = scopeCmdRun(['--json' => true]);

    $decoded = json_decode(trim($out), true);

    expect($decoded)->toBeArray()
        ->and($decoded['verdict'])->toBe('GO')
        ->and($decoded['covered_doctor_user_ids'])->toBe([$pilot->id])
        ->and($decoded['enforcement_flag_armed'])->toBeTrue()
        ->and($decoded['global_enforcement_active'])->toBeFalse();
});
