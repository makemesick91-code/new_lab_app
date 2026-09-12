<?php

declare(strict_types=1);

/**
 * DOCTOR-ACCESS-PR-A-SINGLE-SESSION-FOUNDATION — the governance perimeter.
 *
 * Nothing here exercises a clinical workflow. Every test in this file defends a
 * property that is invisible in normal use and only shows up as an outage, a
 * silent widening, or a capability that quietly stopped working:
 *
 *   - a permission nobody seeded. The one grant this pull request introduces is
 *     read by the force-logout command, and a command checking a permission that
 *     does not exist in the database refuses EVERY actor, including the
 *     operators it exists for — and no test that acts as a Super Admin can see
 *     it, because the single global `Gate::before` returns true before any
 *     permission is consulted.
 *   - a permission that reaches production in the "Other / Uncategorized"
 *     bucket, where the role screen invites somebody to grant it by guess.
 *   - a flag registry entry missing the metadata the release gates read, or
 *     shipping ON, or declared low-risk.
 *   - the engine failing CLOSED instead of INERT on a session driver it cannot
 *     observe, which would deny every doctor login on a file, redis or array
 *     driver.
 *   - the new code reading the trusted-device enforcement flag, which an
 *     existing exact-equality pin in DoctorDeviceEnforcementGateTest forbids
 *     outright.
 *   - the sprint boundary moving: global doctor enforcement staying OFF and the
 *     pilot cohort staying exactly as it was (owner decision O5).
 *
 * WHAT IS DELIBERATELY NOT HERE. Anything about a branch lock, a temporary
 * cover, an effective branch or a bulk device-authorization tool. Those are
 * PR-B and PR-C, and each arrives with its own governance perimeter in the same
 * commit as its runtime. A governance test is the one kind of test that CAN be
 * written against something that does not exist yet — it would pass vacuously —
 * so the scope line is drawn here explicitly rather than left to a reviewer.
 */

use App\Modules\AccessControl\Services\PermissionGroupingService;
use App\Modules\DoctorAccess\Services\DoctorEffectiveBranchResolver;
use App\Modules\DoctorAccess\Services\DoctorSessionLeaseService;
use App\Modules\DoctorAccess\Support\IncumbentSessionProbe;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Modules\DoctorDevice\Services\DoctorDeviceWebAuthnLoginService;
use App\Services\Foundation\FeatureFlagService;
use App\Support\Android\AndroidDoctorEnforcementScope;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Permission;

require_once __DIR__.'/helpers.php';

/**
 * The one permission this pull request introduces.
 *
 * Written out ONCE, and the tests below either derive their subject from the
 * source or check themselves against this name — never both from the same place,
 * so a permission renamed in one half of the codebase and not the other fails
 * here instead of at a refusal nobody can explain.
 */
function glgPermission(): string
{
    return 'release_doctor_session_leases';
}

/**
 * Every PHP file that makes up this pull request's own surface.
 *
 * @return list<string>
 */
function glgSourceFiles(): array
{
    $files = [];

    $directory = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path('Modules/DoctorAccess'))
    );

    foreach ($directory as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    $files[] = app_path('Console/Commands/DoctorSessionForceLogoutCommand.php');

    // PR-C. Commands live flat in app/Console/Commands, outside the recursive
    // Modules/DoctorAccess walk above, so a console surface that writes
    // authorizations would otherwise escape every scan in this file — including
    // the one that proves no surface arms enforcement.
    $files[] = app_path('Console/Commands/DoctorDeviceBulkAuthorizeCommand.php');

    $files[] = config_path('doctor_access.php');

    sort($files);

    return $files;
}

/*
|--------------------------------------------------------------------------
| No surface may be gated on a permission nobody seeded
|--------------------------------------------------------------------------
*/

it('seeds the permission the force logout command actually reads', function () {
    /*
     * READ OUT OF THE SOURCE, not restated. The command is the only surface in
     * this pull request that authorizes anybody, so its own literal is the
     * subject: rename it there and this fails, rather than the rename surviving
     * to production as a command that refuses every operator.
     */
    $source = app_path('Console/Commands/DoctorSessionForceLogoutCommand.php');

    expect(file_exists($source))->toBeTrue();

    preg_match_all(
        "/'((?:view|manage|approve|release)_[a-z0-9_]+)'/",
        (string) file_get_contents($source),
        $matches,
    );

    $used = array_values(array_unique($matches[1]));
    sort($used);

    // The scan has to have found something, or the assertion below is vacuous
    // and would keep passing after a rename that broke the only gate there is.
    expect($used)->not->toBeEmpty();

    // Exactly one, and exactly this one. A second permission read here without
    // being seeded, or this one renamed in the seeder only, both fail.
    expect($used)->toBe([glgPermission()]);

    expect(PermissionSeeder::PERMISSIONS)->toContain(glgPermission());

    daSeedAccessControl();

    // Seeded in the CONSTANT is not the same statement as present in the
    // DATABASE, and `$user->can()` reads the database.
    expect(Permission::query()->where('name', glgPermission())->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Classification — never the Other bucket
|--------------------------------------------------------------------------
*/

it('classifies the permission into a module group rather than Other', function () {
    daSeedAccessControl();

    $groups = app(PermissionGroupingService::class)->group(Permission::query()->get());

    $owners = [];

    foreach ($groups as $group) {
        foreach ($group['permissions'] as $entry) {
            if ($entry['name'] === glgPermission()) {
                $owners[] = $group['key'];
            }
        }
    }

    // Exactly one group, and not the fallback. A permission in two groups would
    // render twice on the role screen; a permission in none renders under
    // "Other / Uncategorized", which is an invitation to grant it by guess.
    expect($owners)->toBe(['rme']);

    // And it carries a description, because the role screen renders one and a
    // blank line beside a session-ending grant is how it gets handed out.
    $described = null;

    foreach ($groups as $group) {
        foreach ($group['permissions'] as $entry) {
            if ($entry['name'] === glgPermission()) {
                $described = $entry['description'] ?? null;
            }
        }
    }

    expect($described)->toBeString()->not->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Who holds it — read as EFFECTIVE permissions, never as direct rows
|--------------------------------------------------------------------------
*/

it('grants force logout to Supervisor RME and to no other operational role', function () {
    // Effective permissions, through can(): a direct-row query would miss a
    // grant that arrives via the role, and a role query would miss a direct
    // grant. Both mistakes read as a pass.
    expect(daSupervisorRme()->can(glgPermission()))->toBeTrue();

    // THE SUBJECT OF THE CAPABILITY IS NOT AN AUTHORITY OVER IT. A doctor ends
    // their own session by logging out; they never hold the grant that ends
    // somebody else's.
    expect(daDoctorUser()->can(glgPermission()))->toBeFalse();

    // The roles that must never acquire it by adjacency. Kasir and Perawat sit
    // next to doctors all day; Admin Lab and Admin Klinik administer estates
    // that look related and are not.
    foreach (['Kasir', 'Perawat', 'Admin Lab', 'Admin Klinik'] as $role) {
        expect(daUnauthorisedActor($role)->can(glgPermission()))->toBeFalse();
    }

    // Super Admin holds it, but ONLY through the `'*'` sync and the global
    // Gate::before — asserted so nobody later "fixes" a missing RoleSeeder entry
    // that was never missing.
    expect(daSuperAdmin()->can(glgPermission()))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The flag registry
|--------------------------------------------------------------------------
*/

it('registers the single active session flag OFF, critical, and fully described', function () {
    $flags = app(FeatureFlagService::class);
    $flag = $flags->get(DoctorSessionLeaseService::FLAG);

    // The same metadata list the foundation registry test asserts for every
    // flag, restated here so a DoctorAccess-specific omission is reported
    // against this pull request.
    foreach ([
        'name',
        'description',
        'default',
        'env_key',
        'owner',
        'risk_level',
        'rollout_status',
        'dependencies',
        'rollback_action',
        'enabled',
    ] as $field) {
        expect($flag)->toHaveKey($field);
        expect($flag[$field])->not->toBeNull();
    }

    expect($flag['default'])->toBeFalse()
        // Committed OFF and RESOLVED off: a flag whose default is false but
        // whose captured environment value is true ships on.
        ->and($flag['enabled'])->toBeFalse()
        ->and($flag['risk_level'])->toBe('critical')
        ->and($flag['owner'])->toBe('rme')
        ->and($flag['rollout_status'])->toBe('implemented')
        ->and($flag['description'])->not->toBeEmpty()
        ->and($flag['rollback_action'])->not->toBeEmpty();

    // It is critical, so the release gate must not see it among the
    // risky-enabled flags on a committed checkout.
    expect($flags->riskyEnabledFlags())->not->toContain(DoctorSessionLeaseService::FLAG);

    // PR-A registered ONE switch and this assertion pinned the absence of the
    // branch lock's own flag, on the rule that a flag registered ahead of its
    // runtime promises something nothing implements. PR-B ships that runtime, so
    // the flag is now registered and the pin is INVERTED rather than deleted: the
    // absence claim was always about the ordering, and the ordering still holds.
    //
    // What remains load-bearing is that the two are SEPARATE keys. One key
    // serving both capabilities would make the dependency unexpressible — the
    // branch resolver requires the lease engine, because cover-expiry
    // invalidation lives in the lease middleware, and a single switch could not
    // arm the lock over a disarmed engine to be refused.
    $registered = array_keys((array) config('feature_flags.flags'));

    expect($registered)->toContain(DoctorSessionLeaseService::FLAG)
        ->toContain(DoctorEffectiveBranchResolver::FLAG_BRANCH_LOCK);

    expect(DoctorSessionLeaseService::FLAG)
        ->not->toBe(DoctorEffectiveBranchResolver::FLAG_BRANCH_LOCK);
});

/*
|--------------------------------------------------------------------------
| Fail INERT, never fail closed
|--------------------------------------------------------------------------
*/

it('reports the engine unobservable rather than guessing about an incumbent', function () {
    $leases = app(DoctorSessionLeaseService::class);
    $probe = app(IncumbentSessionProbe::class);

    // The flag ON. Only the probe is missing — which is the whole point: this
    // must fail SAFE (nothing is enforced) rather than fail closed (every doctor
    // is refused) or fail open (every incumbent reads DEAD and the rule silently
    // degrades to newest-login-wins).
    daArmSingleActiveSession(true);
    daUnobservableSessionStore();

    expect($probe->observable())->toBeFalse()
        ->and($leases->enabled())->toBeFalse();

    // The SAME flag, with the store observable — so the test proves the probe is
    // the difference rather than the flag being unreadable for some other reason.
    daObservableSessionStore();

    expect($probe->observable())->toBeTrue()
        ->and($leases->enabled())->toBeTrue();

    // And the committed posture is the inert one twice over: the flag is off in
    // source control, and the suite's own driver cannot observe an incumbent.
    daArmSingleActiveSession(false);

    expect($leases->enabled())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The new code never reads the trusted-device enforcement flag
|--------------------------------------------------------------------------
*/

it('never reads the trusted device enforcement flag from this pull request', function () {
    $enforcementReaders = [];
    $ownFlagReaders = [];

    foreach (glgSourceFiles() as $path) {
        expect(file_exists($path))->toBeTrue();

        $contents = (string) file_get_contents($path);
        $relative = str_replace(base_path().'/', '', $path);

        if (str_contains($contents, "'".DoctorAppLoginGate::ENFORCEMENT_FLAG."'")) {
            $enforcementReaders[] = $relative;
        }

        if (str_contains($contents, "'".DoctorSessionLeaseService::FLAG."'")) {
            $ownFlagReaders[] = $relative;
        }
    }

    // DoctorDeviceEnforcementGateTest pins the readers of that key to exactly
    // ONE file, so a second reader anywhere is a failure there. Asserting the
    // absence here as well means the failure names THIS pull request, which is
    // the difference between a five-minute fix and an afternoon.
    expect($enforcementReaders)->toBe([]);

    // Proof the scan can actually find a flag literal in these files. Without
    // it, a broken path list would report "no readers" forever.
    expect($ownFlagReaders)->not->toBeEmpty();

    // Stated as a fact about this pull request rather than about that suite: the
    // two capabilities are independent switches, and the session lease may not
    // arm, disarm or consult device enforcement.
    expect(DoctorSessionLeaseService::FLAG)->not->toBe(DoctorAppLoginGate::ENFORCEMENT_FLAG);
});

/*
|--------------------------------------------------------------------------
| Owner decision O5 — the sprint boundary has not moved
|--------------------------------------------------------------------------
*/

it('leaves global doctor enforcement off and writes no pilot cohort of its own', function () {
    $flags = app(FeatureFlagService::class);

    // Neither device flag was armed by this pull request. Safe to assert as an
    // absolute because both are risk-critical and FeatureFlagFoundationTest
    // already pins `riskyEnabledFlags()` to empty on every checkout the suite
    // runs on — so a host that armed one is already failing there.
    expect($flags->enabled(DoctorAppLoginGate::ENFORCEMENT_FLAG))->toBeFalse()
        ->and($flags->enabled(DoctorDeviceWebAuthnLoginService::FLAG))->toBeFalse();

    // SOURCE-CONTROLLED, and therefore assertable: fleet-wide denial stays a
    // reviewed refusal, and the ceiling on what the word "pilot" may mean stays
    // out of a host's reach.
    expect(config('android_release.enforcement.scope.global_permitted'))->toBeFalse()
        ->and(config('android_release.enforcement.scope.default_mode'))->toBe('pilot')
        ->and(config('android_release.enforcement.scope.pilot_cohort_maximum'))->toBe(5)
        ->and(app(AndroidDoctorEnforcementScope::class)->globalPermitted())->toBeFalse();

    /*
     * DELIBERATELY NOT ASSERTED: the resolved scope mode, the cohort ids, and
     * whether any given doctor is covered. Those are HOST values — production
     * runs a live, owner-approved bounded pilot with them set — so pinning them
     * would fail on any checkout configured like production, and would be
     * asserting somebody's environment file rather than this diff.
     * EnforcementPostureGovernanceTest draws the same line for the same reason.
     *
     * What IS this pull request's business is that it wrote no cohort. The
     * committed runtime file supplies both cohort keys from the environment and
     * names no doctor.
     */
    $runtime = (string) file_get_contents(config_path('doctor_device_enforcement.php'));

    expect($runtime)->toContain("env('ANDROID_PILOT_ENFORCEMENT_DOCTOR_USER_ID')")
        ->toContain("env('ANDROID_PILOT_ENFORCEMENT_DOCTOR_USER_IDS'")
        // A literal id here would be a cohort committed to source control,
        // pointing at whoever happens to hold that id in each database.
        ->not->toMatch("/'doctor_user_ids?'\s*=>\s*'?\d/");
});

/*
|--------------------------------------------------------------------------
| No bound this pull request enforces may be moved from a host environment
|--------------------------------------------------------------------------
*/

it('reads the written reason bounds from source control and never from the environment', function () {
    /*
     * A BOUND AN OPERATOR CAN MOVE FROM THE MACHINE THEY ARE ALREADY ON IS NOT A
     * BOUND. The force-logout command quotes these two numbers in its own
     * refusals, so an `env()` call here would let the person running the command
     * widen the rule it is about to enforce against them.
     *
     * THE SCAN IS OVER EXECUTABLE TOKENS, NOT OVER THE FILE TEXT, and that is
     * not fussiness: the config file's own docblock states the rule in prose and
     * therefore contains the literal `env(`. A str_contains() over the raw text
     * fails on the comment that documents the guarantee — which is exactly the
     * class of false positive that gets a real guard deleted. Comments and
     * docblocks are dropped, so what is asserted is that no code calls it.
     */
    $tokens = token_get_all((string) file_get_contents(config_path('doctor_access.php')));

    $executable = '';

    foreach ($tokens as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $executable .= is_array($token) ? $token[1] : $token;
    }

    // The strip has to have left something, or the assertion below is vacuous.
    expect($executable)->toContain('min_length')
        ->and($executable)->not->toContain('env(');

    expect(config('doctor_access.reason.min_length'))->toBeInt()->toBeGreaterThan(1)
        ->and(config('doctor_access.reason.max_length'))->toBeInt()
        ->toBeGreaterThan((int) config('doctor_access.reason.min_length'));
});
