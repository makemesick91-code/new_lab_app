<?php

declare(strict_types=1);

/*
| DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — THE HOME BRANCH LOCK.
|
| Owner decisions O1, O2 and O3, and open items V2, V4 and V5, proven against
| the code rather than against the sprint's own description of it.
|
| WHAT THIS FILE IS ABOUT, AND WHAT IT DELIBERATELY LEAVES TO ITS SIBLINGS.
| The subject is EFFECTIVE_CLINICAL_BRANCH as consumed by the three surfaces the
| owner named: the operational LISTS, the WRITE chokepoint, and the branch
| selector. The lease engine (claim, deny, evict, release) and the two approval
| workflows have their own suites; this file touches the lease only where the
| owner's requirement is explicitly about surviving a logout and a fresh login.
|
| THE INSTRUMENTS, AND WHY EACH ONE WAS CHOSEN. Measured, not assumed:
|
|  - `actingAs()` is used for every lock test that is NOT about login, because
|    SessionGuard::setUser() fires Authenticated and never Login, so no lease is
|    claimed and the branch lock is observed in isolation. Ruling C1 makes the
|    middleware pass a session with no lease token straight through, so these
|    requests reach the surface under test.
|  - `daLoginPost()` is used ONLY where a real authentication entry is the point.
|    A real login DOES claim a lease, and the lease records the branch the
|    session was established under, so ordering matters: the lock is granted
|    BEFORE the login in every test here, never between two requests of one
|    session.
|  - The WRITE chokepoint is exercised through `ClinicVisitService` directly for
|    the new-patient converging input, because the HTTP registration path cannot
|    reach it for a doctor at all: `ClinicVisitController::store()` additionally
|    authorises `create` on Patient, an ability the Doctor role does not hold.
|    The existing-patient input is proven BOTH ways — through the service, and
|    through a real POST — so the chokepoint is not taken on trust.
|  - Every visit fixture is `completed`, never a pre-exam status. The doctor
|    visit lists also pass through DoctorRoomScopeService, which hides PRE-EXAM
|    rows belonging to another room; a pre-exam fixture would make a list
|    assertion depend on room occupancy instead of on branch scope.
|
| ONE HONEST LIMIT, STATED ONCE. `RmeWorkingBranchScope::resolve()` is the single
| entry every visit list, queue, worklist and count widget funnels through
| (ClinicVisitService::scopeBranchIds). Asserting the branch set it returns is
| therefore a statement about all of them; asserting the rendered HTML of one
| page is a statement about that page. Both are done, and they are not the same
| claim.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorAccess\Services\DoctorEffectiveBranchResolver;
use App\Modules\DoctorAccess\Support\DoctorEffectiveBranch;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Odontogram\Services\OdontogramService;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Modules\RmeOnlineContext\Models\UserOnlineContext;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use App\Modules\Treatment\Models\Treatment;
use App\Support\Clinical\ClinicalClock;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    daSeedAccessControl();

    /*
     * Two real RME branches. The owner's brief writes Sunu as "SPN4"; the
     * canonical branch registry (RME-BRANCH-SUN4) codes it SUN4. The code is
     * only a label here — every assertion below is on branch IDS — but the
     * canonical spelling is used so this file never teaches the wrong one.
     */
    $this->sunu = daBranch('SUN4', ['name' => 'Cabang Sunu']);
    $this->landak = daBranch('LDK2', ['name' => 'Cabang Landak']);

    $this->clinic = Clinic::factory()->create();
});

/*
|--------------------------------------------------------------------------
| Local fixtures
|--------------------------------------------------------------------------
*/

/**
 * A visit that a doctor's own lists will actually show.
 *
 * `completed`, not a pre-exam status: see the file header. `clinic_room_id` is
 * pinned to null so ClinicVisitFactory's default does not silently create an
 * extra room AND an extra branch, which would move the legacy branch set that
 * case 1 measures.
 */
function dhblVisit(Branch $branch, Doctor $doctor, ?Patient $patient = null, array $overrides = []): ClinicVisit
{
    $patient ??= Patient::factory()->create([
        'branch_id' => $branch->id,
        'name' => 'Pasien '.$branch->code,
    ]);

    return ClinicVisit::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'clinic_id' => test()->clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'clinic_room_id' => null,
        'status' => ClinicVisit::STATUS_COMPLETED,
        'visit_date' => app(ClinicalClock::class)->todayString(),
    ], $overrides));
}

/** Put a doctor online at a branch. Used BEFORE a lock is granted, deliberately. */
function dhblGoOnline(User $user, Branch $branch): ClinicRoom
{
    $room = ClinicRoom::factory()->create([
        'branch_id' => $branch->id,
        'status' => ClinicRoom::STATUS_ACTIVE,
    ]);

    app(UserOnlineContextService::class)->startDoctorSession($user, (int) $branch->id, (int) $room->id);

    return $room;
}

/** An ACTIVE clinic tablet at a branch, authorised for this doctor. The physical device. */
function dhblTablet(Doctor $doctor, Branch $branch): DoctorDevice
{
    $device = DoctorDevice::factory()->create([
        'branch_id' => $branch->id,
        'status' => DoctorDevice::STATUS_ACTIVE,
        'device_name' => 'Tablet '.$branch->code,
    ]);

    DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
    ]);

    return $device;
}

function dhblScope(): RmeWorkingBranchScope
{
    return app(RmeWorkingBranchScope::class);
}

function dhblResolver(): DoctorEffectiveBranchResolver
{
    return app(DoctorEffectiveBranchResolver::class);
}

/** The payload ClinicVisitService::create() needs, minus the branch under test. */
function dhblVisitPayload(Doctor $doctor, Patient $patient, int $branchId): array
{
    return [
        'patient_mode' => 'existing',
        'branch_id' => $branchId,
        'clinic_id' => test()->clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
    ];
}

/*
|--------------------------------------------------------------------------
| OWNER DECISION O2 — THE THREE-CASE REGRESSION
|--------------------------------------------------------------------------
|
| This is the heart of the sprint and the three cases are written as three
| separate tests on purpose: a single test that walked all three states would
| report one failure for whichever broke first and say nothing about the others.
*/

it('CASE 1 — an UNSET doctor keeps the pre-sprint branch set, byte for byte', function () {
    ['user' => $user, 'doctor' => $doctor] = daDoctorAccount([$this->sunu, $this->landak]);
    dhblGoOnline($user, $this->sunu);

    dhblVisit($this->sunu, $doctor);
    dhblVisit($this->landak, $doctor);

    // MEASURE the legacy answer with the capability disarmed. The baseline is
    // taken from the running code, never written down as an expected literal:
    // a literal would still pass if the pre-sprint behaviour itself changed.
    daDisarmFlags();
    daUnobservableSessionStore();
    $legacyOperational = dhblScope()->operationalBranchIdsFor($user);
    $legacyPlain = dhblScope()->branchIdsFor($user);

    daArmDoctorAccess();

    // No lock row exists. Owner decision O1: this is the compatibility state,
    // not missing data.
    expect(dhblResolver()->resolve($user)->source())->toBe(DoctorEffectiveBranch::SOURCE_UNSET)
        ->and(dhblResolver()->branchIdFor($user))->toBeNull()
        ->and(dhblResolver()->isLocked($user))->toBeFalse()
        ->and(dhblScope()->operationalBranchIdsFor($user))->toBe($legacyOperational)
        ->and(dhblScope()->operationalBranchIdsFor($user))->toBe($legacyPlain)
        // Guard against a vacuous pass: the legacy set must be genuinely wider
        // than one branch, otherwise "unchanged" would prove nothing.
        ->and(count($legacyOperational))->toBeGreaterThan(1)
        ->and($legacyOperational)->toContain((int) $this->sunu->id)
        ->and($legacyOperational)->toContain((int) $this->landak->id);

    // And the rendered list, which is the claim an operator would recognise.
    $this->actingAs($user)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('Pasien SUN4')
        ->assertSee('Pasien LDK2');
});

it('CASE 2 — a doctor LOCKED to SUN4 resolves operational lists to SUN4 only', function () {
    ['user' => $user, 'doctor' => $doctor] = daDoctorAccount([$this->sunu, $this->landak]);
    dhblGoOnline($user, $this->sunu);

    dhblVisit($this->sunu, $doctor);
    dhblVisit($this->landak, $doctor);

    daGrantHomeLock($doctor, $this->sunu);
    daArmDoctorAccess();

    $effective = dhblResolver()->resolve($user);

    expect($effective->source())->toBe(DoctorEffectiveBranch::SOURCE_HOME)
        ->and($effective->branchId())->toBe((int) $this->sunu->id)
        ->and($effective->isLocked())->toBeTrue()
        // THE list scope, and the entry every list funnels through.
        ->and(dhblScope()->operationalBranchIdsFor($user))->toBe([(int) $this->sunu->id])
        ->and(dhblScope()->resolve($user))->toBe([(int) $this->sunu->id])
        // A crafted filter cannot widen it back out.
        ->and(dhblScope()->resolve($user, (int) $this->landak->id))->toBe([(int) $this->sunu->id]);

    $this->actingAs($user)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('Pasien SUN4')
        ->assertDontSee('Pasien LDK2');

    $this->actingAs($user)
        ->get(route('rme.visits.index', ['branch_id' => $this->landak->id]))
        ->assertOk()
        ->assertSee('Pasien SUN4')
        ->assertDontSee('Pasien LDK2');
});

it('CASE 3 — the same doctor on the LDK2 tablet STILL sees only SUN4', function () {
    /*
     * THE PROOF THAT THE PHYSICAL DEVICE BRANCH NEVER DETERMINES CLINICAL SCOPE.
     *
     * Everything that could plausibly speak for "the doctor is at Landak" is
     * present and pointing at LDK2 at once:
     *   - an ACTIVE, authorised clinic tablet whose branch_id is LDK2;
     *   - a live doctor ONLINE context at LDK2, in an LDK2 treatment room.
     *
     * The online context is established BEFORE the lock is granted, which is
     * both the only order the code permits (a locked doctor cannot go online at
     * another branch — see the self-switch test) and the real production
     * narrative: the doctor was already working at Landak when Sunu was
     * approved as their home branch.
     */
    ['user' => $user, 'doctor' => $doctor] = daDoctorAccount([$this->sunu, $this->landak]);

    dhblTablet($doctor, $this->landak);
    dhblGoOnline($user, $this->landak);

    dhblVisit($this->sunu, $doctor);
    dhblVisit($this->landak, $doctor);

    daGrantHomeLock($doctor, $this->sunu);
    daArmDoctorAccess();

    expect(dhblResolver()->branchIdFor($user))->toBe((int) $this->sunu->id)
        ->and(dhblScope()->operationalBranchIdsFor($user))->toBe([(int) $this->sunu->id])
        // The tablet is real and authorised, and it changes nothing.
        ->and(DoctorDevice::query()->where('branch_id', $this->landak->id)->exists())->toBeTrue()
        // So is the LDK2 presence row.
        ->and((int) UserOnlineContext::query()->where('user_id', $user->id)->value('branch_id'))
        ->toBe((int) $this->landak->id);

    $this->actingAs($user)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('Pasien SUN4')
        ->assertDontSee('Pasien LDK2');
});

/*
|--------------------------------------------------------------------------
| A LOCKED DOCTOR CANNOT SELF-SWITCH
|--------------------------------------------------------------------------
*/

it('refuses a crafted branch_id at the online-context start SERVER-SIDE, not merely in the view', function () {
    ['user' => $user, 'doctor' => $doctor] = daDoctorAccount([$this->sunu, $this->landak]);

    // A perfectly valid room at a branch this doctor is genuinely entitled to
    // practise in. Nothing about this request is malformed — only the branch is
    // outside the lock, which is the whole point.
    $landakRoom = ClinicRoom::factory()->create([
        'branch_id' => $this->landak->id,
        'status' => ClinicRoom::STATUS_ACTIVE,
    ]);

    daGrantHomeLock($doctor, $this->sunu);
    daArmDoctorAccess();

    $response = $this->actingAs($user)->post(route('rme.online-context.doctor'), [
        'branch_id' => $this->landak->id,
        'clinic_room_id' => $landakRoom->id,
    ]);

    $response->assertSessionHasErrors('branch_id');

    // The message names the branch the doctor is committed to, and 'terkunci'
    // appears in no other refusal on this path — so this asserts the LOCK
    // refused it, not merely that something did.
    expect(session('errors')->get('branch_id')[0])->toContain('terkunci')
        ->toContain('Cabang Sunu')
        // NOTHING was written. A refused self-switch must not leave a presence
        // row at the branch it was refused for.
        ->and(UserOnlineContext::query()->where('user_id', $user->id)->exists())->toBeFalse();

    // The same refusal at the service boundary, so it cannot be attributed to
    // the FormRequest or to route middleware.
    expect(fn () => app(UserOnlineContextService::class)
        ->startDoctorSession($user, (int) $this->landak->id, (int) $landakRoom->id))
        ->toThrow(ValidationException::class);

    // And the locked branch itself is still reachable — the lock narrows, it
    // does not strand (a positive control for the refusal above).
    $sunuRoom = ClinicRoom::factory()->create([
        'branch_id' => $this->sunu->id,
        'status' => ClinicRoom::STATUS_ACTIVE,
    ]);

    $this->actingAs($user)
        ->post(route('rme.online-context.doctor'), [
            'branch_id' => $this->sunu->id,
            'clinic_room_id' => $sunuRoom->id,
        ])
        ->assertSessionHasNoErrors();

    expect((int) UserOnlineContext::query()->where('user_id', $user->id)->value('branch_id'))
        ->toBe((int) $this->sunu->id);
});

it('offers a locked doctor only their own branch in the selector (open item V3)', function () {
    ['user' => $user, 'doctor' => $doctor] = daDoctorAccount([$this->sunu, $this->landak]);
    dhblGoOnline($user, $this->sunu);

    daGrantHomeLock($doctor, $this->sunu);
    daArmDoctorAccess();

    $this->actingAs($user);

    // A choice that does nothing is worse than no choice: `narrow()` would
    // discard any other branch anyway, so the selector must not offer one.
    expect(app(ClinicVisitService::class)->selectableRmeBranches()->pluck('id')->map(fn ($id) => (int) $id)->all())
        ->toBe([(int) $this->sunu->id]);
});

/*
|--------------------------------------------------------------------------
| THE WRITE CHOKEPOINT — BOTH CONVERGING INPUTS
|--------------------------------------------------------------------------
|
| Narrowing what a locked doctor SEES does not constrain what they WRITE. Both
| branch inputs converge in ClinicVisitService::resolveBranchId(): the
| existing-patient `branch_id` and the new-patient `new_patient.branch_id`.
| Each one is covered, and the audit trail open item V2 asked for is proven
| rather than assumed.
*/

it('refuses an out-of-lock visit written by a locked doctor, and AUDITS the refusal', function () {
    ['user' => $user, 'doctor' => $doctor] = daDoctorAccount([$this->sunu, $this->landak]);
    $patient = Patient::factory()->create(['branch_id' => $this->landak->id, 'name' => 'Pasien LDK2']);

    daGrantHomeLock($doctor, $this->sunu);
    daArmDoctorAccess();

    $this->actingAs($user);

    $before = ClinicVisit::query()->count();

    expect(fn () => app(ClinicVisitService::class)->create(
        dhblVisitPayload($doctor, $patient, (int) $this->landak->id),
    ))->toThrow(ValidationException::class);

    // No visit anywhere. The refusal is asserted BEFORE the transaction opens,
    // so there is nothing to roll back.
    expect(ClinicVisit::query()->count())->toBe($before);

    $audit = DB::table('sys_audit_logs')
        ->where('action', 'DOCTOR_EFFECTIVE_BRANCH_WRITE_REFUSED')
        ->get();

    expect($audit)->toHaveCount(1);

    $row = $audit->first();
    $payload = json_decode((string) $row->new_values, true);

    expect((int) $row->performed_by)->toBe((int) $user->id)
        ->and($row->entity_type)->toBe('trx_clinic_visits')
        ->and($payload['effective_branch_id'])->toBe((int) $this->sunu->id)
        ->and($payload['attempted_branch_id'])->toBe((int) $this->landak->id)
        ->and($payload['user_id'])->toBe((int) $user->id)
        ->and($payload['reason'])->toBe('branch_outside_doctor_effective_branch')
        ->and($payload['surface'])->toBe('clinic_visit_create');

    // IDS AND REASON CODES ONLY. Pinning the key set is what stops a future
    // field added to the registration form from leaking a patient name, an RM
    // number or a complaint into the audit trail.
    $keys = array_keys($payload);
    sort($keys);

    expect($keys)->toBe([
        'attempted_branch_id',
        'effective_branch_id',
        'reason',
        'surface',
        'user_id',
    ]);
});

it('refuses the NEW-PATIENT converging input too, and creates no patient', function () {
    /*
     * The second input, and the more damaging one: in new-patient mode the
     * write creates a PATIENT as well as a visit, so an unguarded forged branch
     * would plant a patient record at a branch the doctor may not work in.
     *
     * Exercised at the service, not over HTTP, because the HTTP path is closed
     * to a doctor for an unrelated reason — ClinicVisitController::store()
     * authorises `create` on Patient, which the Doctor role does not hold. The
     * chokepoint is the same one either way.
     */
    ['user' => $user, 'doctor' => $doctor] = daDoctorAccount([$this->sunu, $this->landak]);

    daGrantHomeLock($doctor, $this->sunu);
    daArmDoctorAccess();

    $this->actingAs($user);

    $patientsBefore = Patient::query()->count();
    $visitsBefore = ClinicVisit::query()->count();

    expect(fn () => app(ClinicVisitService::class)->create([
        'patient_mode' => 'new',
        'clinic_id' => $this->clinic->id,
        'doctor_id' => $doctor->id,
        'new_patient' => [
            'name' => 'Pasien Baru Landak',
            'branch_id' => $this->landak->id,
            'manual_rm_number' => '778899',
        ],
    ]))->toThrow(ValidationException::class);

    expect(Patient::query()->count())->toBe($patientsBefore)
        ->and(ClinicVisit::query()->count())->toBe($visitsBefore);
});

it('refuses an out-of-lock visit through the real registration POST', function () {
    /*
     * The service-level tests above prove the chokepoint. This one proves it is
     * actually REACHED by a request a doctor can really make.
     *
     * It needs the doctor ONLINE at the forged branch, because
     * StoreClinicVisitRequest::validateOnlineDoctor() refuses a doctor who is
     * not online at the submitted branch — a different guard that would
     * otherwise absorb the attempt and leave the lock untested. The
     * pre-lock-context ordering is the same production narrative as case 3.
     */
    ['user' => $user, 'doctor' => $doctor] = daDoctorAccount([$this->sunu, $this->landak]);
    dhblGoOnline($user, $this->landak);

    // A patient this doctor already treats, so the doctor patient scope behind
    // the selector cannot be the thing that refuses.
    $patient = Patient::factory()->create(['branch_id' => $this->landak->id, 'name' => 'Pasien LDK2']);
    dhblVisit($this->landak, $doctor, $patient);

    $treatment = Treatment::factory()->create(['is_active' => true]);

    daGrantHomeLock($doctor, $this->sunu);
    daArmDoctorAccess();

    $visitsBefore = ClinicVisit::query()->count();

    $this->actingAs($user)
        ->post(route('rme.visits.store'), [
            'branch_id' => $this->landak->id,
            'clinic_id' => $this->clinic->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'initial_treatment_id' => $treatment->id,
        ])
        ->assertSessionHasErrors('branch_id');

    // 'terkunci' is the lock's own word. Asserting it is what distinguishes
    // "the lock refused this" from "some validator refused this".
    expect(session('errors')->get('branch_id')[0])->toContain('terkunci')
        ->and(ClinicVisit::query()->count())->toBe($visitsBefore);
});

it('lets the same locked doctor write to their OWN branch', function () {
    // The positive control. Without it, every refusal above could be explained
    // by visit creation being broken for doctors in general.
    ['user' => $user, 'doctor' => $doctor] = daDoctorAccount([$this->sunu, $this->landak]);
    $patient = Patient::factory()->create(['branch_id' => $this->sunu->id, 'name' => 'Pasien SUN4']);

    daGrantHomeLock($doctor, $this->sunu);
    daArmDoctorAccess();

    $this->actingAs($user);

    $visit = app(ClinicVisitService::class)->create(
        dhblVisitPayload($doctor, $patient, (int) $this->sunu->id),
    );

    expect((int) $visit->branch_id)->toBe((int) $this->sunu->id)
        // And a legitimate write pays nothing into the audit trail.
        ->and(DB::table('sys_audit_logs')
            ->where('action', 'DOCTOR_EFFECTIVE_BRANCH_WRITE_REFUSED')
            ->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| THE LOCK IS A PROPERTY OF THE ACCOUNT, NOT OF THE SESSION OR THE DEVICE
|--------------------------------------------------------------------------
*/

it('keeps the lock across a logout and a fresh login', function () {
    ['user' => $user, 'doctor' => $doctor] = daDoctorAccount([$this->sunu, $this->landak]);

    daGrantHomeLock($doctor, $this->sunu);
    daArmDoctorAccess();

    // A REAL login: this is the one entry that fires Illuminate\Auth\Events\Login
    // and therefore the only one that claims a lease. The lock is already in
    // place, so the lease records SUN4 and the session is consistent from its
    // first request.
    daLoginPost($user);
    daAssertActiveLeaseCount(1, $user);

    expect((int) daCurrentLease($user)->effective_branch_id)->toBe((int) $this->sunu->id)
        ->and(dhblResolver()->branchIdFor($user))->toBe((int) $this->sunu->id);

    $this->post(route('logout'));

    // Logout hands the lease back (ruling C5), so the account is free to log in
    // again — and the lock is not part of what was handed back.
    daAssertActiveLeaseCount(0, $user);

    daLoginPost($user);
    daAssertActiveLeaseCount(1, $user);

    expect(dhblResolver()->branchIdFor($user))->toBe((int) $this->sunu->id)
        ->and(dhblScope()->operationalBranchIdsFor($user))->toBe([(int) $this->sunu->id]);

    // The operative consequence after a fresh login: the doctor may go online
    // at SUN4 and nowhere else. Logout marked them offline, so this is the
    // choice they are actually presented with.
    $landakRoom = ClinicRoom::factory()->create([
        'branch_id' => $this->landak->id,
        'status' => ClinicRoom::STATUS_ACTIVE,
    ]);

    expect(fn () => app(UserOnlineContextService::class)
        ->startDoctorSession($user, (int) $this->landak->id, (int) $landakRoom->id))
        ->toThrow(ValidationException::class);

    $sunuRoom = ClinicRoom::factory()->create([
        'branch_id' => $this->sunu->id,
        'status' => ClinicRoom::STATUS_ACTIVE,
    ]);

    app(UserOnlineContextService::class)
        ->startDoctorSession($user, (int) $this->sunu->id, (int) $sunuRoom->id);

    expect((int) UserOnlineContext::query()->where('user_id', $user->id)->value('branch_id'))
        ->toBe((int) $this->sunu->id);
});

it('keeps the lock when the doctor logs in from a different tablet', function () {
    ['user' => $user, 'doctor' => $doctor] = daDoctorAccount([$this->sunu, $this->landak]);

    $sunuTablet = dhblTablet($doctor, $this->sunu);

    daGrantHomeLock($doctor, $this->sunu);
    daArmDoctorAccess();

    daLoginPost($user);

    expect(dhblResolver()->branchIdFor($user))->toBe((int) $this->sunu->id);

    $this->post(route('logout'));

    // The doctor walks to Landak and picks up the tablet that lives there. It
    // is ACTIVE and authorised for them: a legitimate device, at a legitimate
    // practice branch, that is simply not their home branch.
    $landakTablet = dhblTablet($doctor, $this->landak);

    daLoginPost($user);
    daAssertActiveLeaseCount(1, $user);

    expect(dhblResolver()->branchIdFor($user))->toBe((int) $this->sunu->id)
        ->and(dhblScope()->operationalBranchIdsFor($user))->toBe([(int) $this->sunu->id])
        // Both tablets exist and are authorised. Neither has a vote.
        ->and((int) $sunuTablet->branch_id)->toBe((int) $this->sunu->id)
        ->and((int) $landakTablet->branch_id)->toBe((int) $this->landak->id)
        ->and(DoctorDeviceAuthorization::query()
            ->where('doctor_id', $doctor->id)
            ->where('status', DoctorDeviceAuthorization::STATUS_ACTIVE)
            ->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| DEGRADATION — A LOCK WHOSE BRANCH IS RETIRED
|--------------------------------------------------------------------------
|
| Ruling P9 / finding U3: an unusable lock must become UNSET-with-a-retained-
| home-branch, never an exception. BranchContext::requireId() would throw a
| RuntimeException on every write path, so the difference between degrading and
| throwing is the difference between a doctor who keeps working and a doctor who
| is stopped dead by a master-data toggle.
|
| The doctor is left online at LANDAK in both cases, because deactivating the
| branch they are standing in would ALSO invalidate their own online context and
| the redirect that follows would mask the behaviour under test.
*/

it('degrades to legacy behaviour when the locked branch loses is_active, and never evicts', function () {
    ['user' => $user, 'doctor' => $doctor] = daDoctorAccount([$this->sunu, $this->landak]);
    dhblGoOnline($user, $this->landak);

    dhblVisit($this->landak, $doctor);

    daGrantHomeLock($doctor, $this->sunu);
    daArmDoctorAccess();

    // Sanity: the lock IS applying before the branch is retired.
    expect(dhblScope()->operationalBranchIdsFor($user))->toBe([(int) $this->sunu->id]);

    $this->sunu->forceFill(['is_active' => false])->save();

    $effective = dhblResolver()->resolve($user);

    expect($effective->source())->toBe(DoctorEffectiveBranch::SOURCE_DEGRADED)
        ->and($effective->isDegraded())->toBeTrue()
        ->and($effective->isLocked())->toBeFalse()
        ->and($effective->degradedReason())->toBe(DoctorEffectiveBranch::REASON_HOME_BRANCH_NOT_RME_ENABLED)
        // THE HOME BRANCH IS RETAINED. Degrading is not forgetting: the
        // approver surface needs the id to explain why the lock stopped
        // applying, and the row itself is untouched.
        ->and($effective->homeBranchId())->toBe((int) $this->sunu->id)
        ->and($effective->branchId())->toBeNull()
        // Legacy behaviour resumes, byte for byte against the un-narrowed answer.
        ->and(dhblScope()->operationalBranchIdsFor($user))->toBe(dhblScope()->branchIdsFor($user))
        ->and(dhblScope()->operationalBranchIdsFor($user))->toContain((int) $this->landak->id);

    // NOT EVICTED: the request succeeds, and it is not the login redirect the
    // lease middleware issues when it tears a session down.
    $this->actingAs($user)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('Pasien LDK2');

    // The presence row survives, so the doctor still holds their clinic room.
    expect(UserOnlineContext::query()->where('user_id', $user->id)->value('status'))
        ->toBe(UserOnlineContext::STATUS_ONLINE);
});

it('degrades the same way when the locked branch loses is_rme_enabled, with no exception on a write path', function () {
    ['user' => $user, 'doctor' => $doctor] = daDoctorAccount([$this->sunu, $this->landak]);
    dhblGoOnline($user, $this->landak);

    $patient = Patient::factory()->create(['branch_id' => $this->landak->id, 'name' => 'Pasien LDK2']);

    daGrantHomeLock($doctor, $this->sunu);
    daArmDoctorAccess();

    $this->sunu->forceFill(['is_rme_enabled' => false])->save();

    expect(dhblResolver()->resolve($user)->degradedReason())
        ->toBe(DoctorEffectiveBranch::REASON_HOME_BRANCH_NOT_RME_ENABLED);

    $this->actingAs($user);

    /*
     * The write path, called directly and NOT wrapped in an expectation.
     * `expect(...)->not->toThrow()` would report "the closure threw" and hide
     * WHICH exception; calling it plainly makes the failure the real stack
     * trace — a RuntimeException from BranchContext::requireId() if the
     * degradation were ever turned back into an error.
     */
    $visit = app(ClinicVisitService::class)->create(
        dhblVisitPayload($doctor, $patient, (int) $this->landak->id),
    );

    expect((int) $visit->branch_id)->toBe((int) $this->landak->id)
        // A degraded lock refuses nothing, so it audits nothing.
        ->and(DB::table('sys_audit_logs')
            ->where('action', 'DOCTOR_EFFECTIVE_BRANCH_WRITE_REFUSED')
            ->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| FINDING V5 — HYBRID ACCOUNTS FAIL CLOSED TO AN EMPTY SCOPE
|--------------------------------------------------------------------------
|
| No owner decision names this case, so it is pinned here to make the
| fail-closed behaviour CHOSEN rather than inherited. An account that is both a
| lockable Doctor and a context-bound role gets the INTERSECTION of its online
| context branch and its locked branch. When the two disagree that intersection
| is EMPTY: such an account sees nothing until the disagreement is resolved,
| rather than a lock silently widening a pinned working context or a pinned
| context silently overriding a lock. Empty can only ever be narrower than
| either input, so the choice is structurally incapable of granting anything.
*/

it('gives a hybrid Doctor + Kasir account an EMPTY operational scope when context and lock disagree (finding V5)', function () {
    ['user' => $user, 'doctor' => $doctor] = daDoctorAccount([$this->sunu, $this->landak]);
    $user->assignRole('Kasir');
    $user->refresh();

    dhblVisit($this->sunu, $doctor);
    dhblVisit($this->landak, $doctor);

    // The cashier half is working at Landak; the clinical half is locked to Sunu.
    rmeMakeKasirActive($user, $this->landak);

    daGrantHomeLock($doctor, $this->sunu);
    daArmDoctorAccess();

    /*
     * THE INVARIANT IS ASSERTED AT THE LAYER THAT OWNS IT.
     *
     * `RmeWorkingBranchScope::operationalBranchIdsFor()` is where the
     * intersection is computed and therefore where V5 lives. It is asserted
     * directly, and NOT inferred from a rendered page — see the two HTTP
     * assertions further down, which document that a hybrid account never
     * reaches this scope over HTTP at all.
     */
    expect(dhblScope()->isContextBound($user))->toBeTrue()
        // The two inputs, each individually valid.
        ->and(dhblScope()->branchIdsFor($user))->toBe([(int) $this->landak->id])
        ->and(dhblResolver()->branchIdFor($user))->toBe((int) $this->sunu->id)
        // THE CHOSEN OUTCOME: empty, not either one of them.
        ->and(dhblScope()->operationalBranchIdsFor($user))->toBe([])
        ->and(dhblScope()->resolve($user))->toBe([])
        // A crafted filter cannot escape an empty scope either.
        ->and(dhblScope()->resolve($user, (int) $this->landak->id))->toBe([]);

    /*
     * PER-RECORD ACCESS IS WHAT branchIdsFor() SAYS, AND THE LOCK NEVER REACHES
     * IT. `allows()` is deliberately built on `branchIdsFor()` rather than on
     * `operationalBranchIdsFor()` — the class says so, and says not to "align"
     * them — so for THIS account it is exactly the Kasir context branch:
     *
     *   LANDAK  allowed, because the online context grants it and the doctor
     *           lock did not take it away. That is the half that keeps the empty
     *           list scope a narrowing of LISTS rather than a patient-safety
     *           defect: records stay openable.
     *   SUNU    NOT allowed, because per-record authorization is the CONTEXT and
     *           a home lock is not a grant. A lock that widened per-record access
     *           to its own branch would be the lock granting something, which
     *           operationalBranchIdsFor() is written as an intersection
     *           specifically to make impossible.
     *
     * Both directions are asserted, because asserting only the first would pass
     * just as well if the lock HAD widened per-record access to Sunu.
     */
    expect(dhblScope()->allows($user, (int) $this->landak->id))->toBeTrue()
        ->and(dhblScope()->allows($user, (int) $this->sunu->id))->toBeFalse();

    /*
     * OVER HTTP THIS ACCOUNT IS STOPPED EARLIER, BY A DIFFERENT GATE, AND THE
     * SCOPE ABOVE IS NEVER CONSULTED.
     *
     * `UserOnlineContextService::hasSatisfiedContext()` asks
     * `requiresDoctorContext()` FIRST, so a hybrid account holding a KASIR
     * context row is judged an unsatisfied DOCTOR and redirected to the branch
     * selector before any list renders. That is a SECOND, INDEPENDENT
     * fail-closed consequence of the disagreement, not the intersection under
     * test, and not a hole. Asserted here so that nobody reads the empty-scope
     * assertions above as a description of what a hybrid user sees in a browser.
     */
    $this->actingAs($user)
        ->get(route('rme.visits.index'))
        ->assertRedirect();

    /*
     * AND WITH THAT EARLIER GATE LIFTED — and only with it lifted — the empty
     * scope is what the list renders. The bypass is the only way to observe the
     * scope boundary through a request at all, which is precisely why the
     * assertions that matter are made against the scope directly.
     */
    $this->actingAs($user)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertDontSee('Pasien SUN4')
        ->assertDontSee('Pasien LDK2');
});

it('gives the same hybrid account its one branch when context and lock AGREE', function () {
    // Without this, the test above would also pass if the intersection were
    // unconditionally empty for every hybrid account.
    ['user' => $user, 'doctor' => $doctor] = daDoctorAccount([$this->sunu, $this->landak]);
    $user->assignRole('Kasir');
    $user->refresh();

    dhblVisit($this->sunu, $doctor);

    rmeMakeKasirActive($user, $this->sunu);

    daGrantHomeLock($doctor, $this->sunu);
    daArmDoctorAccess();

    expect(dhblScope()->operationalBranchIdsFor($user))->toBe([(int) $this->sunu->id]);

    // Same bypass, same reason as the disagreement case above.
    $this->actingAs($user)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('Pasien SUN4');
});

/*
|--------------------------------------------------------------------------
| FINDING V4 — A REPORT REQUEST FOR ANOTHER BRANCH NARROWS SILENTLY
|--------------------------------------------------------------------------
*/

it('SILENTLY NARROWS a locked doctor report request for another branch instead of refusing it (finding V4)', function () {
    /*
     * THE BEHAVIOUR THE CODE ACTUALLY HAS, NAMED IN THE TEST TITLE.
     *
     * RmeReportController composes two authorities:
     *   resolveBranchId()        -> RmeWorkingBranchScope::allows()   (NOT narrowed)
     *   reportScopeBranchIds()   -> RmeWorkingBranchScope::resolve()  (narrowed)
     *
     * So a locked doctor crafting `?branch_id=` for another RME branch PASSES
     * the first check and then receives their OWN branch's figures from the
     * second. There is no cross-branch leak; the requested branch simply has no
     * effect. Both halves are asserted here because the pair IS the behaviour —
     * asserting only the narrowing would leave "or it 403s" indistinguishable.
     *
     * Asserted at the two authorities rather than over HTTP because the Doctor
     * role holds neither `view_rme_patient_reports` nor
     * `view_rme_payment_reports`, so no doctor can reach the report route in
     * production. Granting a permission the role does not have would prove a
     * situation that does not exist.
     */
    ['user' => $user, 'doctor' => $doctor] = daDoctorAccount([$this->sunu, $this->landak]);

    daGrantHomeLock($doctor, $this->sunu);
    daArmDoctorAccess();

    expect(dhblScope()->allows($user, (int) $this->landak->id))->toBeTrue()
        ->and(dhblScope()->resolve($user, (int) $this->landak->id))->toBe([(int) $this->sunu->id])
        ->and(dhblScope()->resolve($user, (int) $this->sunu->id))->toBe([(int) $this->sunu->id]);
});

/*
|--------------------------------------------------------------------------
| OWNER DECISION O3 — ARCHIVE READS STAY CROSS-BRANCH
|--------------------------------------------------------------------------
|
| A PATIENT-SAFETY BOUNDARY, SO IT IS ASSERTED POSITIVELY. A doctor treating the
| patient in front of them at their own locked branch must still be able to read
| that patient's record and odontogram history from another branch. The
| patient-centric RM workspace anchors on a patient's EARLIEST visit, so a lock
| that reached per-record access would deny exactly the patients whose care
| started elsewhere.
*/

it('keeps archive reads CROSS-BRANCH for a locked doctor (owner decision O3)', function () {
    ['user' => $user, 'doctor' => $doctor] = daDoctorAccount([$this->sunu, $this->landak]);
    dhblGoOnline($user, $this->sunu);

    // One patient, two branches, one doctor: the patient started at Landak and
    // is in the chair at Sunu today.
    $patient = Patient::factory()->create(['branch_id' => $this->landak->id, 'name' => 'Pasien Lintas Cabang']);

    $landakVisit = dhblVisit($this->landak, $doctor, $patient, [
        'visit_date' => app(ClinicalClock::class)->today()->subMonths(6)->toDateString(),
    ]);

    $sunuVisit = dhblVisit($this->sunu, $doctor, $patient);

    Odontogram::factory()->create([
        'clinic_visit_id' => $landakVisit->id,
        'branch_id' => $this->landak->id,
        'tooth_map_payload' => ['teeth' => [['tooth' => '36', 'status' => 'caries']]],
    ]);

    daGrantHomeLock($doctor, $this->sunu);
    daArmDoctorAccess();

    // The lists ARE narrowed — this is the same doctor, same request, and the
    // contrast is the point of owner decision O3.
    expect(dhblScope()->operationalBranchIdsFor($user))->toBe([(int) $this->sunu->id]);

    // PER-RECORD access to the other branch is deliberately NOT narrowed.
    expect(dhblScope()->allows($user, (int) $this->landak->id))->toBeTrue();

    // The odontogram archive read reaches across the branch boundary.
    $history = app(OdontogramService::class)->patientHistoryForVisit($sunuVisit, $user);

    expect($history)->toHaveCount(1)
        ->and($history->first()['branch_code'])->toBe('LDK2')
        ->and((int) $history->first()['visit_id'])->toBe((int) $landakVisit->id);

    // And so does the patient's visit history, which is what the RM workspace
    // walks.
    expect(app(ClinicVisitService::class)
        ->patientVisitHistory((int) $patient->id)
        ->pluck('id')
        ->map(fn ($id) => (int) $id)
        ->all())
        ->toContain((int) $landakVisit->id);
});
