<?php

declare(strict_types=1);

/*
| DOCTOR-ACCESS-PR-A-SINGLE-SESSION-FOUNDATION — shared fixtures for the
| DoctorAccess suites.
|
| Deliberately NOT added to tests/Pest.php, following the convention of
| tests/Feature/AccessControl/helpers.php: these helpers are only meaningful to
| this capability, and the global helper file is already the busiest shared
| surface in the suite. Require it from each test file:
|
|     require_once __DIR__.'/helpers.php';
|
| Every function is wrapped in a function_exists() guard so several test files
| in this directory may require it in the same process.
|
| EVERY HELPER BELOW EXISTS TO AVOID A SPECIFIC, MEASURED TRAP. The trap is
| named on the line above the function. None of them are conveniences.
|
| ------------------------------------------------------------------------------
| THE PR-A BOUNDARY, STATED HERE BECAUSE IT IS WHY SOME FAMILIAR HELPERS ARE
| ABSENT.
| ------------------------------------------------------------------------------
|
| This pull request ships the SESSION LEASE and nothing else. There is no branch
| lock, no temporary cover and no effective-branch resolver, so there is no
| home-lock fixture, no cover fixture, and no second flag to arm. A helper that
| built a row for a table this pull request does not create would not merely be
| unused — it would not load. The branch-lock fixtures arrive in PR-B, in the
| same commit as the tables they write to.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorAccess\Models\DoctorSessionLease;
use App\Modules\DoctorAccess\Services\DoctorSessionLeaseService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Feature flags
|--------------------------------------------------------------------------
*/

if (! function_exists('daSetFlag')) {
    /**
     * TRAP: the flag KEY contains a dot, so config()->set('feature_flags.flags.doctor.single_active_session', ...)
     * silently builds a nested structure FeatureFlagService never reads and the test then passes with the
     * flag still OFF — so the whole `flags` array is rewritten, and BOTH `default` and `env_value` are
     * written because FeatureFlagService::resolveOverride() prefers a captured `env_value` over `default`.
     */
    function daSetFlag(string $key, bool $enabled): void
    {
        $flags = config('feature_flags.flags', []);

        if (! isset($flags[$key]) || ! is_array($flags[$key])) {
            throw new InvalidArgumentException("Unknown feature flag: {$key}");
        }

        $flags[$key]['default'] = $enabled;
        $flags[$key]['env_value'] = $enabled;

        config()->set('feature_flags.flags', $flags);
    }
}

if (! function_exists('daArmSingleActiveSession')) {
    /**
     * TRAP: `doctor.single_active_session` defaults FALSE, and while it is off the claim listener returns
     * before its first query — a lease test that forgets this asserts on an engine that never ran.
     */
    function daArmSingleActiveSession(bool $enabled = true): void
    {
        daSetFlag(DoctorSessionLeaseService::FLAG, $enabled);
    }
}

if (! function_exists('daArmDoctorAccess')) {
    /**
     * TRAP: arming the flag is not enough — the lease engine ALSO requires
     * IncumbentSessionProbe::observable(), and phpunit.xml pins SESSION_DRIVER=array, so the capability is
     * inert on a default test run. This is the one call that makes the whole capability live, and every
     * armed test below starts with it.
     *
     * ONE FLAG, not two. PR-A has exactly one switch; the branch lock's second flag arrives with the
     * branch lock, and a helper that armed a flag this pull request does not register would throw from
     * daSetFlag() rather than quietly pass.
     */
    function daArmDoctorAccess(): void
    {
        daArmSingleActiveSession(true);
        daObservableSessionStore();
    }
}

if (! function_exists('daDisarmFlags')) {
    /**
     * TRAP: config is restored between tests, but a test that arms mid-way then asserts the OFF behaviour
     * in the same test needs an explicit disarm rather than a fresh test — the query-budget test measures
     * exactly that, an armed request against a disarmed one in a single process.
     *
     * The session driver is deliberately LEFT OBSERVABLE. Disarming through the driver instead would prove
     * the probe rather than the flag, and the budget measurement needs the flag to be the only difference.
     */
    function daDisarmFlags(): void
    {
        daArmSingleActiveSession(false);
    }
}

/*
|--------------------------------------------------------------------------
| Session-store observability
|--------------------------------------------------------------------------
*/

if (! function_exists('daObservableSessionStore')) {
    /**
     * TRAP: phpunit.xml sets SESSION_DRIVER=array, so IncumbentSessionProbe::observable() is FALSE and
     * DoctorSessionLeaseService::enabled() returns false however the flag is set. Switching the driver to
     * `database` also makes the framework's own session handler write a real `sessions` row
     * (StartSession::handleStatefulRequest() saves within the request), so an incumbent created by
     * daLoginPost() is genuinely LIVE rather than needing a hand-inserted row.
     */
    function daObservableSessionStore(): void
    {
        config()->set('session.driver', 'database');
    }
}

if (! function_exists('daUnobservableSessionStore')) {
    /**
     * TRAP: proving the fail-safe disarm (the engine must go INERT, never fail closed toward denial, on an
     * unobservable driver) needs the driver put back explicitly; asserting it by simply not calling
     * daObservableSessionStore() proves nothing, because that is also what a forgotten setup looks like.
     */
    function daUnobservableSessionStore(): void
    {
        config()->set('session.driver', 'array');
    }
}

/*
|--------------------------------------------------------------------------
| Actors
|--------------------------------------------------------------------------
*/

if (! function_exists('daSeedAccessControl')) {
    /**
     * TRAP: assignRole() throws RoleDoesNotExist unless PermissionSeeder and RoleSeeder have run, and
     * seeding twice in one test wastes seconds on the slowest seeders in the suite — so this seeds once,
     * detected from the database rather than from a static that RefreshDatabase would not reset.
     */
    function daSeedAccessControl(): void
    {
        if (Role::query()->where('name', 'Super Admin')->exists()) {
            return;
        }

        test()->seed([PermissionSeeder::class, RoleSeeder::class]);
    }
}

if (! function_exists('daSuperAdmin')) {
    /**
     * TRAP: Super Admin passes every permission check through the single global Gate::before, so no
     * permission-shaped guard can ever refuse this actor. That is exactly why the self-release refusal
     * lives inside the release transaction rather than in a policy — and why a test that only ever acts as
     * a Super Admin cannot see a missing grant. Two calls return two DIFFERENT accounts.
     */
    function daSuperAdmin(): User
    {
        daSeedAccessControl();

        return superAdmin();
    }
}

if (! function_exists('daSupervisorRme')) {
    /**
     * TRAP: this is the one operational role RoleSeeder grants `release_doctor_session_leases`, and unlike
     * Super Admin it has NO Gate::before bypass — so it is the only actor that proves the permission check
     * itself rather than proving the bypass. It is also the actor a force-logout test should use, because a
     * refusal measured against a Super Admin could never distinguish "authorized" from "short-circuited".
     */
    function daSupervisorRme(): User
    {
        daSeedAccessControl();

        return userInRole('Supervisor RME');
    }
}

if (! function_exists('daUnauthorisedActor')) {
    /**
     * TRAP: "unauthorised" must mean a real seeded role that holds none of this sprint's permissions, not a
     * role-less user — a role-less user would pass a denial test for the wrong reason. Kasir is the default
     * because RoleSeeder grants it no doctor-access permission at all.
     */
    function daUnauthorisedActor(string $role = 'Kasir'): User
    {
        daSeedAccessControl();

        return userInRole($role);
    }
}

/*
|--------------------------------------------------------------------------
| Branch and doctor fixtures
|--------------------------------------------------------------------------
*/

if (! function_exists('daBranch')) {
    /**
     * TRAP: `mst_branches.code` is unique, so two fixtures naming the same branch collide. Idempotent by
     * code, so a test may name a branch more than once. `is_active` and `is_rme_enabled` are both set
     * because an RME population is what this capability is about; pass ['is_active' => false] deliberately
     * if a test needs the inactive case.
     */
    function daBranch(string $code = 'TLK1', array $attributes = []): Branch
    {
        $branch = Branch::withTrashed()->firstOrNew(['code' => $code]);

        $branch->forceFill(array_merge([
            'name' => $branch->exists ? $branch->name : 'Cabang '.$code,
            'is_active' => true,
            'is_rme_enabled' => true,
            'deleted_at' => null,
        ], $attributes))->save();

        return $branch->refresh();
    }
}

if (! function_exists('daDoctorAccount')) {
    /**
     * TRAP three ways. (1) DoctorSessionLeaseService::subjectTo() keys on the 'Doctor' ROLE, so an account
     * without it is silently exempt from single-session enforcement and every assertion about a lease reads
     * as a pass. (2) `mst_doctors.user_id` is nullable, and an unlinked Doctor-role account binds nobody —
     * DoctorAccessSubjectGuard refuses it outright — so the link is mandatory here; build the unlinked case
     * deliberately by clearing it afterwards. (3) `branch_id` is pinned to the first named branch so
     * DoctorFactory does not create a stray extra branch behind the test's back, and the practice pivot is
     * populated explicitly rather than left to the factory.
     *
     * @param  array<int, Branch|int>  $practiceBranches
     * @param  array<string, mixed>  $doctorAttributes
     * @return array{user: User, doctor: Doctor}
     */
    function daDoctorAccount(
        array $practiceBranches = [],
        array $doctorAttributes = [],
        ?User $user = null,
    ): array {
        daSeedAccessControl();

        $user ??= User::factory()->create();

        if (! $user->hasRole('Doctor')) {
            $user->assignRole('Doctor');
        }

        $branchIds = array_values(array_unique(array_map(
            fn (Branch|int $branch): int => $branch instanceof Branch ? (int) $branch->id : $branch,
            $practiceBranches,
        )));

        $state = array_merge([
            'user_id' => $user->id,
            'is_active' => true,
        ], $doctorAttributes);

        if ($branchIds !== [] && ! array_key_exists('branch_id', $doctorAttributes)) {
            $state['branch_id'] = $branchIds[0];
        }

        $factory = Doctor::factory();

        if ($branchIds !== []) {
            $factory = $factory->withAllowedBranches($branchIds);
        }

        $doctor = $factory->create($state);

        return ['user' => $user->refresh(), 'doctor' => $doctor->refresh()];
    }
}

if (! function_exists('daDoctorUser')) {
    /**
     * TRAP: most lease tests act as the USER and never need the doctor record, while the force-logout tests
     * address the DOCTOR by id — and re-deriving one from the other invites a mismatch. This returns the
     * user; use daDoctorAccount() whenever the doctor record is also needed.
     *
     * @param  array<int, Branch|int>  $practiceBranches
     */
    function daDoctorUser(array $practiceBranches = []): User
    {
        return daDoctorAccount($practiceBranches)['user'];
    }
}

/*
|--------------------------------------------------------------------------
| The server-side sessions table (incumbent liveness)
|--------------------------------------------------------------------------
*/

if (! function_exists('daInsertSessionRow')) {
    /**
     * TRAP: the `sessions` table EXISTS in every run — the framework migration creates it and
     * RefreshDatabase migrates it regardless of driver — only the WRITER is absent under the array driver.
     * So incumbent liveness IS testable without switching drivers, provided the test inserts the row
     * itself. `payload` is NOT NULL, and `last_activity` is an integer unix timestamp compared against
     * now()->subMinutes(config('session.lifetime')), so a row older than that reads DEAD even though it
     * exists.
     */
    function daInsertSessionRow(User|int $user, string $sessionId, ?int $lastActivity = null): string
    {
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user instanceof User ? (int) $user->id : $user,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'DoctorAccess test fixture',
            'payload' => base64_encode(serialize([])),
            'last_activity' => $lastActivity ?? now()->getTimestamp(),
        ]);

        return $sessionId;
    }
}

if (! function_exists('daDeleteSessionRow')) {
    /**
     * TRAP: IncumbentSessionProbe::isLive() keys on user_id, NOT on the session id recorded at claim time,
     * because the framework mints a new id on every re-authentication. Deleting ONE row therefore only
     * makes an incumbent dead if it was that user's ONLY session row — use daKillSessionsFor() to
     * simulate a dead incumbent reliably.
     */
    function daDeleteSessionRow(string $sessionId): int
    {
        return DB::table('sessions')->where('id', $sessionId)->delete();
    }
}

if (! function_exists('daKillSessionsFor')) {
    /**
     * TRAP: this is the ONLY reliable way to simulate the dead-incumbent reclaim (a doctor who finished on
     * the ward tablet and walked to the office PC), because liveness is a user_id predicate: one surviving
     * row for the same user keeps the incumbent ALIVE and the next login is refused, not reclaimed.
     */
    function daKillSessionsFor(User|int $user): int
    {
        return DB::table('sessions')
            ->where('user_id', $user instanceof User ? (int) $user->id : $user)
            ->delete();
    }
}

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

if (! function_exists('daLoginPost')) {
    /**
     * TRAP, and the single most important one in this file: actingAs() goes through SessionGuard::setUser(),
     * which fires Authenticated and NOT Login, so it NEVER claims a lease. Only a real POST to the login
     * route fires Illuminate\Auth\Events\Login and reaches ClaimDoctorSessionLease. A lease test built on
     * actingAs() passes while asserting on an engine that never ran.
     *
     * Each call is a fresh request with no cookie jar, which is one half of what makes two calls behave as
     * two different browsers — the other half is the in-memory session store, which SURVIVES between
     * requests in one test. Where the assertion is genuinely about two browsers, use the local
     * dslNewBrowser() rather than a second daLoginPost() alone.
     *
     * UserFactory hashes the same static 'password' for every account, so the default password is correct
     * unless the test changed it.
     */
    function daLoginPost(User $user, string $password = 'password', bool $remember = false): TestResponse
    {
        return test()->post('/login', [
            'email' => $user->email,
            'password' => $password,
            'remember' => $remember,
        ]);
    }
}

/*
|--------------------------------------------------------------------------
| Lease assertions
|--------------------------------------------------------------------------
*/

if (! function_exists('daCurrentLease')) {
    /**
     * TRAP: "the lease" means the UNRELEASED one. Released rows are retained deliberately as the audit
     * trail — the partial unique index only constrains `released_at IS NULL` — so a bare latest() or
     * first() picks up history and a re-claim test reads the wrong row. This reads the table directly
     * rather than through the repository under test.
     */
    function daCurrentLease(User|int $user): ?DoctorSessionLease
    {
        return DoctorSessionLease::query()
            ->where('user_id', $user instanceof User ? (int) $user->id : $user)
            ->whereNull('released_at')
            ->first();
    }
}

if (! function_exists('daActiveLeaseCount')) {
    /**
     * TRAP: with no user, this counts leases across the WHOLE table, which is how the pass-through rule is
     * proven — actingAs() a doctor with everything armed must leave ZERO lease rows anywhere, and a
     * per-user count would hide a lease claimed for a different account.
     */
    function daActiveLeaseCount(User|int|null $user = null): int
    {
        return DoctorSessionLease::query()
            ->whereNull('released_at')
            ->when(
                $user !== null,
                fn ($query) => $query->where(
                    'user_id',
                    $user instanceof User ? (int) $user->id : $user,
                ),
            )
            ->count();
    }
}

if (! function_exists('daAssertActiveLeaseCount')) {
    /**
     * TRAP: an expectation written as expect(DoctorSessionLease::count())->toBe(1) counts RELEASED rows
     * too and drifts the moment a test releases and re-claims — this names the invariant (at most one
     * unreleased lease per user) instead of a row total.
     */
    function daAssertActiveLeaseCount(int $expected, User|int|null $user = null): void
    {
        expect(daActiveLeaseCount($user))->toBe($expected);
    }
}
