<?php

declare(strict_types=1);

/**
 * DOCTOR-ACCESS PR-B — the governance perimeter of the BRANCH LOCK.
 *
 * Nothing here exercises a clinical workflow. Every test defends a property that
 * is invisible in normal use and only shows up as an outage, a silent widening,
 * or a capability that quietly stopped working:
 *
 *   - a route gated on a permission nobody seeded. The route middleware then
 *     refuses everyone, including the approvers this pull request exists for,
 *     and no test acting as a Super Admin can see it, because the one global
 *     `Gate::before` returns true before any permission is consulted.
 *   - a permission reaching production in the "Other / Uncategorized" bucket,
 *     where the role screen invites somebody to grant it by guess.
 *   - the new flag missing the metadata the release gates read, shipping ON, or
 *     declared low-risk.
 *   - an armed branch lock outliving a disarmed lease engine. Cover-expiry
 *     invalidation lives in the lease middleware, so an armed lock over a
 *     disarmed lease engine would leave an EXPIRED cover granting authority that
 *     nothing can invalidate.
 *   - a lock that has silently stopped applying. The resolver degrades to UNSET
 *     on purpose and deliberately does NOT audit, because it runs on every
 *     protected request, so the approver queue is the only place the standing
 *     condition is visible.
 *
 * WHAT IS DELIBERATELY NOT HERE, TWICE OVER.
 *
 * The maker-checker cases belong to DoctorBranchApprovalTest, next to the
 * transactions that enforce them: the invariant lives inside the service under a
 * row lock, not in a policy, precisely so `Gate::before` cannot skip it, and a
 * governance file asserting role grants could never show that.
 *
 * The enforcement-flag scan and the sprint-boundary pin are NOT repeated from
 * DoctorSessionLeaseGovernanceTest. That file's `glgSourceFiles()` walks the
 * whole `Modules/DoctorAccess` tree recursively, so every file this pull request
 * adds is already inside its scan; restating it here would give a second place
 * to keep in step and no extra coverage.
 */

use App\Modules\AccessControl\Services\PermissionGroupingService;
use App\Modules\DoctorAccess\Services\DoctorEffectiveBranchResolver;
use App\Modules\DoctorAccess\Services\DoctorSessionLeaseService;
use App\Modules\DoctorAccess\Support\DoctorEffectiveBranch;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Services\Foundation\FeatureFlagService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

require_once __DIR__.'/helpers.php';

/**
 * The four permissions this pull request introduces.
 *
 * Written out ONCE, and every test below either derives its subject from the
 * source or checks itself against this list — never both from the same place, so
 * a permission renamed in one half of the codebase and not the other fails here
 * instead of at a route.
 *
 * @return list<string>
 */
function dblgPermissions(): array
{
    return [
        'view_doctor_branch_locks',
        'manage_doctor_branch_locks',
        'approve_doctor_branch_locks',
        'release_doctor_session_leases',
    ];
}

/*
|--------------------------------------------------------------------------
| No surface may be gated on a permission nobody seeded
|--------------------------------------------------------------------------
*/

it('seeds every permission the controller and both policies actually read', function () {
    $sources = [
        app_path('Modules/DoctorAccess/Controllers/DoctorBranchLockController.php'),
        app_path('Modules/DoctorAccess/Policies/DoctorBranchLockRequestPolicy.php'),
        app_path('Modules/DoctorAccess/Policies/DoctorBranchCoverPolicy.php'),
    ];

    $used = [];

    foreach ($sources as $source) {
        expect(file_exists($source))->toBeTrue();

        preg_match_all(
            "/'((?:view|manage|approve|release)_[a-z0-9_]+)'/",
            (string) file_get_contents($source),
            $matches,
        );

        $used = array_merge($used, $matches[1]);
    }

    $used = array_values(array_unique($used));
    sort($used);

    // The scan has to have found something, or the assertion below is vacuous
    // and would keep passing after a rename that broke every gate.
    expect($used)->not->toBeEmpty();

    foreach ($used as $permission) {
        expect(PermissionSeeder::PERMISSIONS)->toContain($permission);
    }

    // And the four this pull request introduces are all of them, in both
    // directions: a fifth permission read here without being seeded, or one of
    // these four renamed in the seeder only, both fail.
    $expected = dblgPermissions();
    sort($expected);

    expect($used)->toBe($expected);
});

it('seeds every permission the doctor branch lock routes are gated on', function () {
    $gated = [];

    foreach (Route::getRoutes() as $route) {
        $name = (string) $route->getName();

        if (! str_starts_with($name, 'rme.doctor-branch-')) {
            continue;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, 'permission:')) {
                continue;
            }

            foreach (explode('|', substr($middleware, strlen('permission:'))) as $permission) {
                $gated[] = trim($permission);
            }
        }
    }

    $gated = array_values(array_unique(array_filter($gated)));

    // Routes exist and several of the groups carry a permission middleware, so
    // an empty result means the route names moved and this guard stopped
    // guarding anything.
    expect($gated)->not->toBeEmpty();

    foreach ($gated as $permission) {
        expect(PermissionSeeder::PERMISSIONS)->toContain($permission);
    }

    daSeedAccessControl();

    // Seeded in the CONSTANT is not the same statement as present in the
    // DATABASE, and the route middleware reads the database.
    foreach ($gated as $permission) {
        expect(Permission::query()->where('name', $permission)->exists())->toBeTrue();
    }
});

/*
|--------------------------------------------------------------------------
| Classification — never the Other bucket
|--------------------------------------------------------------------------
*/

it('classifies all four permissions into a module group rather than Other', function () {
    daSeedAccessControl();

    $groups = app(PermissionGroupingService::class)->group(Permission::query()->get());

    foreach (dblgPermissions() as $permission) {
        $owners = [];

        foreach ($groups as $group) {
            foreach ($group['permissions'] as $entry) {
                if ($entry['name'] === $permission) {
                    $owners[] = $group['key'];
                }
            }
        }

        // Exactly one group, and not the fallback. A permission in two groups
        // would render twice on the role screen; a permission in none renders
        // under "Other / Uncategorized", which is an invitation to grant it by
        // guess.
        expect($owners)->toBe(['rme']);
    }
});

/*
|--------------------------------------------------------------------------
| Who holds them — read as EFFECTIVE permissions, never as direct rows
|--------------------------------------------------------------------------
*/

it('grants the approver tier to Supervisor RME and to no other operational role', function () {
    // Effective permissions, through can(): a direct-row query would miss a
    // grant that arrives via the role, and a role query would miss a direct
    // grant. Both mistakes read as a pass.
    $supervisor = daSupervisorRme();

    foreach (dblgPermissions() as $permission) {
        expect($supervisor->can($permission))->toBeTrue();
    }

    // The subject of the capability is not an authority over it. A doctor files
    // their OWN home-branch request through the policy against
    // `mst_doctors.user_id` and needs none of these four to do it.
    $doctor = daDoctorUser();

    foreach (dblgPermissions() as $permission) {
        expect($doctor->can($permission))->toBeFalse();
    }

    foreach (['Kasir', 'Admin Lab'] as $role) {
        $actor = daUnauthorisedActor($role);

        foreach (dblgPermissions() as $permission) {
            expect($actor->can($permission))->toBeFalse();
        }
    }
});

/*
|--------------------------------------------------------------------------
| The flag registry
|--------------------------------------------------------------------------
*/

it('registers the branch lock flag OFF, critical, and fully described', function () {
    $flags = app(FeatureFlagService::class);

    $flag = $flags->get(DoctorEffectiveBranchResolver::FLAG_BRANCH_LOCK);

    // The same metadata list the foundation registry test asserts for every
    // flag, restated here so a branch-lock-specific omission is reported against
    // this pull request.
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

    // Both switches are critical, so the release gate must see NEITHER of them
    // among the risky-enabled flags on a committed checkout. The lease flag is
    // named here as well because arming it is what makes this one capable of
    // resolving, so the pair is what actually ships a live capability.
    expect($flags->riskyEnabledFlags())
        ->not->toContain(DoctorEffectiveBranchResolver::FLAG_BRANCH_LOCK)
        ->not->toContain(DoctorSessionLeaseService::FLAG);

    // The two keys are distinct switches. A single key serving both capabilities
    // would make the dependency below unexpressible.
    expect(DoctorEffectiveBranchResolver::FLAG_BRANCH_LOCK)
        ->not->toBe(DoctorSessionLeaseService::FLAG);
});

/*
|--------------------------------------------------------------------------
| An armed branch lock may not outlive a disarmed lease engine
|--------------------------------------------------------------------------
*/

it('reports not-applicable when the session store cannot be observed', function () {
    $branch = daBranch('TLK1');
    ['user' => $user, 'doctor' => $doctor] = daDoctorAccount([$branch]);
    daGrantHomeLock($doctor, $branch);

    $resolver = app(DoctorEffectiveBranchResolver::class);
    $scope = app(RmeWorkingBranchScope::class);

    // Both flags ON. Only the probe is missing — which is the whole point: this
    // must fail SAFE (nothing narrows) rather than fail closed (the doctor is
    // stopped) or fail open (an expired cover keeps granting authority nothing
    // can invalidate, because cover-expiry invalidation lives in the lease
    // middleware and the lease engine is what just disarmed).
    daArmFlags(true, true);
    daUnobservableSessionStore();

    expect($resolver->enabled())->toBeFalse()
        ->and($resolver->resolve($user)->source())->toBe(DoctorEffectiveBranch::SOURCE_NOT_APPLICABLE)
        ->and($resolver->resolve($user)->branchId())->toBeNull()
        ->and($resolver->branchIdFor($user))->toBeNull()
        ->and($resolver->isLocked($user))->toBeFalse();

    // Legacy behaviour, verbatim: the operational scope is exactly what it was
    // before this sprint, not an intersection with the stored lock.
    expect($scope->operationalBranchIdsFor($user))->toBe($scope->branchIdsFor($user));

    // The SAME lock, the SAME flags, with the store observable — so the test
    // proves the probe is the difference rather than the lock being unreadable
    // for some other reason.
    daObservableSessionStore();

    expect($resolver->enabled())->toBeTrue()
        ->and($resolver->resolve($user)->source())->toBe(DoctorEffectiveBranch::SOURCE_HOME)
        ->and($resolver->branchIdFor($user))->toBe((int) $branch->id);
});

/*
|--------------------------------------------------------------------------
| A lock that has stopped applying must be VISIBLE
|--------------------------------------------------------------------------
*/

it('shows a degraded lock on the approver queue', function () {
    $retired = daBranch('LDK2');
    ['user' => $doctorUser, 'doctor' => $doctor] = daDoctorAccount([$retired]);

    daGrantHomeLock($doctor, $retired);

    // The single master-data toggle that silently unlocks a doctor: the branch
    // they are locked to stops being an RME branch. The resolver degrades to
    // UNSET so the doctor keeps working under pre-sprint rules — correct, and
    // correct is not the same as visible.
    $retired->forceFill(['is_rme_enabled' => false])->save();

    daArmDoctorAccess();

    $resolver = app(DoctorEffectiveBranchResolver::class);
    $state = $resolver->resolve($doctorUser);

    expect($state->isDegraded())->toBeTrue()
        ->and($state->branchId())->toBeNull()
        ->and($state->homeBranchId())->toBe((int) $retired->id)
        ->and($state->degradedReason())
        ->toBe(DoctorEffectiveBranch::REASON_HOME_BRANCH_NOT_RME_ENABLED);

    actingAs(daSupervisorRme());

    $response = get(route('rme.doctor-branch-locks.index'));

    $response->assertOk();

    $degraded = collect($response->viewData('degradedLocks'));

    expect($degraded)->toHaveCount(1);

    $row = $degraded->first();

    expect($row['doctor_id'])->toBe((int) $doctor->id)
        ->and($row['home_branch_id'])->toBe((int) $retired->id)
        ->and($row['reason_code'])->toBe(DoctorEffectiveBranch::REASON_HOME_BRANCH_NOT_RME_ENABLED)
        ->and($row['reason_label'])->not->toBeEmpty();

    // Rendered, not merely computed. The reason CODE is on the page beside the
    // sentence because an operator opening a support ticket needs the value an
    // engineer will grep for.
    $response->assertSee('Kunci Cabang Tidak Berlaku')
        ->assertSee(DoctorEffectiveBranch::REASON_HOME_BRANCH_NOT_RME_ENABLED)
        ->assertSee((string) $doctor->name);
});

it('does not report a healthy lock as degraded', function () {
    $branch = daBranch('TLK1');
    ['doctor' => $doctor] = daDoctorAccount([$branch]);

    daGrantHomeLock($doctor, $branch);
    daArmDoctorAccess();

    actingAs(daSupervisorRme());

    $response = get(route('rme.doctor-branch-locks.index'));

    $response->assertOk();

    // A warning that fires for a working lock is a warning operators learn to
    // ignore, which would cost exactly the signal this pair exists to add.
    expect($response->viewData('degradedLocks'))->toBe([]);

    $response->assertDontSee('Kunci Cabang Tidak Berlaku');
});
