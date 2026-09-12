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
| THE PR-B BOUNDARY. PR-A's note said the branch-lock fixtures would arrive in
| the same commit as the tables they write to; they are below.
| ------------------------------------------------------------------------------
|
| PR-A shipped the SESSION LEASE and had exactly one flag. PR-B adds the HOME
| LOCK, the TEMPORARY COVER and the EFFECTIVE-BRANCH RESOLVER, and with them a
| SECOND flag whose coupling to the first is real but undeclared: the resolver
| requires BOTH flags AND an observable session store. Arming only
| `doctor.branch_lock` leaves the resolver disabled, and a test that does so
| proves the OFF path while claiming to prove the lock. daArmDoctorAccess() is
| the one call that makes the whole capability live and is what nearly every
| armed test wants.
|
| PR-C adds the BULK DEVICE-AUTHORIZATION fixtures below, under a dba* prefix.
| The prefix is not decoration: this directory's fixtures live in the GLOBAL
| namespace alongside roughly a hundred others from sibling suites, several of
| them unguarded, so a name collision is a fatal redeclare rather than a
| shadowed helper.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorAccess\Models\DoctorBranchCover;
use App\Modules\DoctorAccess\Models\DoctorBranchLock;
use App\Modules\DoctorAccess\Models\DoctorSessionLease;
use App\Modules\DoctorAccess\Services\DoctorEffectiveBranchResolver;
use App\Modules\DoctorAccess\Services\DoctorSessionLeaseService;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use Carbon\CarbonInterface;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

if (! function_exists('daArmBranchLock')) {
    /**
     * TRAP: `doctor.branch_lock` defaults FALSE and its registry `dependencies` entry is DECORATIVE —
     * nothing reads it. Arming this flag alone still leaves DoctorEffectiveBranchResolver::enabled() false,
     * because that method requires the lease flag and the session-store probe as well. Use this directly
     * only when the test is specifically proving the dependency refusal.
     */
    function daArmBranchLock(bool $enabled = true): void
    {
        daSetFlag(DoctorEffectiveBranchResolver::FLAG_BRANCH_LOCK, $enabled);
    }
}

if (! function_exists('daArmFlags')) {
    /**
     * TRAP: the two flags are independent switches with a real but undeclared runtime coupling, so a test
     * that needs the branch lock to actually resolve must arm both — this is the only call that makes
     * DoctorEffectiveBranchResolver::enabled() capable of returning true.
     */
    function daArmFlags(bool $singleActiveSession = true, bool $branchLock = true): void
    {
        daArmSingleActiveSession($singleActiveSession);
        daArmBranchLock($branchLock);
    }
}

if (! function_exists('daArmDoctorAccess')) {
    /**
     * TRAP: arming the flags is not enough — the lease engine and the branch resolver BOTH also require
     * IncumbentSessionProbe::observable(), and phpunit.xml pins SESSION_DRIVER=array, so the capability is
     * inert on a default test run. This is the one call that makes the whole capability live, and every
     * armed test below starts with it.
     *
     * TWO FLAGS now, where PR-A had one. A branch-lock test that calls only daArmSingleActiveSession()
     * leaves the resolver disabled and its every answer null, which the runtime treats as UNSET — so the
     * test would pass by proving legacy behaviour.
     */
    function daArmDoctorAccess(): void
    {
        daArmFlags(true, true);
        daObservableSessionStore();
    }
}

if (! function_exists('daDisarmFlags')) {
    /**
     * TRAP: config is restored between tests, but a test that arms mid-way then asserts the OFF behaviour
     * in the same test needs an explicit disarm rather than a fresh test — the query-budget test measures
     * exactly that, an armed request against a disarmed one in a single process. BOTH flags must go: either
     * one left on keeps part of the capability live.
     *
     * The session driver is deliberately LEFT OBSERVABLE. Disarming through the driver instead would prove
     * the probe rather than the flags, and the budget measurement needs the flags to be the only difference.
     */
    function daDisarmFlags(): void
    {
        daArmFlags(false, false);
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
| Home lock and temporary cover
|--------------------------------------------------------------------------
*/

if (! function_exists('daGrantHomeLock')) {
    /**
     * TRAP: firstOrNew(['doctor_id' => …]) MASS ASSIGNS its search attributes through the constructor, and
     * this model's $fillable is deliberately empty, so it throws before the forceFill() below is ever
     * reached — measured, and it was 57 of 95 failures in one run. Look the row up and build a bare model.
     *
     * This grants the lock DIRECTLY, bypassing DoctorBranchLockApprovalService on purpose: a resolver or
     * list test must not depend on the approval path passing, and the approval path has its own suite.
     *
     * NOTE: the target branch is NOT validated against the practice pivot here — that is exactly the
     * approval service's job. Pass a branch outside the pivot deliberately to build the stranded-doctor
     * case the approver queue has to surface.
     */
    function daGrantHomeLock(
        Doctor $doctor,
        Branch|int $homeBranch,
        ?User $establishedBy = null,
        string $establishedVia = DoctorBranchLock::VIA_INITIAL_ASSIGNMENT,
    ): DoctorBranchLock {
        $branchId = $homeBranch instanceof Branch ? (int) $homeBranch->id : $homeBranch;

        $lock = DoctorBranchLock::query()
            ->where('doctor_id', (int) $doctor->id)
            ->first() ?? new DoctorBranchLock;

        $lock->forceFill([
            'doctor_id' => (int) $doctor->id,
            'home_branch_id' => $branchId,
            'established_via' => $establishedVia,
            'established_at' => now(),
            'established_by_user_id' => $establishedBy?->id,
        ])->save();

        return $lock->refresh();
    }
}

if (! function_exists('daApprovedCover')) {
    /**
     * TRAP four ways. (1) `status` and the decision stamps are NOT in $fillable, so an approved cover cannot
     * be built with create() alone — forceFill() is required, and a cover left PENDING is not approved, so
     * the resolver returns the home branch and the test silently proves nothing. (2)
     * `source_home_branch_id` is NOT NULL; it defaults here to the doctor's live home lock so the row is not
     * stale by construction. (3) The period is an INSTANT PAIR and half-open [starts_at, ends_at) — pass
     * instants relative to now(), never a clinical date, and remember a cover whose ends_at equals the
     * instant under test does NOT cover it. (4) requester and decider default to two DIFFERENT accounts,
     * because maker == checker is forbidden; pass the same user for both only when deliberately building an
     * illegal historical row.
     *
     * NOTE: this writes the row directly, so the configured cover bounds are NOT applied. A period outside
     * them is representable here but would be refused by DoctorBranchCoverApprovalService, so do not use
     * such a period to describe production state.
     */
    function daApprovedCover(
        Doctor $doctor,
        Branch|int $targetBranch,
        CarbonInterface|string $startsAt,
        CarbonInterface|string $endsAt,
        ?User $requester = null,
        ?User $decidedBy = null,
        Branch|int|null $sourceHomeBranch = null,
    ): DoctorBranchCover {
        $targetBranchId = $targetBranch instanceof Branch ? (int) $targetBranch->id : $targetBranch;

        $sourceHomeBranchId = match (true) {
            $sourceHomeBranch instanceof Branch => (int) $sourceHomeBranch->id,
            is_int($sourceHomeBranch) => $sourceHomeBranch,
            default => (int) (DoctorBranchLock::query()
                ->where('doctor_id', (int) $doctor->id)
                ->value('home_branch_id') ?? $targetBranchId),
        };

        $requester ??= User::factory()->create();
        $decidedBy ??= User::factory()->create();

        $cover = new DoctorBranchCover;

        $cover->forceFill([
            'doctor_id' => (int) $doctor->id,
            'requester_user_id' => (int) $requester->id,
            'source_home_branch_id' => $sourceHomeBranchId,
            'target_branch_id' => $targetBranchId,
            'starts_at' => $startsAt instanceof CarbonInterface ? $startsAt : Carbon::parse($startsAt),
            'ends_at' => $endsAt instanceof CarbonInterface ? $endsAt : Carbon::parse($endsAt),
            'reason' => 'Fixture cover for the DoctorAccess suite.',
            'requested_at' => now(),
            'status' => DoctorBranchCover::STATUS_APPROVED,
            'decided_by_user_id' => (int) $decidedBy->id,
            'decided_at' => now(),
        ])->save();

        return $cover->refresh();
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

/*
|--------------------------------------------------------------------------
| Bulk device authorization (PR-C)
|--------------------------------------------------------------------------
*/

if (! function_exists('dbaTrustedDevice')) {
    /**
     * A device that PR-C considers ELIGIBLE.
     *
     * TRAP: DoctorDeviceFactory defaults `identity_state` to IDENTITY_UNVERIFIED, and an
     * unverified device is refused by DoctorDeviceAuthorizationService::approve() outright.
     * A test that took the factory default would build a fixture the whole feature declines
     * to act on and would then "prove" the exclusion it never meant to write.
     *
     * @param  array<string, mixed>  $attributes
     */
    function dbaTrustedDevice(array $attributes = [], ?Branch $branch = null): DoctorDevice
    {
        $branch ??= daBranch('Cabang Perangkat');

        return DoctorDevice::factory()->create(array_merge([
            'branch_id' => $branch->id,
            'status' => DoctorDevice::STATUS_ACTIVE,
            'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
            'enrollment_status' => DoctorDevice::ENROLLMENT_VERIFIED,
            'public_key_fingerprint' => hash('sha256', (string) Str::uuid()),
        ], $attributes));
    }
}

if (! function_exists('dbaUnverifiedDevice')) {
    /** Active, admitted hardware that has never proved possession of a key. */
    function dbaUnverifiedDevice(?Branch $branch = null): DoctorDevice
    {
        return dbaTrustedDevice([
            'identity_state' => DoctorDevice::IDENTITY_UNVERIFIED,
            'public_key_fingerprint' => null,
        ], $branch);
    }
}

if (! function_exists('dbaPendingApprovalDevice')) {
    /**
     * Hardware that registered itself at a doctor's first login and that no human has
     * admitted. THE device state PR-C must never promote: approve() would flip it to
     * ACTIVE and write a DOCTOR_DEVICE_ADMITTED audit row.
     */
    function dbaPendingApprovalDevice(?Branch $branch = null): DoctorDevice
    {
        return dbaTrustedDevice(['status' => DoctorDevice::STATUS_PENDING_APPROVAL], $branch);
    }
}

if (! function_exists('dbaAuthorization')) {
    /**
     * An authorization row in an exact state, for an exact pair.
     *
     * Built through the factory rather than the service on purpose: these fixtures describe
     * an estate the run FINDS, not one it created, and driving them through the lifecycle
     * would make the fixture depend on the code under test.
     */
    function dbaAuthorization(
        Doctor $doctor,
        DoctorDevice $device,
        string $status = DoctorDeviceAuthorization::STATUS_ACTIVE,
    ): DoctorDeviceAuthorization {
        $factory = DoctorDeviceAuthorization::factory();

        $factory = match ($status) {
            DoctorDeviceAuthorization::STATUS_ACTIVE => $factory->active(),
            DoctorDeviceAuthorization::STATUS_REJECTED => $factory->rejected(),
            DoctorDeviceAuthorization::STATUS_REVOKED => $factory->revoked(),
            default => $factory,
        };

        return $factory->create([
            'doctor_id' => $doctor->id,
            'doctor_device_id' => $device->id,
        ]);
    }
}

if (! function_exists('dbaRun')) {
    /**
     * Invoke the command and return its raw output.
     *
     * TRAP: expectsOutputToContain() consumes ONE writeln per expectation, so a suite that
     * used it would silently assert only the first of many printed counters. Artisan::call()
     * plus Artisan::output() reads the whole buffer and lets a test assert on all of it.
     *
     * @param  array<string, mixed>  $options
     * @return array{exit: int, output: string}
     */
    function dbaRun(array $options = []): array
    {
        $exit = Artisan::call('doctor:device-bulk-authorize', $options);

        return ['exit' => $exit, 'output' => Artisan::output()];
    }
}

if (! function_exists('dbaDigest')) {
    /** The plan digest the dry run printed, which --apply demands back verbatim. */
    function dbaDigest(string $output): string
    {
        expect($output)->toMatch('/PLAN_DIGEST=[0-9a-f]{12}/');

        preg_match('/PLAN_DIGEST=([0-9a-f]{12})/', $output, $matches);

        return $matches[1];
    }
}

if (! function_exists('dbaCounter')) {
    /** One printed counter, as an int. Fails loudly rather than defaulting to zero. */
    function dbaCounter(string $output, string $key): int
    {
        expect($output)->toMatch('/'.preg_quote($key, '/').'=\d+/');

        preg_match('/'.preg_quote($key, '/').'=(\d+)/', $output, $matches);

        return (int) $matches[1];
    }
}

if (! function_exists('dbaDeviceSnapshot')) {
    /**
     * Every column of every device row, ordered.
     *
     * The non-mutation proof is a SNAPSHOT COMPARISON rather than a count: a run that
     * flipped one device from pending_approval to active would leave the count identical
     * and only the column values would betray it.
     *
     * @return array<int, array<string, mixed>>
     */
    function dbaDeviceSnapshot(): array
    {
        return DB::table('mst_doctor_devices')->orderBy('id')->get()
            ->map(fn ($row): array => (array) $row)->all();
    }
}

if (! function_exists('dbaAuditCount')) {
    /** How many audit rows carry one action. */
    function dbaAuditCount(string $action): int
    {
        return (int) DB::table('sys_audit_logs')->where('action', $action)->count();
    }
}

if (! function_exists('dbaActiveMatrix')) {
    /**
     * Every ACTIVE (doctor, device) pair, as sorted "doctorId:deviceId" strings.
     *
     * @return list<string>
     */
    function dbaActiveMatrix(): array
    {
        $pairs = DB::table('mst_doctor_device_authorizations')
            ->where('status', DoctorDeviceAuthorization::STATUS_ACTIVE)
            ->orderBy('doctor_id')->orderBy('doctor_device_id')
            ->get()
            ->map(fn ($row): string => $row->doctor_id.':'.$row->doctor_device_id)
            ->all();

        sort($pairs);

        return $pairs;
    }
}
