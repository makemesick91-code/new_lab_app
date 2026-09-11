<?php

declare(strict_types=1);

/*
| DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — THE APPROVAL WORKFLOWS.
|
| Three workflows share one permission tier and one row-lock discipline, so they
| are proven in one file:
|
|   INITIAL ASSIGNMENT   UNSET            -> approved HOME_LOCKED_BRANCH
|   PERMANENT TRANSFER   home branch A    -> approved home branch B
|   TEMPORARY COVER      time-boxed authority to work somewhere else, auto-reverting
|
| WHAT THIS FILE IS FOR, in one sentence: the owner's strict actor-based
| maker-checker rule (MERGED_PLAN section W, owner decision O7) and the section Q
| cover semantics are asserted here as BEHAVIOUR, not as documentation.
|
| ─────────────────────────────────────────────────────────────────────────────
| WHY THE SERVICE, NOT ONLY THE ROUTE
| ─────────────────────────────────────────────────────────────────────────────
|
| The single global `Gate::before` (app/Providers/RepositoryServiceProvider.php)
| returns TRUE for a Super Admin before ANY policy method runs. A maker-checker
| clause written only in `DoctorBranchCoverPolicy::decide()` would therefore never
| execute for the one actor most able to be both parties. Owner test 8 is
| satisfied only if the refusal lives in the SERVICE, inside the transaction,
| under the row lock — so section A proves the bypass is real AND that the service
| refuses anyway. Both halves are asserted; neither is argued.
|
| ─────────────────────────────────────────────────────────────────────────────
| SET-UP FACTS THAT ARE NOT OPTIONAL (each one measured, not assumed)
| ─────────────────────────────────────────────────────────────────────────────
|
| * Both feature flags default FALSE, and `DoctorBranchLockController` 404s every
|   action while `DoctorEffectiveBranchResolver::enabled()` is false — which also
|   requires `IncumbentSessionProbe::observable()`, i.e. a session driver of
|   `database`, while phpunit.xml pins SESSION_DRIVER=array. `daArmDoctorAccess()`
|   is the ONE call that arms all three; see tests/Feature/DoctorAccess/helpers.php.
| * `actingAs()` fires Authenticated, never Login, so it NEVER claims a lease.
|   Every "the doctor holds a live session" fixture below goes through
|   `daLoginPost()`, which is a real POST to the login route.
| * All DoctorAccess routes are in `EnsureRmeOnlineContext::EXEMPT_ROUTE_NAMES`,
|   so no middleware bypass is needed for the HTTP cases — verified in the
|   middleware, not assumed.
| * Cover periods are INSTANT PAIRS, half-open [starts_at, ends_at). Operator
|   input is parsed in the CLINICAL zone (WITA) while the app timezone is UTC, so
|   every input string here is formatted from `ClinicalClock::now()` and every
|   directly written row is formatted from `now()` (UTC), which is the frame the
|   column actually stores.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorAccess\Models\DoctorBranchCover;
use App\Modules\DoctorAccess\Models\DoctorBranchLock;
use App\Modules\DoctorAccess\Models\DoctorBranchLockRequest;
use App\Modules\DoctorAccess\Models\DoctorSessionLease;
use App\Modules\DoctorAccess\Policies\DoctorBranchCoverPolicy;
use App\Modules\DoctorAccess\Policies\DoctorBranchLockRequestPolicy;
use App\Modules\DoctorAccess\Services\DoctorBranchCoverApprovalService;
use App\Modules\DoctorAccess\Services\DoctorBranchLockApprovalService;
use App\Modules\DoctorAccess\Services\DoctorEffectiveBranchResolver;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use App\Support\Clinical\ClinicalClock;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Assert;

require_once __DIR__.'/helpers.php';

/*
|--------------------------------------------------------------------------
| Local fixtures — deliberately NOT added to helpers.php
|--------------------------------------------------------------------------
|
| helpers.php is the shared surface for every DoctorAccess suite and is owned
| elsewhere. Everything below is specific to the approval workflows, so it lives
| here and is guarded with function_exists() for the same reason helpers.php is:
| several files in this directory may be required into one process.
*/

if (! function_exists('dbaScene')) {
    /**
     * A doctor, their practice pivot, and optionally their home lock.
     *
     * TRAP: the practice pivot is load-bearing on the APPROVAL path only —
     * `assertWithinPracticeBranches()` runs in approve(), not in request()
     * (finding V1). So a scene that pivots the doctor to every branch proves the
     * happy path, and a scene that pivots them to fewer proves the stranding
     * guard. Both shapes are needed, hence the explicit code list.
     *
     * @param  array<int, string>  $practiceCodes
     * @return array{branches: array<string, Branch>, doctor: Doctor, user: User}
     */
    function dbaScene(array $practiceCodes = ['TLK1', 'LDK2', 'ATG3'], ?string $homeCode = 'TLK1'): array
    {
        $branches = [];

        foreach ($practiceCodes as $code) {
            $branches[$code] = daBranch($code);
        }

        if ($homeCode !== null && ! array_key_exists($homeCode, $branches)) {
            $branches[$homeCode] = daBranch($homeCode);
        }

        $account = daDoctorAccount(array_values(array_map(
            fn (string $code): Branch => $branches[$code],
            $practiceCodes,
        )));

        if ($homeCode !== null) {
            daGrantHomeLock($account['doctor'], $branches[$homeCode]);
        }

        return [
            'branches' => $branches,
            'doctor' => $account['doctor'],
            'user' => $account['user'],
        ];
    }
}

if (! function_exists('dbaCoverInput')) {
    /**
     * Operator input for a cover period, expressed on the CLINICAL wall clock.
     *
     * TRAP: `DoctorBranchCoverPeriod::fromOperatorInput()` parses the string in
     * the clinical zone (WITA, +08) while `config('app.timezone')` is UTC. A
     * string built from `now()` would therefore be read eight hours later than
     * intended, which silently pushes a "starts an hour ago" fixture into the
     * future and makes an activation test assert on an inactive cover.
     *
     * @return array{starts_at: string, ends_at: string}
     */
    function dbaCoverInput(int $startsInHours, int $endsInHours): array
    {
        $now = app(ClinicalClock::class)->now();

        return [
            'starts_at' => $now->addHours($startsInHours)->format('Y-m-d H:i:s'),
            'ends_at' => $now->addHours($endsInHours)->format('Y-m-d H:i:s'),
        ];
    }
}

if (! function_exists('dbaPendingCover')) {
    /**
     * A PENDING cover row written DIRECTLY, bypassing
     * `DoctorBranchCoverApprovalService::request()`.
     *
     * This exists for exactly one purpose: the advisory overlap check in
     * request() would refuse an overlapping period before it ever reached the
     * queue, so the ENFORCED check inside approve() — the one the non-overlap
     * invariant actually rests on — could never be exercised through the front
     * door. Writing the row directly is what makes that check testable.
     *
     * `status` is not in $fillable, so forceFill() is mandatory.
     */
    function dbaPendingCover(
        Doctor $doctor,
        Branch $target,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        User $requester,
        Branch $sourceHome,
    ): DoctorBranchCover {
        $cover = new DoctorBranchCover;

        $cover->forceFill([
            'doctor_id' => (int) $doctor->id,
            'requester_user_id' => (int) $requester->id,
            'source_home_branch_id' => (int) $sourceHome->id,
            'target_branch_id' => (int) $target->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'reason' => 'Pengajuan cover untuk pengujian jalur persetujuan.',
            'requested_at' => now(),
            'status' => DoctorBranchCover::STATUS_PENDING,
        ])->save();

        return $cover->refresh();
    }
}

if (! function_exists('dbaDeviceTrail')) {
    /**
     * The three rows a branch decision must NEVER touch (ruling P17, section Q).
     *
     * TRAP: `trx_doctor_device_webauthn_credentials` binds to `doctor_device_id`
     * ONLY — there is no doctor column, because a credential proves the DEVICE,
     * not the clinician. So the trail is device + authorization + credential, and
     * a test that only checked the authorization would miss the credential
     * entirely.
     *
     * @return array{device: DoctorDevice, authorization: DoctorDeviceAuthorization, credential: DoctorDeviceWebAuthnCredential}
     */
    function dbaDeviceTrail(Doctor $doctor): array
    {
        $device = DoctorDevice::factory()->create();

        $authorization = DoctorDeviceAuthorization::factory()->active()->create([
            'doctor_id' => (int) $doctor->id,
            'doctor_device_id' => (int) $device->id,
        ]);

        $credential = DoctorDeviceWebAuthnCredential::create([
            'uuid' => (string) Str::uuid(),
            'doctor_device_id' => (int) $device->id,
            'credential_id' => 'dba-cred-'.Str::random(24),
            'public_key' => 'dba-public-key',
            'user_verified' => true,
            'device_bound_verdict' => DoctorDeviceWebAuthnCredential::VERDICT_DEVICE_BOUND,
            'registered_at' => now(),
        ]);

        return [
            'device' => $device,
            'authorization' => $authorization,
            'credential' => $credential,
        ];
    }
}

if (! function_exists('dbaAssertDeviceTrailIntact')) {
    /**
     * @param  array{device: DoctorDevice, authorization: DoctorDeviceAuthorization, credential: DoctorDeviceWebAuthnCredential}  $trail
     */
    function dbaAssertDeviceTrailIntact(array $trail): void
    {
        expect($trail['device']->refresh()->status)->toBe(DoctorDevice::STATUS_ACTIVE)
            ->and($trail['device']->revoked_at)->toBeNull()
            ->and($trail['authorization']->refresh()->status)->toBe(DoctorDeviceAuthorization::STATUS_ACTIVE)
            ->and($trail['authorization']->revoked_at)->toBeNull()
            ->and($trail['credential']->refresh()->revoked_at)->toBeNull();
    }
}

if (! function_exists('dbaCatchValidation')) {
    /**
     * TRAP: `ValidationException::getMessage()` is a SUMMARY of the first error,
     * so a substring assertion on it silently stops distinguishing two refusals
     * that happen to share a prefix. The error KEY is the stable contract — it is
     * what the surface renders the message under — so refusals are asserted on
     * `errors()` and the message is checked separately where its wording is the
     * point.
     */
    function dbaCatchValidation(Closure $callback): ValidationException
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            return $exception;
        }

        Assert::fail('Expected a ValidationException; the call returned normally.');
    }
}

if (! function_exists('dbaRoomIn')) {
    function dbaRoomIn(Branch $branch): ClinicRoom
    {
        return ClinicRoom::factory()->create([
            'branch_id' => (int) $branch->id,
            'status' => ClinicRoom::STATUS_ACTIVE,
        ]);
    }
}

beforeEach(function (): void {
    // Arms both flags AND the observable session store. Without the third half
    // every controller action 404s and the resolver reports not-applicable, so a
    // suite that armed only the flags would pass while asserting on an engine
    // that never ran.
    daArmDoctorAccess();
});

afterEach(function (): void {
    // A pinned clock leaking into a later test is the classic cross-test
    // contamination in this codebase; released unconditionally, pinned or not.
    freeTestClock();
});

/*
|--------------------------------------------------------------------------
| A. OWNER DECISION O7 — STRICT ACTOR-BASED MAKER-CHECKER (the eight tests)
|--------------------------------------------------------------------------
|
| MERGED_PLAN section W, verbatim in intent, applied to the COVER workflow —
| which is where section X found the invariant MISSING when the owner's decision
| exposed it. The lock path had the check; the cover path did not.
|
| THE RULE IS ACTOR BASED, NEVER ROLE BASED:
|
|     cover.requester_user_id MUST NEVER equal the deciding user id
|
| Nothing below encodes "Super Admin is the maker" or "Supervisor RME is the
| checker". Both tiers hold `manage_doctor_branch_locks` and
| `approve_doctor_branch_locks`, and either may be either party on any given row
| provided the two parties are two different accounts.
*/

it('lets a super admin file a cover and a supervisor rme approve it', function (): void {
    $scene = dbaScene();
    $maker = daSuperAdmin();
    $checker = daSupervisorRme();

    $this->actingAs($maker)
        ->post(route('rme.doctor-branch-covers.store'), [
            'doctor_id' => $scene['doctor']->id,
            'target_branch_id' => $scene['branches']['LDK2']->id,
            'reason' => 'Menutup jadwal dokter yang cuti di cabang Landak.',
        ] + dbaCoverInput(1, 5))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $cover = DoctorBranchCover::query()->firstOrFail();
    expect($cover->status)->toBe(DoctorBranchCover::STATUS_PENDING)
        ->and((int) $cover->requester_user_id)->toBe((int) $maker->id);

    $this->actingAs($checker)
        ->post(route('rme.doctor-branch-covers.approve', $cover))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('rme.doctor-branch-locks.index'));

    expect($cover->refresh()->status)->toBe(DoctorBranchCover::STATUS_APPROVED)
        ->and((int) $cover->decided_by_user_id)->toBe((int) $checker->id)
        ->and($cover->decided_at)->not->toBeNull();
});

it('lets a supervisor rme file a cover and a super admin approve it', function (): void {
    // The mirror image of the case above, and the reason it is a separate test:
    // if either direction only worked one way round, the workflow would be
    // coupled to how the estate happens to be staffed today.
    $scene = dbaScene();
    $maker = daSupervisorRme();
    $checker = daSuperAdmin();

    $this->actingAs($maker)
        ->post(route('rme.doctor-branch-covers.store'), [
            'doctor_id' => $scene['doctor']->id,
            'target_branch_id' => $scene['branches']['LDK2']->id,
            'reason' => 'Menutup jadwal dokter yang cuti di cabang Landak.',
        ] + dbaCoverInput(1, 5))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $cover = DoctorBranchCover::query()->firstOrFail();
    expect((int) $cover->requester_user_id)->toBe((int) $maker->id);

    $this->actingAs($checker)
        ->post(route('rme.doctor-branch-covers.approve', $cover))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('rme.doctor-branch-locks.index'));

    expect($cover->refresh()->status)->toBe(DoctorBranchCover::STATUS_APPROVED)
        ->and((int) $cover->decided_by_user_id)->toBe((int) $checker->id);
});

it('refuses the same super admin approving the cover that super admin filed', function (): void {
    // OWNER TEST 3, and the sharpest of the eight: this actor passes every
    // permission check in the system through Gate::before, so the refusal here
    // can only come from the service comparing two stored user ids.
    $scene = dbaScene();
    $superAdmin = daSuperAdmin();

    $this->actingAs($superAdmin)
        ->post(route('rme.doctor-branch-covers.store'), [
            'doctor_id' => $scene['doctor']->id,
            'target_branch_id' => $scene['branches']['LDK2']->id,
            'reason' => 'Menutup jadwal dokter yang cuti di cabang Landak.',
        ] + dbaCoverInput(1, 5))
        ->assertSessionHasNoErrors();

    $cover = DoctorBranchCover::query()->firstOrFail();

    // Through the full stack: the route permission passes, the policy is
    // bypassed, and the service still refuses — so the request comes back with
    // an error rather than a 403 or a success.
    $this->actingAs($superAdmin)
        ->post(route('rme.doctor-branch-covers.approve', $cover))
        ->assertSessionHasErrors('cover');

    expect($cover->refresh()->status)->toBe(DoctorBranchCover::STATUS_PENDING)
        ->and($cover->decided_by_user_id)->toBeNull();

    // And directly at the service, so the refusal is pinned to the layer that
    // owns it rather than to an HTTP redirect shape.
    $exception = dbaCatchValidation(fn () => app(DoctorBranchCoverApprovalService::class)
        ->approve((int) $cover->id, $superAdmin));

    expect(array_keys($exception->errors()))->toContain('cover')
        ->and($exception->getMessage())->toContain('Anda tidak dapat memutuskan pengajuan cover cabang yang Anda ajukan sendiri');
});

it('refuses the same supervisor rme approving the cover that supervisor filed', function (): void {
    // OWNER TEST 4. This actor has NO Gate::before bypass, so the policy refuses
    // first and the route answers 403 — but the service refusal is asserted too,
    // because the policy is not the boundary for every actor and the invariant
    // must not depend on which one arrived.
    $scene = dbaScene();
    $supervisor = daSupervisorRme();

    $this->actingAs($supervisor)
        ->post(route('rme.doctor-branch-covers.store'), [
            'doctor_id' => $scene['doctor']->id,
            'target_branch_id' => $scene['branches']['LDK2']->id,
            'reason' => 'Menutup jadwal dokter yang cuti di cabang Landak.',
        ] + dbaCoverInput(1, 5))
        ->assertSessionHasNoErrors();

    $cover = DoctorBranchCover::query()->firstOrFail();

    $this->actingAs($supervisor)
        ->post(route('rme.doctor-branch-covers.approve', $cover))
        ->assertForbidden();

    expect($cover->refresh()->status)->toBe(DoctorBranchCover::STATUS_PENDING);

    $exception = dbaCatchValidation(fn () => app(DoctorBranchCoverApprovalService::class)
        ->approve((int) $cover->id, $supervisor));

    expect(array_keys($exception->errors()))->toContain('cover');
    expect($cover->refresh()->status)->toBe(DoctorBranchCover::STATUS_PENDING);
});

it('refuses an unauthorised role filing a management cover', function (): void {
    // OWNER TEST 5. "Unauthorised" means a real seeded role holding none of the
    // four DoctorAccess permissions — a role-less user would pass this test for
    // the wrong reason.
    $scene = dbaScene();

    $this->actingAs(daUnauthorisedActor())
        ->post(route('rme.doctor-branch-covers.store'), [
            'doctor_id' => $scene['doctor']->id,
            'target_branch_id' => $scene['branches']['LDK2']->id,
            'reason' => 'Menutup jadwal dokter yang cuti di cabang Landak.',
        ] + dbaCoverInput(1, 5))
        ->assertForbidden();

    expect(DoctorBranchCover::query()->count())->toBe(0);
});

it('refuses an unauthorised role approving a cover', function (): void {
    // OWNER TEST 6.
    $scene = dbaScene();
    $cover = dbaPendingCover(
        $scene['doctor'],
        $scene['branches']['LDK2'],
        now()->addHour(),
        now()->addHours(5),
        daSuperAdmin(),
        $scene['branches']['TLK1'],
    );

    $this->actingAs(daUnauthorisedActor())
        ->post(route('rme.doctor-branch-covers.approve', $cover))
        ->assertForbidden();

    expect($cover->refresh()->status)->toBe(DoctorBranchCover::STATUS_PENDING);
});

/*
| OWNER TEST 7 — "a requester who GAINS or CHANGES role later still cannot
| approve their own request" — is split into the two cases below rather than
| written as one, because either journey alone could pass by accident:
|
|   (a) a Supervisor RME maker who is later ALSO made a Super Admin, i.e. who
|       ACQUIRES the global Gate::before bypass after filing. If the refusal
|       were role-derived, this is the journey that would start succeeding the
|       moment the bypass arrived.
|   (b) a maker whose original role is REMOVED and replaced, so the role used to
|       file no longer exists on the account at decision time.
|
| The check is satisfied structurally only if it compares the STORED
| requester_user_id and re-derives nothing from current roles — so both cases
| assert the refusal, and case (a) additionally asserts that the bypass really
| did arrive.
*/

it('refuses a maker who acquires the super admin bypass after filing', function (): void {
    // OWNER TEST 7, journey (a).
    $scene = dbaScene();
    $maker = daSupervisorRme();
    $covers = app(DoctorBranchCoverApprovalService::class);
    $period = dbaCoverInput(1, 5);

    $cover = $covers->request(
        $maker,
        (int) $scene['doctor']->id,
        (int) $scene['branches']['LDK2']->id,
        $period['starts_at'],
        $period['ends_at'],
        'Menutup jadwal dokter yang cuti di cabang Landak.',
    );

    // The role change happens AFTER filing, which is the whole point.
    $maker->assignRole('Super Admin');
    $maker->refresh();
    $maker->unsetRelation('roles')->unsetRelation('permissions');

    // The bypass really did arrive: the policy now says yes.
    expect(Gate::forUser($maker)->allows('decide', $cover))->toBeTrue();

    // The service still says no, because it compares stored ids.
    $exception = dbaCatchValidation(fn () => $covers->approve((int) $cover->id, $maker));

    expect(array_keys($exception->errors()))->toContain('cover')
        ->and($cover->refresh()->status)->toBe(DoctorBranchCover::STATUS_PENDING);
});

it('refuses a maker whose original role was removed and replaced', function (): void {
    // OWNER TEST 7, journey (b): the stored requester id outlives the role that
    // was used to file, so re-deriving authority from current roles could never
    // reconstruct who the maker was.
    $scene = dbaScene();
    $maker = daSupervisorRme();
    $covers = app(DoctorBranchCoverApprovalService::class);
    $period = dbaCoverInput(1, 5);

    $cover = $covers->request(
        $maker,
        (int) $scene['doctor']->id,
        (int) $scene['branches']['LDK2']->id,
        $period['starts_at'],
        $period['ends_at'],
        'Menutup jadwal dokter yang cuti di cabang Landak.',
    );

    $maker->removeRole('Supervisor RME');
    $maker->assignRole('Super Admin');
    $maker->refresh();
    $maker->unsetRelation('roles')->unsetRelation('permissions');

    $exception = dbaCatchValidation(fn () => $covers->approve((int) $cover->id, $maker));

    expect(array_keys($exception->errors()))->toContain('cover')
        ->and($cover->refresh()->status)->toBe(DoctorBranchCover::STATUS_PENDING);
});

it('proves gate before does not bypass the maker checker invariant', function (): void {
    // OWNER TEST 8, asserted rather than argued, in four parts:
    //
    //   1. For a SUPERVISOR RME self-requester the POLICY refuses — so the
    //      policy clause is real and not dead code.
    //   2. For a SUPER ADMIN self-requester the SAME policy ability returns
    //      TRUE. That is the bypass, demonstrated on the exact ability the
    //      route uses, not inferred from a provider comment.
    //   3. The service refuses that Super Admin anyway.
    //   4. Neither policy declares a `before()` hook, which would shadow the
    //      single global bypass and change this analysis silently.
    $scene = dbaScene();
    $supervisor = daSupervisorRme();
    $superAdmin = daSuperAdmin();
    $covers = app(DoctorBranchCoverApprovalService::class);
    $period = dbaCoverInput(1, 5);

    $supervisorCover = $covers->request(
        $supervisor,
        (int) $scene['doctor']->id,
        (int) $scene['branches']['LDK2']->id,
        $period['starts_at'],
        $period['ends_at'],
        'Menutup jadwal dokter yang cuti di cabang Landak.',
    );

    // 1 — the policy is a real gate for an actor with no bypass.
    expect(Gate::forUser($supervisor)->allows('decide', $supervisorCover))->toBeFalse();

    // Housekeeping, not the assertion: the pending cover is cleared so a second
    // one can be filed past `trx_dbc_pending_uq`. A rejection requires a reason,
    // so one is given.
    $covers->reject((int) $supervisorCover->id, $superAdmin, 'Diajukan ulang oleh Super Admin untuk bagian dua.');

    $superAdminCover = $covers->request(
        $superAdmin,
        (int) $scene['doctor']->id,
        (int) $scene['branches']['LDK2']->id,
        $period['starts_at'],
        $period['ends_at'],
        'Menutup jadwal dokter yang cuti di cabang Landak.',
    );

    // 2 — and it is bypassed entirely for a Super Admin.
    expect(Gate::forUser($superAdmin)->allows('decide', $superAdminCover))->toBeTrue();

    // 3 — the service is therefore the only thing standing between a Super
    //     Admin and self-approval, and it holds.
    $exception = dbaCatchValidation(fn () => $covers->approve((int) $superAdminCover->id, $superAdmin));

    expect(array_keys($exception->errors()))->toContain('cover')
        ->and($superAdminCover->refresh()->status)->toBe(DoctorBranchCover::STATUS_PENDING);

    // 4 — a policy `before()` would return true for a Super Admin before
    //     `decide()` ran, which is exactly the shape this sprint must not adopt.
    expect(method_exists(DoctorBranchCoverPolicy::class, 'before'))->toBeFalse()
        ->and(method_exists(DoctorBranchLockRequestPolicy::class, 'before'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| B. INITIAL ASSIGNMENT — UNSET is the compatibility state, not missing data
|--------------------------------------------------------------------------
*/

it('sets the home branch when an initial assignment is approved', function (): void {
    $scene = dbaScene(homeCode: null);
    $maker = daSuperAdmin();
    $checker = daSupervisorRme();
    $locks = app(DoctorBranchLockApprovalService::class);

    expect(DoctorBranchLock::query()->count())->toBe(0);

    $request = $locks->request(
        $maker,
        (int) $scene['doctor']->id,
        (int) $scene['branches']['TLK1']->id,
        'Penetapan cabang tetap awal untuk dokter ini.',
    );

    expect($request->status)->toBe(DoctorBranchLockRequest::STATUS_PENDING)
        ->and($request->request_type)->toBe(DoctorBranchLockRequest::TYPE_INITIAL_ASSIGNMENT)
        ->and($request->source_branch_id)->toBeNull();

    $approved = $locks->approve((int) $request->id, $checker);

    $lock = DoctorBranchLock::query()->where('doctor_id', $scene['doctor']->id)->firstOrFail();

    expect($approved->status)->toBe(DoctorBranchLockRequest::STATUS_APPROVED)
        ->and($approved->applied_at)->not->toBeNull()
        ->and((int) $lock->home_branch_id)->toBe((int) $scene['branches']['TLK1']->id)
        ->and($lock->established_via)->toBe(DoctorBranchLock::VIA_INITIAL_ASSIGNMENT)
        ->and((int) $lock->established_by_user_id)->toBe((int) $checker->id);
});

it('lets a doctor file their own branch lock request with no management permission', function (): void {
    // `manage_doctor_branch_locks` is filing ON ANOTHER DOCTOR'S BEHALF. A doctor
    // asking for their own home branch needs no permission at all — the boundary
    // is DoctorBranchLockRequestPolicy::create() against mst_doctors.user_id —
    // and the route carries no permission middleware for exactly this reason.
    $scene = dbaScene(homeCode: null);

    expect($scene['user']->can('manage_doctor_branch_locks'))->toBeFalse()
        ->and($scene['user']->can('approve_doctor_branch_locks'))->toBeFalse();

    $this->actingAs($scene['user'])
        ->post(route('rme.doctor-branch-locks.store'), [
            'destination_branch_id' => $scene['branches']['TLK1']->id,
            'reason' => 'Saya berpraktik tetap di cabang Telkomas.',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('rme.doctor-branch-locks.create'));

    $request = DoctorBranchLockRequest::query()->firstOrFail();

    expect((int) $request->doctor_id)->toBe((int) $scene['doctor']->id)
        ->and((int) $request->requester_user_id)->toBe((int) $scene['user']->id)
        ->and($request->status)->toBe(DoctorBranchLockRequest::STATUS_PENDING)
        // Filing is not granting: no lock row may exist yet.
        ->and(DoctorBranchLock::query()->count())->toBe(0);
});

it('never lets a doctor approve their own branch lock request', function (): void {
    // TWO refusals, because they are two different clauses and only one of them
    // is about the requester:
    //
    //   (a) a Doctor-role account is refused as the SUBJECT even though somebody
    //       else filed — `assertNotTheSubject()`, which needs the locked doctor
    //       row and therefore cannot live beside the requester comparison;
    //   (b) the same is true when that doctor's account ALSO holds Super Admin,
    //       which is the trap: every permission check passes and only the
    //       in-transaction subject comparison refuses.
    $scene = dbaScene(homeCode: null);
    $locks = app(DoctorBranchLockApprovalService::class);

    $request = $locks->request(
        daSuperAdmin(),
        (int) $scene['doctor']->id,
        (int) $scene['branches']['TLK1']->id,
        'Penetapan cabang tetap awal untuk dokter ini.',
    );

    // (a)
    $exception = dbaCatchValidation(fn () => $locks->approve((int) $request->id, $scene['user']));
    expect(array_keys($exception->errors()))->toContain('request')
        ->and($exception->getMessage())->toContain('akun dokter Anda sendiri');

    // (b)
    $scene['user']->assignRole('Super Admin');
    $scene['user']->refresh();
    $scene['user']->unsetRelation('roles')->unsetRelation('permissions');

    expect(Gate::forUser($scene['user'])->allows('decide', $request))->toBeTrue();

    $bypassAttempt = dbaCatchValidation(fn () => $locks->approve((int) $request->id, $scene['user']));

    expect(array_keys($bypassAttempt->errors()))->toContain('request')
        ->and($request->refresh()->status)->toBe(DoctorBranchLockRequest::STATUS_PENDING)
        ->and(DoctorBranchLock::query()->count())->toBe(0);
});

it('never lets a doctor file a cover for themself', function (): void {
    // Section Q: "A doctor must NEVER self-create or self-approve cover."
    // Proven at BOTH layers, because they refuse for different reasons: the
    // route because the doctor holds no `manage_doctor_branch_locks`, and the
    // service because the subject is identified by mst_doctors.user_id, which no
    // policy keyed on the actor can see.
    $scene = dbaScene();

    $this->actingAs($scene['user'])
        ->post(route('rme.doctor-branch-covers.store'), [
            'doctor_id' => $scene['doctor']->id,
            'target_branch_id' => $scene['branches']['LDK2']->id,
            'reason' => 'Saya ingin bertugas di cabang Landak sementara.',
        ] + dbaCoverInput(1, 5))
        ->assertForbidden();

    $period = dbaCoverInput(1, 5);

    $exception = dbaCatchValidation(fn () => app(DoctorBranchCoverApprovalService::class)->request(
        $scene['user'],
        (int) $scene['doctor']->id,
        (int) $scene['branches']['LDK2']->id,
        $period['starts_at'],
        $period['ends_at'],
        'Saya ingin bertugas di cabang Landak sementara.',
    ));

    expect(array_keys($exception->errors()))->toContain('doctor_id')
        ->and($exception->getMessage())->toContain('untuk diri sendiri')
        ->and(DoctorBranchCover::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| C. PERMANENT TRANSFER
|--------------------------------------------------------------------------
*/

it('approves a permanent transfer from one locked branch to another', function (): void {
    $scene = dbaScene();
    $locks = app(DoctorBranchLockApprovalService::class);

    $request = $locks->request(
        daSuperAdmin(),
        (int) $scene['doctor']->id,
        (int) $scene['branches']['LDK2']->id,
        'Dokter dipindahkan permanen ke cabang Landak.',
    );

    expect($request->request_type)->toBe(DoctorBranchLockRequest::TYPE_TRANSFER)
        ->and((int) $request->source_branch_id)->toBe((int) $scene['branches']['TLK1']->id);

    $locks->approve((int) $request->id, daSupervisorRme());

    $lock = DoctorBranchLock::query()->where('doctor_id', $scene['doctor']->id)->firstOrFail();

    expect((int) $lock->home_branch_id)->toBe((int) $scene['branches']['LDK2']->id)
        ->and($lock->established_via)->toBe(DoctorBranchLock::VIA_TRANSFER);
});

it('refuses a transfer whose source branch no longer matches the live lock', function (): void {
    // THE STALE-SOURCE GUARD. There is no clinical day to expire against here,
    // so this comparison is the workflow's entire freshness boundary: an
    // approval must never be applied against a starting point the approver never
    // saw.
    $scene = dbaScene();
    $locks = app(DoctorBranchLockApprovalService::class);

    $request = $locks->request(
        daSuperAdmin(),
        (int) $scene['doctor']->id,
        (int) $scene['branches']['LDK2']->id,
        'Dokter dipindahkan permanen ke cabang Landak.',
    );

    // The lock moves underneath the pending request.
    daGrantHomeLock($scene['doctor'], $scene['branches']['ATG3']);

    $exception = dbaCatchValidation(fn () => $locks->approve((int) $request->id, daSupervisorRme()));

    expect(array_keys($exception->errors()))->toContain('request')
        ->and($exception->getMessage())->toContain('kunci cabang dokter telah berubah')
        ->and($request->refresh()->status)->toBe(DoctorBranchLockRequest::STATUS_PENDING)
        ->and((int) DoctorBranchLock::query()->where('doctor_id', $scene['doctor']->id)->value('home_branch_id'))
        ->toBe((int) $scene['branches']['ATG3']->id);
});

it('refuses a transfer to the branch the doctor is already locked to', function (): void {
    $scene = dbaScene();

    $exception = dbaCatchValidation(fn () => app(DoctorBranchLockApprovalService::class)->request(
        daSuperAdmin(),
        (int) $scene['doctor']->id,
        (int) $scene['branches']['TLK1']->id,
        'Dokter dipindahkan permanen ke cabang Telkomas.',
    ));

    expect(array_keys($exception->errors()))->toContain('destination_branch_id')
        ->and(DoctorBranchLockRequest::query()->count())->toBe(0);
});

it('refuses a transfer to an inactive or non rme destination at both filing and approval', function (): void {
    // Re-checked at decision time on purpose: a branch that was eligible when
    // the request was filed may not be when it is approved, and an approval must
    // never resurrect a deactivated branch.
    $scene = dbaScene();
    $locks = app(DoctorBranchLockApprovalService::class);
    $maker = daSuperAdmin();

    // Filing: an inactive branch is refused outright.
    $inactive = daBranch('SPN4', ['is_active' => false]);
    $inactiveAttempt = dbaCatchValidation(fn () => $locks->request(
        $maker,
        (int) $scene['doctor']->id,
        (int) $inactive->id,
        'Dokter dipindahkan permanen ke cabang Sunu.',
    ));
    expect(array_keys($inactiveAttempt->errors()))->toContain('destination_branch_id');

    // Filing: an active branch with RME disabled is refused too — MAIN is the
    // production shape of this case.
    $nonRme = daBranch('MAIN', ['is_rme_enabled' => false]);
    $nonRmeAttempt = dbaCatchValidation(fn () => $locks->request(
        $maker,
        (int) $scene['doctor']->id,
        (int) $nonRme->id,
        'Dokter dipindahkan permanen ke cabang utama.',
    ));
    expect(array_keys($nonRmeAttempt->errors()))->toContain('destination_branch_id');

    // Approval: eligible when filed, deactivated before the decision.
    $request = $locks->request(
        $maker,
        (int) $scene['doctor']->id,
        (int) $scene['branches']['LDK2']->id,
        'Dokter dipindahkan permanen ke cabang Landak.',
    );

    $scene['branches']['LDK2']->forceFill(['is_active' => false])->save();

    $atDecision = dbaCatchValidation(fn () => $locks->approve((int) $request->id, daSupervisorRme()));

    expect(array_keys($atDecision->errors()))->toContain('destination_branch_id')
        ->and($request->refresh()->status)->toBe(DoctorBranchLockRequest::STATUS_PENDING)
        ->and((int) DoctorBranchLock::query()->where('doctor_id', $scene['doctor']->id)->value('home_branch_id'))
        ->toBe((int) $scene['branches']['TLK1']->id);
});

it('refuses a second pending branch lock request for the same doctor', function (): void {
    // `trx_doctor_branch_lock_req_pending_uq` is the real enforcement —
    // one PENDING row per doctor, including the double-submit an
    // application-level check would race straight through. The contract asserted
    // here is that the refusal escapes as a ValidationException, never a raw
    // QueryException, and that the connection survives it: the count query after
    // the refusal is the actual proof, because that is the statement PostgreSQL
    // kills with 25P02 if the unique violation were caught inside the
    // transaction instead of outside a savepoint.
    $scene = dbaScene();
    $locks = app(DoctorBranchLockApprovalService::class);
    $maker = daSuperAdmin();

    $locks->request(
        $maker,
        (int) $scene['doctor']->id,
        (int) $scene['branches']['LDK2']->id,
        'Dokter dipindahkan permanen ke cabang Landak.',
    );

    $exception = dbaCatchValidation(fn () => $locks->request(
        $maker,
        (int) $scene['doctor']->id,
        (int) $scene['branches']['ATG3']->id,
        'Dokter dipindahkan permanen ke cabang Antang.',
    ));

    expect(array_keys($exception->errors()))->toContain('doctor_id')
        ->and(DoctorBranchLockRequest::query()->count())->toBe(1);
});

it('blocks a permanent transfer approval while a cover is active', function (): void {
    // Section Q: a permanent transfer must not create an ambiguous effective
    // branch. While a cover is IN FORCE there would be two defensible answers to
    // "which branch is this doctor working from", so the decision is refused and
    // the message names the escape hatch.
    $scene = dbaScene();
    $locks = app(DoctorBranchLockApprovalService::class);

    daApprovedCover(
        $scene['doctor'],
        $scene['branches']['ATG3'],
        now()->subHour(),
        now()->addHours(3),
    );

    $request = $locks->request(
        daSuperAdmin(),
        (int) $scene['doctor']->id,
        (int) $scene['branches']['LDK2']->id,
        'Dokter dipindahkan permanen ke cabang Landak.',
    );

    $exception = dbaCatchValidation(fn () => $locks->approve((int) $request->id, daSupervisorRme()));

    expect(array_keys($exception->errors()))->toContain('request')
        ->and($exception->getMessage())->toContain('cover sementara')
        ->and($request->refresh()->status)->toBe(DoctorBranchLockRequest::STATUS_PENDING)
        ->and((int) DoctorBranchLock::query()->where('doctor_id', $scene['doctor']->id)->value('home_branch_id'))
        ->toBe((int) $scene['branches']['TLK1']->id);
});

it('allows the permanent transfer once the cover has ended, with the clock alone', function (): void {
    // The second half of section Q's "until it ends or is cancelled", and a
    // lazy-resolution proof in its own right: NOTHING is run between the refusal
    // and the success except a clock move. No command, no scheduler, no queue.
    Queue::fake();

    $scene = dbaScene();
    $locks = app(DoctorBranchLockApprovalService::class);

    daApprovedCover(
        $scene['doctor'],
        $scene['branches']['ATG3'],
        now()->subHour(),
        now()->addHours(3),
    );

    $request = $locks->request(
        daSuperAdmin(),
        (int) $scene['doctor']->id,
        (int) $scene['branches']['LDK2']->id,
        'Dokter dipindahkan permanen ke cabang Landak.',
    );

    $checker = daSupervisorRme();
    dbaCatchValidation(fn () => $locks->approve((int) $request->id, $checker));

    pinTestClock(now()->addHours(4)->toDateTimeString());

    $locks->approve((int) $request->id, $checker);

    expect((int) DoctorBranchLock::query()->where('doctor_id', $scene['doctor']->id)->value('home_branch_id'))
        ->toBe((int) $scene['branches']['LDK2']->id);

    Queue::assertNothingPushed();
});

it('allows the permanent transfer once the cover is cancelled', function (): void {
    $scene = dbaScene();
    $locks = app(DoctorBranchLockApprovalService::class);
    $covers = app(DoctorBranchCoverApprovalService::class);
    $approver = daSupervisorRme();

    $cover = daApprovedCover(
        $scene['doctor'],
        $scene['branches']['ATG3'],
        now()->subHour(),
        now()->addHours(3),
    );

    $request = $locks->request(
        daSuperAdmin(),
        (int) $scene['doctor']->id,
        (int) $scene['branches']['LDK2']->id,
        'Dokter dipindahkan permanen ke cabang Landak.',
    );

    dbaCatchValidation(fn () => $locks->approve((int) $request->id, $approver));

    $covers->cancel(
        (int) $cover->id,
        $approver,
        'Cover dibatalkan karena dokter dipindahkan permanen.',
        isApprover: true,
    );

    $locks->approve((int) $request->id, $approver);

    expect($cover->refresh()->status)->toBe(DoctorBranchCover::STATUS_CANCELLED)
        ->and($cover->cancelled_at)->not->toBeNull()
        ->and((int) DoctorBranchLock::query()->where('doctor_id', $scene['doctor']->id)->value('home_branch_id'))
        ->toBe((int) $scene['branches']['LDK2']->id);
});

/*
|--------------------------------------------------------------------------
| D. AN APPROVAL ENDS A LOGIN SESSION AND NOTHING ELSE (ruling P17)
|--------------------------------------------------------------------------
|
| Requirement 4 and owner decision O1: an approved change invalidates the
| doctor's active session and forces a fresh login. Section Q adds, for cover:
| "MUST NOT revoke device, DoctorDeviceAuthorization or WebAuthn credential."
|
| All three survivors are asserted, every time. A test that only checked the
| authorization would miss the credential, which binds to the DEVICE and has no
| doctor column at all.
*/

it('releases the doctor lease on transfer approval without revoking device authorization or credential', function (): void {
    $scene = dbaScene();
    $trail = dbaDeviceTrail($scene['doctor']);

    // A REAL login, because actingAs() fires Authenticated and never Login, so
    // it would leave zero lease rows and this test would assert on nothing.
    daLoginPost($scene['user']);
    $lease = daCurrentLease($scene['user']);
    expect($lease)->not->toBeNull();
    daAssertActiveLeaseCount(1, $scene['user']);

    $locks = app(DoctorBranchLockApprovalService::class);
    $request = $locks->request(
        daSuperAdmin(),
        (int) $scene['doctor']->id,
        (int) $scene['branches']['LDK2']->id,
        'Dokter dipindahkan permanen ke cabang Landak.',
    );
    $locks->approve((int) $request->id, daSupervisorRme());

    // The lease is RELEASED, not deleted: the released row is the audit trail,
    // which is why the partial unique index only constrains released_at IS NULL.
    daAssertActiveLeaseCount(0, $scene['user']);
    expect($lease->refresh()->released_at)->not->toBeNull()
        ->and($lease->released_reason)->toBe(DoctorSessionLease::RELEASE_BRANCH_TRANSFER_APPROVED);

    dbaAssertDeviceTrailIntact($trail);
});

it('releases the doctor lease on cover approval without revoking device authorization or credential', function (): void {
    $scene = dbaScene();
    $trail = dbaDeviceTrail($scene['doctor']);

    daLoginPost($scene['user']);
    $lease = daCurrentLease($scene['user']);
    expect($lease)->not->toBeNull();

    $covers = app(DoctorBranchCoverApprovalService::class);
    $period = dbaCoverInput(1, 5);
    $cover = $covers->request(
        daSuperAdmin(),
        (int) $scene['doctor']->id,
        (int) $scene['branches']['LDK2']->id,
        $period['starts_at'],
        $period['ends_at'],
        'Menutup jadwal dokter yang cuti di cabang Landak.',
    );

    // NOTE the period: it starts in an HOUR, so the effective branch has not
    // changed yet — and the session is released anyway. Section Q's rule is
    // unconditional; releasing only when the effective branch changes right now
    // would turn "approval invalidates the session" into a case analysis, and
    // the case that gets it wrong is the doctor mid-shift when a cover starting
    // later is approved.
    $covers->approve((int) $cover->id, daSupervisorRme());

    daAssertActiveLeaseCount(0, $scene['user']);
    expect($lease->refresh()->released_at)->not->toBeNull()
        ->and($lease->released_reason)->toBe(DoctorSessionLease::RELEASE_EFFECTIVE_BRANCH_CHANGED);

    dbaAssertDeviceTrailIntact($trail);
});

/*
|--------------------------------------------------------------------------
| E. COVER: ACTIVATION, EXPIRY, AND WHAT EXPIRY MUST NOT DO
|--------------------------------------------------------------------------
|
| The branch a doctor may go online at is asserted through the REAL chokepoint,
| `UserOnlineContextService::startDoctorSession()`, which is where a crafted POST
| arrives. A disabled <select> is not a security boundary; this is.
*/

it('lands a fresh online session on the cover branch while the cover is active', function (): void {
    $scene = dbaScene(['TLK1', 'LDK2']);
    $onlineContexts = app(UserOnlineContextService::class);

    daApprovedCover(
        $scene['doctor'],
        $scene['branches']['LDK2'],
        now()->subHour(),
        now()->addHours(3),
    );

    // Home is refused WHILE the cover is in force — the cover REPLACES the
    // effective branch, it does not add to it.
    $refusal = dbaCatchValidation(fn () => $onlineContexts->startDoctorSession(
        $scene['user'],
        (int) $scene['branches']['TLK1']->id,
        (int) dbaRoomIn($scene['branches']['TLK1'])->id,
    ));
    expect(array_keys($refusal->errors()))->toContain('branch_id');

    $context = $onlineContexts->startDoctorSession(
        $scene['user'],
        (int) $scene['branches']['LDK2']->id,
        (int) dbaRoomIn($scene['branches']['LDK2'])->id,
    );

    expect((int) $context->branch_id)->toBe((int) $scene['branches']['LDK2']->id);
});

it('lands a fresh online session back on home after the cover expires, with no job run', function (): void {
    // THE MECHANISM SECTION Q SPECIFIES: authority is derivable from current
    // timestamps on every request, so an expired cover can never remain
    // effective because cron or a queue worker was delayed.
    //
    // This test moves the clock and NOTHING ELSE. No Artisan call, no command,
    // no scheduler; Queue::assertNothingPushed() states that in an assertion
    // rather than in a comment.
    Queue::fake();

    $scene = dbaScene(['TLK1', 'LDK2']);
    $onlineContexts = app(UserOnlineContextService::class);

    daApprovedCover(
        $scene['doctor'],
        $scene['branches']['LDK2'],
        now()->subHours(3),
        now()->addHour(),
    );

    pinTestClock(now()->addHours(2)->toDateTimeString());

    // The cover branch is now refused...
    $refusal = dbaCatchValidation(fn () => $onlineContexts->startDoctorSession(
        $scene['user'],
        (int) $scene['branches']['LDK2']->id,
        (int) dbaRoomIn($scene['branches']['LDK2'])->id,
    ));
    expect(array_keys($refusal->errors()))->toContain('branch_id');

    // ...and home is where a fresh session lands.
    $context = $onlineContexts->startDoctorSession(
        $scene['user'],
        (int) $scene['branches']['TLK1']->id,
        (int) dbaRoomIn($scene['branches']['TLK1'])->id,
    );

    expect((int) $context->branch_id)->toBe((int) $scene['branches']['TLK1']->id);

    Queue::assertNothingPushed();
});

it('leaves the home lock and the whole device trail untouched when a cover expires', function (): void {
    // Section Q, stated negatively: "Cover expiry MUST NOT change
    // HOME_LOCKED_BRANCH, and MUST NOT revoke device, DoctorDeviceAuthorization
    // or WebAuthn credential."
    $scene = dbaScene(['TLK1', 'LDK2']);
    $trail = dbaDeviceTrail($scene['doctor']);

    $cover = daApprovedCover(
        $scene['doctor'],
        $scene['branches']['LDK2'],
        now()->subHours(3),
        now()->addHour(),
    );

    $homeBefore = (int) DoctorBranchLock::query()
        ->where('doctor_id', $scene['doctor']->id)
        ->value('home_branch_id');

    pinTestClock(now()->addHours(2)->toDateTimeString());

    // THE ONE AUTHORITY, asked directly: past ends_at the effective branch is
    // home again. Asserted through the resolver rather than through a
    // presence read, because presence would have answered the same either way
    // and would have proven nothing about expiry.
    expect(app(DoctorEffectiveBranchResolver::class)->branchIdFor($scene['user']))
        ->toBe((int) $scene['branches']['TLK1']->id);

    expect((int) DoctorBranchLock::query()->where('doctor_id', $scene['doctor']->id)->value('home_branch_id'))
        ->toBe($homeBefore)
        ->and($homeBefore)->toBe((int) $scene['branches']['TLK1']->id);

    // EXPIRED is DERIVED, never persisted: the stored status is still `approved`
    // after ends_at. Section Q forbids redundant state a delayed job would have
    // to maintain, and this is the assertion that would fail if somebody added
    // an `expired` write.
    expect($cover->refresh()->status)->toBe(DoctorBranchCover::STATUS_APPROVED)
        ->and($cover->cancelled_at)->toBeNull();

    dbaAssertDeviceTrailIntact($trail);
});

it('refuses an overlapping cover when it is filed', function (): void {
    $scene = dbaScene();
    $covers = app(DoctorBranchCoverApprovalService::class);
    $clock = app(ClinicalClock::class);

    daApprovedCover(
        $scene['doctor'],
        $scene['branches']['LDK2'],
        now()->subHour(),
        now()->addHours(6),
    );

    $exception = dbaCatchValidation(fn () => $covers->request(
        daSuperAdmin(),
        (int) $scene['doctor']->id,
        (int) $scene['branches']['ATG3']->id,
        $clock->now()->addHours(2)->format('Y-m-d H:i:s'),
        $clock->now()->addHours(9)->format('Y-m-d H:i:s'),
        'Cover tumpang tindih yang harus ditolak.',
    ));

    expect(array_keys($exception->errors()))->toContain('starts_at')
        ->and($exception->getMessage())->toContain('bertabrakan')
        // The advisory refusal must leave no row behind.
        ->and(DoctorBranchCover::query()->where('status', DoctorBranchCover::STATUS_PENDING)->count())->toBe(0);
});

it('refuses an overlapping cover at approval time even when the advisory check was bypassed', function (): void {
    // THIS is the check the non-overlap invariant actually rests on. The
    // advisory pre-check in request() cannot be the boundary — two requests
    // filed a millisecond apart both read clean — and no index on either engine
    // can express overlap, because a unique index compares VALUES while overlap
    // is a RANGE predicate. That was proven by attempting the write: identical
    // periods were rejected, merely overlapping ones were ACCEPTED.
    //
    // So the pending row is written directly, past the advisory check, and the
    // refusal asserted here is the one taken under the doctor and home-lock row
    // locks inside approve().
    $scene = dbaScene();

    daApprovedCover(
        $scene['doctor'],
        $scene['branches']['LDK2'],
        now()->subHour(),
        now()->addHours(6),
    );

    $pending = dbaPendingCover(
        $scene['doctor'],
        $scene['branches']['ATG3'],
        now()->addHours(2),
        now()->addHours(9),
        daSuperAdmin(),
        $scene['branches']['TLK1'],
    );

    $exception = dbaCatchValidation(fn () => app(DoctorBranchCoverApprovalService::class)
        ->approve((int) $pending->id, daSupervisorRme()));

    expect(array_keys($exception->errors()))->toContain('starts_at')
        ->and($pending->refresh()->status)->toBe(DoctorBranchCover::STATUS_PENDING)
        ->and(DoctorBranchCover::query()->where('status', DoctorBranchCover::STATUS_APPROVED)->count())->toBe(1);
});

it('refuses a cover for a doctor with no home lock, at both filing and approval', function (): void {
    // An UNSET doctor has no `mst_doctor_branch_locks` row, so there is nothing
    // to lock, nothing to serialise overlap on, and nothing to revert to when
    // the cover ends. Refusing is not a missing-data repair: inventing a
    // placeholder row would write a branch nobody approved.
    $scene = dbaScene(['TLK1', 'LDK2'], homeCode: null);
    $covers = app(DoctorBranchCoverApprovalService::class);
    $period = dbaCoverInput(1, 5);

    $filing = dbaCatchValidation(fn () => $covers->request(
        daSuperAdmin(),
        (int) $scene['doctor']->id,
        (int) $scene['branches']['LDK2']->id,
        $period['starts_at'],
        $period['ends_at'],
        'Cover untuk dokter tanpa cabang tetap.',
    ));

    expect(array_keys($filing->errors()))->toContain('doctor_id')
        ->and($filing->getMessage())->toContain('belum memiliki cabang tetap');

    // And at the decision, for a row that reached the queue another way.
    $pending = dbaPendingCover(
        $scene['doctor'],
        $scene['branches']['LDK2'],
        now()->addHour(),
        now()->addHours(5),
        daSuperAdmin(),
        $scene['branches']['TLK1'],
    );

    $decision = dbaCatchValidation(fn () => $covers->approve((int) $pending->id, daSupervisorRme()));

    expect(array_keys($decision->errors()))->toContain('doctor_id')
        ->and($pending->refresh()->status)->toBe(DoctorBranchCover::STATUS_PENDING);
});

it('refuses a cover targeting an inactive or non rme branch', function (): void {
    $scene = dbaScene();
    $covers = app(DoctorBranchCoverApprovalService::class);
    $maker = daSuperAdmin();
    $period = dbaCoverInput(1, 5);

    $inactive = daBranch('SPN4', ['is_active' => false]);
    $inactiveAttempt = dbaCatchValidation(fn () => $covers->request(
        $maker,
        (int) $scene['doctor']->id,
        (int) $inactive->id,
        $period['starts_at'],
        $period['ends_at'],
        'Cover ke cabang yang sudah tidak aktif.',
    ));
    expect(array_keys($inactiveAttempt->errors()))->toContain('target_branch_id');

    $nonRme = daBranch('MAIN', ['is_rme_enabled' => false]);
    $nonRmeAttempt = dbaCatchValidation(fn () => $covers->request(
        $maker,
        (int) $scene['doctor']->id,
        (int) $nonRme->id,
        $period['starts_at'],
        $period['ends_at'],
        'Cover ke cabang non RME.',
    ));
    expect(array_keys($nonRmeAttempt->errors()))->toContain('target_branch_id');

    // Re-checked at decision time: eligible when filed, deactivated before the
    // approval.
    $cover = $covers->request(
        $maker,
        (int) $scene['doctor']->id,
        (int) $scene['branches']['LDK2']->id,
        $period['starts_at'],
        $period['ends_at'],
        'Menutup jadwal dokter yang cuti di cabang Landak.',
    );

    $scene['branches']['LDK2']->forceFill(['is_rme_enabled' => false])->save();

    $atDecision = dbaCatchValidation(fn () => $covers->approve((int) $cover->id, daSupervisorRme()));

    expect(array_keys($atDecision->errors()))->toContain('target_branch_id')
        ->and($cover->refresh()->status)->toBe(DoctorBranchCover::STATUS_PENDING);
});

/*
|--------------------------------------------------------------------------
| F. THE PRACTICE-PIVOT GUARD (open item V1) — a lock must narrow, never strand
|--------------------------------------------------------------------------
|
| `startDoctorSession()` refuses a branch outside `mst_doctor_branches` BEFORE the
| locked-branch assert is ever reached. So an approval naming a branch the doctor
| does not practise at does not narrow them — it STOPS them: the approved branch
| is outside their pivot, and every branch inside their pivot is outside the
| approval. They cannot go online ANYWHERE for the whole period.
|
| The fix belongs in the approval, never in the practice check: relaxing the
| practice check would turn an approval into a way to reach a branch the doctor
| was never entitled to work in, which is the widening this sprint refuses.
|
| Latent rather than live in production, where every doctor is pivoted to every
| RME branch. It becomes live the first time anyone narrows a pivot — which is
| why it is proven now rather than discovered then.
*/

it('refuses approving a cover whose target is outside the doctor practice branches', function (): void {
    $scene = dbaScene(['TLK1']);
    $outside = daBranch('LDK2');
    $covers = app(DoctorBranchCoverApprovalService::class);
    $period = dbaCoverInput(1, 5);

    // Filing succeeds — the pivot is not read there — so the refusal must come
    // from the decision, which is where the transaction and the locks are.
    $cover = $covers->request(
        daSuperAdmin(),
        (int) $scene['doctor']->id,
        (int) $outside->id,
        $period['starts_at'],
        $period['ends_at'],
        'Cover ke cabang di luar cabang praktik dokter.',
    );

    $exception = dbaCatchValidation(fn () => $covers->approve((int) $cover->id, daSupervisorRme()));

    expect(array_keys($exception->errors()))->toContain('target_branch_id')
        // The message must name the REMEDY, not just the refusal: an operator who
        // only reads "not allowed" has no way to know that Master Data Dokter is
        // where this is fixed.
        ->and($exception->getMessage())->toContain('Cabang Praktik')
        ->and($cover->refresh()->status)->toBe(DoctorBranchCover::STATUS_PENDING);

    // AND the doctor is still able to go online at home, which is the whole
    // point of refusing: the guard exists so nobody is stranded.
    $context = app(UserOnlineContextService::class)->startDoctorSession(
        $scene['user'],
        (int) $scene['branches']['TLK1']->id,
        (int) dbaRoomIn($scene['branches']['TLK1'])->id,
    );
    expect((int) $context->branch_id)->toBe((int) $scene['branches']['TLK1']->id);
});

it('refuses approving a home branch outside the doctor practice branches', function (): void {
    $scene = dbaScene(['TLK1']);
    $outside = daBranch('LDK2');
    $locks = app(DoctorBranchLockApprovalService::class);

    $request = $locks->request(
        daSuperAdmin(),
        (int) $scene['doctor']->id,
        (int) $outside->id,
        'Perpindahan ke cabang di luar cabang praktik dokter.',
    );

    $exception = dbaCatchValidation(fn () => $locks->approve((int) $request->id, daSupervisorRme()));

    expect(array_keys($exception->errors()))->toContain('destination_branch_id')
        ->and($exception->getMessage())->toContain('Cabang Praktik')
        ->and($request->refresh()->status)->toBe(DoctorBranchLockRequest::STATUS_PENDING)
        ->and((int) DoctorBranchLock::query()->where('doctor_id', $scene['doctor']->id)->value('home_branch_id'))
        ->toBe((int) $scene['branches']['TLK1']->id);
});

/*
|--------------------------------------------------------------------------
| G. REJECTION CHANGES NOTHING
|--------------------------------------------------------------------------
*/

it('preserves branch, session and the whole device trail when a request is rejected', function (): void {
    // Nothing about the doctor's authority changed, so evicting them would be a
    // punishment for somebody else's paperwork. The lease must SURVIVE — which
    // makes this the mirror of section D and the reason both are needed.
    $scene = dbaScene();
    $trail = dbaDeviceTrail($scene['doctor']);

    daLoginPost($scene['user']);
    $lease = daCurrentLease($scene['user']);
    expect($lease)->not->toBeNull();

    $locks = app(DoctorBranchLockApprovalService::class);
    $request = $locks->request(
        daSuperAdmin(),
        (int) $scene['doctor']->id,
        (int) $scene['branches']['LDK2']->id,
        'Dokter dipindahkan permanen ke cabang Landak.',
    );

    $rejected = $locks->reject((int) $request->id, daSupervisorRme(), 'Belum disetujui manajemen cabang.');

    expect($rejected->status)->toBe(DoctorBranchLockRequest::STATUS_REJECTED)
        ->and($rejected->applied_at)->toBeNull()
        ->and((int) DoctorBranchLock::query()->where('doctor_id', $scene['doctor']->id)->value('home_branch_id'))
        ->toBe((int) $scene['branches']['TLK1']->id);

    daAssertActiveLeaseCount(1, $scene['user']);
    expect($lease->refresh()->released_at)->toBeNull();

    dbaAssertDeviceTrailIntact($trail);
});

it('preserves branch, session and the whole device trail when a cover is rejected', function (): void {
    $scene = dbaScene();
    $trail = dbaDeviceTrail($scene['doctor']);

    daLoginPost($scene['user']);
    $lease = daCurrentLease($scene['user']);
    expect($lease)->not->toBeNull();

    $covers = app(DoctorBranchCoverApprovalService::class);
    $period = dbaCoverInput(1, 5);
    $cover = $covers->request(
        daSuperAdmin(),
        (int) $scene['doctor']->id,
        (int) $scene['branches']['LDK2']->id,
        $period['starts_at'],
        $period['ends_at'],
        'Menutup jadwal dokter yang cuti di cabang Landak.',
    );

    $rejected = $covers->reject((int) $cover->id, daSupervisorRme(), 'Cover tidak diperlukan.');

    expect($rejected->status)->toBe(DoctorBranchCover::STATUS_REJECTED)
        ->and((int) DoctorBranchLock::query()->where('doctor_id', $scene['doctor']->id)->value('home_branch_id'))
        ->toBe((int) $scene['branches']['TLK1']->id);

    daAssertActiveLeaseCount(1, $scene['user']);
    expect($lease->refresh()->released_at)->toBeNull();

    dbaAssertDeviceTrailIntact($trail);
});

it('requires a reason when a request or a cover is rejected', function (): void {
    // THE OWNER'S REQUIREMENT, ASSERTED AS WRITTEN: "rejection preserves branch,
    // session, device and credential, AND REQUIRES A REASON."
    //
    // A rejection nobody can explain is not one — the same argument the cover
    // CANCELLATION path already accepts, where `requireReason()` enforces a
    // minimum length in the SERVICE rather than as a conditional FormRequest
    // rule, precisely so an HTTP caller and a console caller cannot diverge on
    // whether the reason was required.
    //
    // READ AT THE TIME OF WRITING: both reject paths take
    // `?string $decisionNote = null` and never call `requireReason()`, and
    // `decision_note` is `nullable` in DecideDoctorBranchLockRequestRequest and
    // DecideDoctorBranchCoverRequest alike. So this test is EXPECTED TO FAIL
    // until the requirement is implemented.
    //
    // The failure is a reported product gap, not a test to soften. Two, and only
    // two, honest resolutions exist: enforce the reason in the two services
    // beside the existing cancellation guard, or have the owner state that a
    // rejection note is optional — in which case THIS TEST is deleted and the
    // requirement is struck, rather than quietly weakened to assert whatever the
    // code happens to do.
    $scene = dbaScene();
    $locks = app(DoctorBranchLockApprovalService::class);
    $covers = app(DoctorBranchCoverApprovalService::class);
    $checker = daSupervisorRme();
    $maker = daSuperAdmin();

    $request = $locks->request(
        $maker,
        (int) $scene['doctor']->id,
        (int) $scene['branches']['LDK2']->id,
        'Dokter dipindahkan permanen ke cabang Landak.',
    );

    $requestRefusal = dbaCatchValidation(fn () => $locks->reject((int) $request->id, $checker, null));
    expect($request->refresh()->status)->toBe(DoctorBranchLockRequest::STATUS_PENDING)
        ->and($requestRefusal->errors())->not->toBeEmpty();

    $period = dbaCoverInput(1, 5);
    $cover = $covers->request(
        $maker,
        (int) $scene['doctor']->id,
        (int) $scene['branches']['ATG3']->id,
        $period['starts_at'],
        $period['ends_at'],
        'Menutup jadwal dokter yang cuti di cabang Antang.',
    );

    $coverRefusal = dbaCatchValidation(fn () => $covers->reject((int) $cover->id, $checker, null));
    expect($cover->refresh()->status)->toBe(DoctorBranchCover::STATUS_PENDING)
        ->and($coverRefusal->errors())->not->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| H. THE OWNER'S FOUR CONCURRENCY CASES
|--------------------------------------------------------------------------
|
| READ THIS BEFORE READING THE ASSERTIONS.
|
| RefreshDatabase wraps every test in ONE transaction on ONE connection, so a
| TRUE cross-connection race is NOT EXPRESSIBLE here. `lockForUpdate()` also
| compiles to an empty string on SQLite, which is the engine the local suite
| runs, so the row locks these services depend on emit nothing at all locally.
|
| WHAT THE FOUR CASES BELOW PROVE: the ORDERING and the LOGIC — that each
| decision re-reads the state it depends on inside its own transaction, in the
| order the design requires, and refuses rather than proceeding on a stale read.
| That is the half of concurrency correctness a single connection can show.
|
| WHAT THEY CANNOT PROVE: that two genuinely simultaneous approvers serialise.
| That rests on the `mst_doctor_branch_locks` row lock inside the approval
| transaction and is observable only on PostgreSQL with real commits on separate
| connections. Every name and comment below says which half it is. None of them
| may be read as evidence that the database prevents overlapping covers: it
| prevents duplicate PENDING covers and IDENTICAL approved periods, and nothing
| more — proven by attempting the write, not by inspecting the schema.
*/

it('serialises cover approval against a doctor login by releasing the lease the login must re-claim', function (): void {
    // OWNER CONCURRENCY CASE 1 — cover approval versus doctor login.
    //
    // PROVES: an approval that lands while a doctor is signed in leaves NO
    // active lease behind, so the next authentication is a fresh claim rather
    // than a session that silently changed branch underneath the doctor. Then
    // the re-login claims again and the count returns to exactly one.
    //
    // DOES NOT PROVE: what happens if the approval commits DURING the login
    // transaction. On one connection those two cannot interleave. The claim
    // path's own lost-race handling — the nested savepoint and the
    // unique-violation reclaim — is a PostgreSQL-only observation.
    $scene = dbaScene();

    daLoginPost($scene['user']);
    daAssertActiveLeaseCount(1, $scene['user']);
    $first = daCurrentLease($scene['user']);

    $covers = app(DoctorBranchCoverApprovalService::class);
    $period = dbaCoverInput(1, 5);
    $cover = $covers->request(
        daSuperAdmin(),
        (int) $scene['doctor']->id,
        (int) $scene['branches']['LDK2']->id,
        $period['starts_at'],
        $period['ends_at'],
        'Menutup jadwal dokter yang cuti di cabang Landak.',
    );
    $covers->approve((int) $cover->id, daSupervisorRme());

    daAssertActiveLeaseCount(0, $scene['user']);

    // NEXT-REQUEST EVICTION, which is the only cross-session logout this
    // codebase has: releasing the lease is DATA, and the doctor keeps working
    // until their browser makes another request. That request is made here, and
    // it is also what clears the authenticated session — the `login` route sits
    // behind `guest`, so a second POST to it from a still-authenticated client
    // would be redirected without ever firing the Login event, and the re-claim
    // below would silently assert nothing.
    $this->get(route('profile.edit'))->assertRedirect(route('login'));

    // The doctor authenticates again. The released row is retained, so the table
    // now holds history plus exactly one live lease.
    daLoginPost($scene['user']);

    daAssertActiveLeaseCount(1, $scene['user']);
    $second = daCurrentLease($scene['user']);

    expect($second)->not->toBeNull()
        ->and((int) $second->id)->not->toBe((int) $first->id)
        ->and(DoctorSessionLease::query()->where('user_id', $scene['user']->id)->count())->toBe(2);
});

it('decides cover expiry from the timestamps read at request time, never from a job', function (): void {
    // OWNER CONCURRENCY CASE 2 — cover expiry versus a protected request.
    //
    // PROVES: the answer to "which branch is this doctor working from" is
    // recomputed from current timestamps on the read path, so a request that
    // arrives one instant after ends_at gets the post-expiry answer with no
    // housekeeping in between. Two reads of the same unchanged row, either side
    // of the boundary, return different authority.
    //
    // DOES NOT PROVE: ordering against a concurrent writer. It proves only that
    // correctness does not DEPEND on one, which is the property section Q
    // demands — an expired cover must never remain effective because cron or a
    // queue worker was delayed.
    Queue::fake();

    $scene = dbaScene(['TLK1', 'LDK2']);
    $onlineContexts = app(UserOnlineContextService::class);

    $cover = daApprovedCover(
        $scene['doctor'],
        $scene['branches']['LDK2'],
        now()->subHours(2),
        now()->addMinutes(30),
    );

    // BEFORE the boundary: the cover branch is the authority.
    expect($cover->coversInstant(now()))->toBeTrue();
    $duringCover = $onlineContexts->startDoctorSession(
        $scene['user'],
        (int) $scene['branches']['LDK2']->id,
        (int) dbaRoomIn($scene['branches']['LDK2'])->id,
    );
    expect((int) $duringCover->branch_id)->toBe((int) $scene['branches']['LDK2']->id);

    // Cross the boundary with the clock alone. The ROW IS NOT TOUCHED.
    $updatedAtBefore = (string) $cover->refresh()->updated_at;
    pinTestClock(now()->addHour()->toDateTimeString());

    expect($cover->refresh()->coversInstant(now()))->toBeFalse()
        ->and($cover->status)->toBe(DoctorBranchCover::STATUS_APPROVED)
        ->and((string) $cover->updated_at)->toBe($updatedAtBefore);

    // AFTER the boundary: the cover branch is refused and home is the authority.
    $refusal = dbaCatchValidation(fn () => $onlineContexts->startDoctorSession(
        $scene['user'],
        (int) $scene['branches']['LDK2']->id,
        (int) dbaRoomIn($scene['branches']['LDK2'])->id,
    ));
    expect(array_keys($refusal->errors()))->toContain('branch_id');

    $afterExpiry = $onlineContexts->startDoctorSession(
        $scene['user'],
        (int) $scene['branches']['TLK1']->id,
        (int) dbaRoomIn($scene['branches']['TLK1'])->id,
    );
    expect((int) $afterExpiry->branch_id)->toBe((int) $scene['branches']['TLK1']->id);

    Queue::assertNothingPushed();
});

it('refuses the second of two overlapping approvals and refuses an identical period at the database level', function (): void {
    // OWNER CONCURRENCY CASE 3 — simultaneous overlapping approvals.
    //
    // PROVES two separate things, and they are separate on purpose:
    //
    //   (1) SEQUENCED, IN-SERVICE: once the first approval has committed, a
    //       second approval of an OVERLAPPING period re-reads the approved set
    //       under the locks and refuses. That is the logic half.
    //
    //   (2) AT THE DATABASE: `trx_doctor_branch_covers_period_uq` refuses a
    //       second APPROVED row for the IDENTICAL period — the double-submit
    //       race — and it is asserted by attempting the insert, not by reading
    //       the schema. The insert runs in a NESTED DB::transaction so the
    //       framework emits a SAVEPOINT: on PostgreSQL a failed statement
    //       aborts the WHOLE transaction, so the count query that follows is the
    //       actual proof — it is the statement PostgreSQL kills with 25P02 if
    //       the savepoint was not used, and it passes on SQLite either way.
    //
    // DOES NOT PROVE: that two approvers acting at the same instant serialise.
    // The database CANNOT express the overlap rule at all — a unique index
    // compares values while overlap is a range predicate — so the invariant
    // rests entirely on the `mst_doctor_branch_locks` row lock inside the
    // approval transaction, which emits nothing on SQLite. Only the PostgreSQL
    // gate can observe it. Nothing here may be read as "the database prevents
    // overlapping covers", because it does not.
    $scene = dbaScene();
    $covers = app(DoctorBranchCoverApprovalService::class);
    $checker = daSupervisorRme();

    $firstApproved = daApprovedCover(
        $scene['doctor'],
        $scene['branches']['LDK2'],
        now()->subHour(),
        now()->addHours(6),
    );

    // (1) the overlapping second decision.
    $second = dbaPendingCover(
        $scene['doctor'],
        $scene['branches']['ATG3'],
        now()->addHours(3),
        now()->addHours(9),
        daSuperAdmin(),
        $scene['branches']['TLK1'],
    );

    $exception = dbaCatchValidation(fn () => $covers->approve((int) $second->id, $checker));

    expect(array_keys($exception->errors()))->toContain('starts_at')
        ->and($second->refresh()->status)->toBe(DoctorBranchCover::STATUS_PENDING)
        ->and(DoctorBranchCover::query()->where('status', DoctorBranchCover::STATUS_APPROVED)->count())->toBe(1);

    // (2) the identical-period backstop, proven by attempting to violate it.
    // The stored values are read back so the insert byte-matches the existing
    // row; a hand-formatted string could miss the index by a fractional second.
    $stored = DB::table('trx_doctor_branch_covers')->where('id', $firstApproved->id)->first();

    expect(fn () => DB::transaction(fn () => DB::table('trx_doctor_branch_covers')->insert([
        'doctor_id' => $stored->doctor_id,
        'requester_user_id' => $stored->requester_user_id,
        'source_home_branch_id' => $stored->source_home_branch_id,
        'target_branch_id' => $stored->target_branch_id,
        'starts_at' => $stored->starts_at,
        'ends_at' => $stored->ends_at,
        'reason' => 'Duplikat periode identik yang harus ditolak database.',
        'requested_at' => $stored->requested_at,
        'status' => DoctorBranchCover::STATUS_APPROVED,
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class);

    // THE PROOF QUERY. If the unique violation had been caught inside the
    // enclosing transaction rather than outside a savepoint, PostgreSQL would
    // kill this statement with 25P02.
    expect(DoctorBranchCover::query()->where('status', DoctorBranchCover::STATUS_APPROVED)->count())->toBe(1);
});

it('refuses a permanent transfer that reads a live cover rather than racing it', function (): void {
    // OWNER CONCURRENCY CASE 4 — permanent transfer while a temporary cover
    // exists.
    //
    // PROVES: the transfer decision re-reads the cover predicate INSIDE its own
    // transaction, after the doctor row is locked, and refuses; and that the
    // refusal is a live-cover predicate rather than "a cover row exists", since
    // a merely SCHEDULED future cover does NOT block. Both branches of that
    // predicate are asserted, because gating on the wrong one would either jam
    // the workflow or admit the ambiguity section Q forbids.
    //
    // DOES NOT PROVE: that a transfer approval and a cover approval issued at
    // the same instant serialise on the doctor row. That is the row lock's job
    // and it compiles to nothing on SQLite; only the PostgreSQL gate observes
    // it. What is shown here is that neither decision trusts a read taken
    // before its own lock.
    $scene = dbaScene();
    $locks = app(DoctorBranchLockApprovalService::class);
    $checker = daSupervisorRme();

    // A FUTURE cover: no ambiguity about the CURRENT effective branch, so the
    // transfer must proceed.
    daApprovedCover(
        $scene['doctor'],
        $scene['branches']['ATG3'],
        now()->addDays(2),
        now()->addDays(3),
    );

    $scheduledCaseRequest = $locks->request(
        daSuperAdmin(),
        (int) $scene['doctor']->id,
        (int) $scene['branches']['LDK2']->id,
        'Dokter dipindahkan permanen ke cabang Landak.',
    );

    $locks->approve((int) $scheduledCaseRequest->id, $checker);

    expect((int) DoctorBranchLock::query()->where('doctor_id', $scene['doctor']->id)->value('home_branch_id'))
        ->toBe((int) $scene['branches']['LDK2']->id);

    // Now an ACTIVE cover, filed and approved AFTER the transfer request the
    // approver is about to decide — so the request was filed against a state
    // where nothing blocked it and the block can only come from the re-read
    // inside approve().
    $backRequest = $locks->request(
        daSuperAdmin(),
        (int) $scene['doctor']->id,
        (int) $scene['branches']['TLK1']->id,
        'Dokter dikembalikan permanen ke cabang Telkomas.',
    );

    daApprovedCover(
        $scene['doctor'],
        $scene['branches']['ATG3'],
        now()->subMinutes(30),
        now()->addHours(4),
        sourceHomeBranch: $scene['branches']['LDK2'],
    );

    $exception = dbaCatchValidation(fn () => $locks->approve((int) $backRequest->id, $checker));

    expect(array_keys($exception->errors()))->toContain('request')
        ->and($exception->getMessage())->toContain('cover sementara')
        ->and($backRequest->refresh()->status)->toBe(DoctorBranchLockRequest::STATUS_PENDING)
        ->and((int) DoctorBranchLock::query()->where('doctor_id', $scene['doctor']->id)->value('home_branch_id'))
        ->toBe((int) $scene['branches']['LDK2']->id);
});

it('ACCEPTS a cover that begins exactly where the previous one ends, which is what half-open means', function (): void {
    /*
     * THE OTHER DIRECTION OF THE NON-OVERLAP RULE, and the direction a refusal
     * test can never reach. Everything above proves that overlapping periods are
     * refused; NOTHING above proves that back-to-back periods are allowed, so a
     * single `<=` where the code needs `<` would pass every test in this file
     * while refusing the most ordinary real request an approver ever files:
     * morning cover, then afternoon cover, same day.
     *
     * The owner stated the case in wall-clock terms — 08:00-12:00 and 12:00-16:00
     * must NOT overlap — so the boundary here is an EXACT instant shared by the
     * two rows: `$boundary` is the first period's ends_at and the second's
     * starts_at, the same value, not two values a minute apart. An adjacency test
     * built from "+1 second" would pass under either comparison operator and
     * would prove nothing.
     */
    $scene = dbaScene();
    $covers = app(DoctorBranchCoverApprovalService::class);
    $clock = app(ClinicalClock::class);

    // The shared instant, formatted ONCE on the clinical wall clock and reused
    // for both rows, because the operator surface parses in WITA while the column
    // stores UTC — deriving the two ends from two separate now() reads is how a
    // "shared" boundary silently becomes two instants microseconds apart.
    $morningStart = $clock->now()->addHours(1)->format('Y-m-d H:i:s');
    $boundary = $clock->now()->addHours(5)->format('Y-m-d H:i:s');
    $afternoonEnd = $clock->now()->addHours(9)->format('Y-m-d H:i:s');

    $morning = $covers->request(
        daSuperAdmin(),
        (int) $scene['doctor']->id,
        (int) $scene['branches']['LDK2']->id,
        $morningStart,
        $boundary,
        'Cover pagi di cabang Landak.',
    );

    $covers->approve((int) $morning->id, daSupervisorRme());

    // Filed AFTER the first is already APPROVED, so the advisory check in
    // request() and the enforced check in approve() both see a live neighbour.
    $afternoon = $covers->request(
        daSuperAdmin(),
        (int) $scene['doctor']->id,
        (int) $scene['branches']['ATG3']->id,
        $boundary,
        $afternoonEnd,
        'Cover siang di cabang Antang.',
    );

    $covers->approve((int) $afternoon->id, daSupervisorRme());

    expect($morning->refresh()->status)->toBe(DoctorBranchCover::STATUS_APPROVED)
        ->and($afternoon->refresh()->status)->toBe(DoctorBranchCover::STATUS_APPROVED)
        // The shared instant really is shared, so the acceptance above was
        // decided at the boundary rather than across a gap.
        ->and($afternoon->starts_at->equalTo($morning->ends_at))->toBeTrue();

    // AND THE SEMANTICS, not merely the acceptance: at the boundary instant the
    // FIRST cover is already over and the SECOND is already in force. That is the
    // whole content of [starts_at, ends_at) and it is what makes two adjacent
    // covers unambiguous about which branch the doctor is at.
    $at = $afternoon->starts_at->toImmutable();

    expect($morning->coversInstant($at))->toBeFalse()
        ->and($afternoon->coversInstant($at))->toBeTrue();

    // One second earlier, the morning cover still holds and the afternoon one
    // does not — the transition is at the boundary, not near it.
    $justBefore = $at->subSecond();

    expect($morning->coversInstant($justBefore))->toBeTrue()
        ->and($afternoon->coversInstant($justBefore))->toBeFalse();

    /*
     * THE SECOND HALF OF THE PREDICATE, AND IT IS HERE BECAUSE A MUTATION PROVED
     * THE FIRST HALF ALONE WAS NOT ENOUGH.
     *
     * Overlap is `starts_at < :ends AND ends_at > :starts`, two comparisons, and
     * the pair above only reaches ONE of them: filed in chronological order, the
     * neighbour is rejected by `ends_at > :starts` and `starts_at` never decides
     * anything. Relaxing `starts_at` to `<=` was measured and the test above
     * still PASSED, so that operator was unpinned.
     *
     * Filing in REVERSE order — the later period first, the earlier one second —
     * is what puts the other comparison in the deciding position: the new cover
     * ends exactly where the existing one begins.
     */
    $reverse = dbaScene(['TLK1', 'LDK2', 'ATG3'], 'TLK1');

    $lateStart = $clock->now()->addHours(20)->format('Y-m-d H:i:s');
    $sharedEdge = $clock->now()->addHours(16)->format('Y-m-d H:i:s');
    $earlyStart = $clock->now()->addHours(12)->format('Y-m-d H:i:s');

    $later = $covers->request(
        daSuperAdmin(),
        (int) $reverse['doctor']->id,
        (int) $reverse['branches']['LDK2']->id,
        $sharedEdge,
        $lateStart,
        'Cover sore, diajukan lebih dulu.',
    );
    $covers->approve((int) $later->id, daSupervisorRme());

    $earlier = $covers->request(
        daSuperAdmin(),
        (int) $reverse['doctor']->id,
        (int) $reverse['branches']['ATG3']->id,
        $earlyStart,
        $sharedEdge,
        'Cover siang, berakhir tepat saat cover sore dimulai.',
    );
    $covers->approve((int) $earlier->id, daSupervisorRme());

    expect($later->refresh()->status)->toBe(DoctorBranchCover::STATUS_APPROVED)
        ->and($earlier->refresh()->status)->toBe(DoctorBranchCover::STATUS_APPROVED)
        ->and($earlier->ends_at->equalTo($later->starts_at))->toBeTrue();
});

it('ACCEPTS overlapping covers for two DIFFERENT doctors, because the invariant is per doctor', function (): void {
    /*
     * THE OWNER STATED THIS AS ITS OWN CASE, and it is the one an over-broad
     * invariant breaks. Overlap is a property of ONE doctor's timeline. If the
     * enforced check or either partial unique index omitted `doctor_id`, the
     * SECOND doctor's cover for the same hours would be refused — and the outage
     * would land on a normal clinic day, when two clinicians cover two branches
     * over the same lunch hour, with an error message about a collision that does
     * not exist.
     *
     * Both covers are filed through the real service with the SAME instants, so
     * the acceptance cannot be attributed to the periods differing.
     */
    $first = dbaScene(['TLK1', 'LDK2', 'ATG3'], 'TLK1');
    $second = dbaScene(['TLK1', 'LDK2', 'ATG3'], 'TLK1');

    expect((int) $first['doctor']->id)->not->toBe((int) $second['doctor']->id);

    $covers = app(DoctorBranchCoverApprovalService::class);
    $period = dbaCoverInput(1, 5);

    $firstCover = $covers->request(
        daSuperAdmin(),
        (int) $first['doctor']->id,
        (int) $first['branches']['LDK2']->id,
        $period['starts_at'],
        $period['ends_at'],
        'Cover dokter pertama di cabang Landak.',
    );
    $covers->approve((int) $firstCover->id, daSupervisorRme());

    // IDENTICAL instants, a different doctor, and the same target branch — the
    // strictest version of the case, because a same-branch collision is the one a
    // careless index would most plausibly forbid.
    $secondCover = $covers->request(
        daSuperAdmin(),
        (int) $second['doctor']->id,
        (int) $second['branches']['LDK2']->id,
        $period['starts_at'],
        $period['ends_at'],
        'Cover dokter kedua di cabang Landak.',
    );
    $covers->approve((int) $secondCover->id, daSupervisorRme());

    expect($firstCover->refresh()->status)->toBe(DoctorBranchCover::STATUS_APPROVED)
        ->and($secondCover->refresh()->status)->toBe(DoctorBranchCover::STATUS_APPROVED)
        ->and($secondCover->starts_at->equalTo($firstCover->starts_at))->toBeTrue()
        ->and($secondCover->ends_at->equalTo($firstCover->ends_at))->toBeTrue()
        ->and(DoctorBranchCover::query()->where('status', DoctorBranchCover::STATUS_APPROVED)->count())->toBe(2);

    // And each doctor's effective branch is their OWN cover's target, so the two
    // rows do not merely coexist in the table — they resolve independently.
    $resolver = app(DoctorEffectiveBranchResolver::class);

    pinTestClock(now()->addHours(3)->toDateTimeString());

    expect($resolver->branchIdFor($first['user']))->toBe((int) $first['branches']['LDK2']->id)
        ->and($resolver->branchIdFor($second['user']))->toBe((int) $second['branches']['LDK2']->id);
});

it('ACCEPTS a new cover once the previous one has expired, with the clock alone', function (): void {
    /*
     * The owner's "expired previous cover: does not block". The enforced overlap
     * check reads the cover table by doctor and instant, so a check written
     * against STATUS alone — approved rows, any period — would refuse this, and
     * a doctor who covered another branch last week could never cover one again.
     *
     * No job, no command, no scheduler: the clock moves and nothing else, which is
     * the same mechanism the expiry cases above rest on.
     */
    Queue::fake();

    $scene = dbaScene();
    $covers = app(DoctorBranchCoverApprovalService::class);
    $clock = app(ClinicalClock::class);

    $expired = daApprovedCover(
        $scene['doctor'],
        $scene['branches']['LDK2'],
        now()->subHours(6),
        now()->subHour(),
    );

    // The old row is still APPROVED and is still in the table; it is only its
    // PERIOD that is over. A test that cancelled or deleted it would prove the
    // status filter, not the period filter.
    expect($expired->refresh()->status)->toBe(DoctorBranchCover::STATUS_APPROVED)
        ->and($expired->coversInstant(now()->toImmutable()))->toBeFalse();

    $fresh = $covers->request(
        daSuperAdmin(),
        (int) $scene['doctor']->id,
        (int) $scene['branches']['ATG3']->id,
        $clock->now()->addHour()->format('Y-m-d H:i:s'),
        $clock->now()->addHours(5)->format('Y-m-d H:i:s'),
        'Cover baru setelah cover sebelumnya berakhir.',
    );

    $covers->approve((int) $fresh->id, daSupervisorRme());

    expect($fresh->refresh()->status)->toBe(DoctorBranchCover::STATUS_APPROVED)
        ->and(DoctorBranchCover::query()->where('status', DoctorBranchCover::STATUS_APPROVED)->count())->toBe(2);

    Queue::assertNothingPushed();
});

it('DENIES the evicted session on its very next request, and lets the doctor back in by logging in again', function (): void {
    /*
     * THE OWNER'S "old session denial" AND "fresh login new branch", END TO END
     * THROUGH HTTP — which is the only place the property is real.
     *
     * Everything above proves that an approval RELEASES the lease row. That is
     * not the same statement as "the browser that was working is now locked out":
     * the released row is invisible to the doctor, whose session still holds the
     * lease token, and it is EnsureDoctorSessionLease that has to turn that token
     * into a refusal on the next request. Between those two facts sits the whole
     * eviction, and a suite that asserted only the row would ship an approval
     * that quietly changed nothing for the person holding the session.
     */
    $scene = dbaScene(['TLK1', 'LDK2']);

    daLoginPost($scene['user']);
    expect(daCurrentLease($scene['user']))->not->toBeNull();

    // The session is genuinely working BEFORE the approval, so the refusal below
    // can only be attributed to the approval.
    $this->get('/profile')->assertOk();

    $locks = app(DoctorBranchLockApprovalService::class);
    $request = $locks->request(
        daSuperAdmin(),
        (int) $scene['doctor']->id,
        (int) $scene['branches']['LDK2']->id,
        'Dokter dipindahkan permanen ke cabang Landak.',
    );
    $locks->approve((int) $request->id, daSupervisorRme());

    // THE DENIAL. Same browser, same session, next request.
    $this->get('/profile')->assertRedirect(route('login'));

    // The lock moved, and the doctor is not locked OUT of the system — only out
    // of that session. A fresh login succeeds and claims a new lease.
    daAssertActiveLeaseCount(0, $scene['user']);

    daLoginPost($scene['user'])->assertRedirect();

    daAssertActiveLeaseCount(1, $scene['user']);

    // ...and it lands on the NEW branch: the whole point of forcing the fresh
    // login is that branch context is re-derived rather than carried over.
    expect(app(DoctorEffectiveBranchResolver::class)->branchIdFor($scene['user']))
        ->toBe((int) $scene['branches']['LDK2']->id);

    $onlineContexts = app(UserOnlineContextService::class);

    $refusal = dbaCatchValidation(fn () => $onlineContexts->startDoctorSession(
        $scene['user'],
        (int) $scene['branches']['TLK1']->id,
        (int) dbaRoomIn($scene['branches']['TLK1'])->id,
    ));
    expect(array_keys($refusal->errors()))->toContain('branch_id');

    $context = $onlineContexts->startDoctorSession(
        $scene['user'],
        (int) $scene['branches']['LDK2']->id,
        (int) dbaRoomIn($scene['branches']['LDK2'])->id,
    );

    expect((int) $context->branch_id)->toBe((int) $scene['branches']['LDK2']->id);
});
