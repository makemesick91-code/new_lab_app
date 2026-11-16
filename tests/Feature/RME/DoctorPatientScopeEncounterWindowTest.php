<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M2.1/M2.2 — PR A1 (rule 243 §2)
|--------------------------------------------------------------------------
| The doctor ENCOUNTER scope, behind `rme.doctor_encounter_scope` (risky,
| default OFF, read only through FeatureFlagService — CG-08).
|
| Flag OFF: today's Sprint 66.2 relationship scope, exactly (assignment rows
| and any visit with the doctor grant access; refusals are 403; another
| doctor's encounter may be co-authored, OD-5-1).
|
| Flag ON:
|  - a visit is IN SERVICE while open (registered, waiting, in_progress,
|    cashier_pending) or completed TODAY on the clinical calendar (WITA);
|    cancelled never; DoctorEncounterWindow decides, with a SQL twin;
|  - read modes per patient: NONE / OWN / HISTORY — the doctor reads their own
|    visits at any date, and the patient's other NON-pre-exam visits (and their
|    documents) only while an own in-service encounter exists;
|  - assignment rows grant nothing (plan default 1);
|  - out-of-scope reads answer 404 (default 6), writes only on the doctor's own
|    in-service encounter (403 "ditangani dokter lain"), re-asserted in
|    RmeVisitConsentService::actorMayWorkOnEncounter and
|    PrescriptionAuthoringAuthority::permits;
|  - lists mirror the per-record checks; BranchContext and the rule 138 room
|    scope are unchanged; Owner, Super Admin and Supervisor RME stay exempt.
|
| Selected by the existing `DoctorPatientScope` critical-gate token in both
| runner variants and declared in critical_gate_mandatory_suites (CG-19).
| Helper prefix `dpew` is unique (the critical gate loads every suite at once).
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Patient\Models\Patient;
use App\Modules\Prescription\Models\RmePrescription;
use App\Modules\Prescription\Services\PrescriptionAuthoringAuthority;
use App\Modules\RME\Models\PatientDoctorAssignment;
use App\Modules\RME\Services\DoctorEncounterWindow;
use App\Modules\RME\Services\DoctorPatientScopeService;
use App\Modules\RME\Services\DoctorRoomScopeService;
use App\Services\Foundation\FeatureFlagService;
use App\Support\Storage\ClinicalEvidenceStorage;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

const DPEW_FLAG = 'rme.doctor_encounter_scope';

const DPEW_OTHER_DOCTOR = 'ditangani dokter lain';

beforeEach(function () {
    // 10:00 WITA on 2026-11-17 — well inside one clinical day in both zones.
    Carbon::setTestNow(Carbon::parse('2026-11-17 02:00:00', 'UTC'));

    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake(ClinicalEvidenceStorage::diskName());

    test()->dpewBranch = Branch::factory()->create(['code' => 'EW01', 'is_rme_enabled' => true]);
    test()->dpewRoom = ClinicRoom::factory()->create(['branch_id' => test()->dpewBranch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    test()->dpewOtherRoom = ClinicRoom::factory()->create(['branch_id' => test()->dpewBranch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $clinic = Clinic::factory()->create();

    // One doctor per room (a room is never shared). Doctor B's visits are
    // nevertheless created in A's room by default, so that from A's side the
    // rule 138 room scope admits them and only the encounter scope decides.
    foreach (['A' => test()->dpewRoom, 'B' => test()->dpewOtherRoom] as $name => $room) {
        $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'branch_id' => test()->dpewBranch->id]);
        $user = User::factory()->create()->assignRole('Doctor');
        $doctor->update(['user_id' => $user->id]);
        $doctor->branches()->sync([(int) test()->dpewBranch->id]);
        rmeMakeDoctorOnline($doctor, test()->dpewBranch, $room, $user);
        $user->givePermissionTo(['view_clinic_visits', 'manage_clinic_visits']);

        test()->{'dpewDoctor'.$name} = $doctor->refresh();
        test()->{'dpewUser'.$name} = $user->refresh();
    }

    test()->dpewPatient = Patient::factory()->create(['branch_id' => test()->dpewBranch->id]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function dpewFlag(bool $on): void
{
    $flags = config('feature_flags.flags', []);
    $flags[DPEW_FLAG]['default'] = $on;
    $flags[DPEW_FLAG]['env_value'] = null;
    config()->set('feature_flags.flags', $flags);
}

/** A visit of the fixture patient (unless overridden) in the doctors' room. */
function dpewVisit(Doctor $doctor, string $status, array $overrides = []): ClinicVisit
{
    return ClinicVisit::factory()->create(array_merge([
        'branch_id' => test()->dpewBranch->id,
        'patient_id' => test()->dpewPatient->id,
        'doctor_id' => $doctor->id,
        'clinic_room_id' => test()->dpewRoom->id,
        'status' => $status,
        'visit_date' => '2026-11-17',
    ], $overrides))->refresh();
}

function dpewCompleted(Doctor $doctor, string $completedAtUtc, array $overrides = []): ClinicVisit
{
    return dpewVisit($doctor, ClinicVisit::STATUS_COMPLETED, array_merge([
        'completed_at' => $completedAtUtc,
        'visit_date' => substr($completedAtUtc, 0, 10),
    ], $overrides));
}

function dpewScope(): DoctorPatientScopeService
{
    return app(DoctorPatientScopeService::class);
}

/** HTTP-equivalent status of a policy decision (null status on a denial is 403). */
function dpewStatus(User $user, string $ability, mixed $arguments): int
{
    $decision = Gate::forUser($user)->inspect($ability, $arguments);

    return $decision->allowed() ? 200 : ($decision->status() ?? 403);
}

/*
|--------------------------------------------------------------------------
| 0 — the switch
|--------------------------------------------------------------------------
*/

it('declares the encounter scope flag risky and OFF, read through FeatureFlagService', function () {
    $flags = app(FeatureFlagService::class);

    expect($flags->declaredDefault(DPEW_FLAG))->toBeFalse()
        ->and($flags->get(DPEW_FLAG)['risk_level'])->toBeIn(['high', 'critical'])
        ->and($flags->enabled(DPEW_FLAG))->toBeFalse()
        ->and(DoctorPatientScopeService::ENCOUNTER_SCOPE_FLAG)->toBe(DPEW_FLAG)
        ->and(dpewScope()->encounterScopeEnabled())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| A — the window (WITA, not the UTC date) and its SQL twin
|--------------------------------------------------------------------------
*/

it('puts open visits and visits completed today in service, never cancelled, yesterday or undated ones', function () {
    $window = app(DoctorEncounterWindow::class);
    $a = test()->dpewDoctorA;

    expect($window->isInService(dpewVisit($a, ClinicVisit::STATUS_REGISTERED)))->toBeTrue()
        ->and($window->isInService(dpewVisit($a, ClinicVisit::STATUS_WAITING)))->toBeTrue()
        ->and($window->isInService(dpewVisit($a, ClinicVisit::STATUS_IN_PROGRESS)))->toBeTrue()
        ->and($window->isInService(dpewVisit($a, ClinicVisit::STATUS_CASHIER_PENDING)))->toBeTrue()
        ->and($window->isInService(dpewCompleted($a, '2026-11-17 01:00:00')))->toBeTrue()
        ->and($window->isInService(dpewCompleted($a, '2026-11-16 08:00:00')))->toBeFalse()
        ->and($window->isInService(dpewVisit($a, ClinicVisit::STATUS_COMPLETED, ['completed_at' => null])))->toBeFalse()
        ->and($window->isInService(dpewVisit($a, ClinicVisit::STATUS_CANCELLED, ['cancelled_at' => '2026-11-17 01:00:00'])))->toBeFalse();
});

it('decides "today" on the clinical calendar at 00:30 WITA, where the UTC date disagrees', function () {
    // 2026-11-18 00:30 WITA = 2026-11-17 16:30 UTC.
    Carbon::setTestNow(Carbon::parse('2026-11-17 16:30:00', 'UTC'));
    $window = app(DoctorEncounterWindow::class);
    $a = test()->dpewDoctorA;

    // Both instants carry the UTC date 2026-11-17; only one is "today" in WITA.
    $lateLastNight = dpewCompleted($a, '2026-11-17 15:59:00');   // 23:59 WITA on the 17th
    $justAfterMidnight = dpewCompleted($a, '2026-11-17 16:10:00'); // 00:10 WITA on the 18th

    expect($window->isInService($lateLastNight))->toBeFalse()
        ->and($window->isInService($justAfterMidnight))->toBeTrue();
});

it('keeps the SQL twin the mirror image of the PHP window', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-17 16:30:00', 'UTC'));
    $window = app(DoctorEncounterWindow::class);
    $a = test()->dpewDoctorA;

    $visits = collect([
        dpewVisit($a, ClinicVisit::STATUS_REGISTERED),
        dpewVisit($a, ClinicVisit::STATUS_WAITING),
        dpewVisit($a, ClinicVisit::STATUS_IN_PROGRESS),
        dpewVisit($a, ClinicVisit::STATUS_CASHIER_PENDING),
        dpewVisit($a, ClinicVisit::STATUS_CANCELLED),
        dpewVisit($a, ClinicVisit::STATUS_COMPLETED, ['completed_at' => null]),
        dpewCompleted($a, '2026-11-17 15:59:00'),
        dpewCompleted($a, '2026-11-17 16:10:00'),
        dpewCompleted($a, '2026-11-18 15:59:59'),
        dpewCompleted($a, '2026-11-16 20:00:00'),
    ]);
    $deleted = dpewVisit($a, ClinicVisit::STATUS_IN_PROGRESS);
    $deleted->delete();

    $php = $visits->filter(fn (ClinicVisit $visit) => $window->isInService($visit))->pluck('id')->sort()->values()->all();
    $sql = $window->constrainInService(ClinicVisit::withTrashed())->pluck('trx_clinic_visits.id')->sort()->values()->all();

    expect($sql)->toBe($php)
        ->and($window->isInService($deleted->refresh()))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| B — flag OFF is today's behaviour, exactly
|--------------------------------------------------------------------------
*/

it('keeps the relationship scope, 403 refusals and co-authoring with the flag OFF', function () {
    dpewFlag(false);
    $a = test()->dpewUserA;
    $bLive = dpewVisit(test()->dpewDoctorB, ClinicVisit::STATUS_IN_PROGRESS);
    $stranger = Patient::factory()->create(['branch_id' => test()->dpewBranch->id]);
    $strangerVisit = dpewVisit(test()->dpewDoctorB, ClinicVisit::STATUS_COMPLETED, ['patient_id' => $stranger->id, 'completed_at' => '2025-01-01 03:00:00']);

    // An assignment row alone grants the patient (Sprint 66.2).
    PatientDoctorAssignment::factory()->create([
        'patient_id' => test()->dpewPatient->id,
        'doctor_id' => test()->dpewDoctorA->id,
        'unassigned_at' => null,
    ]);

    expect(dpewScope()->doctorCanAccessPatient($a, test()->dpewPatient))->toBeTrue()
        ->and(dpewStatus($a, 'view', $bLive))->toBe(200)
        ->and(dpewStatus($a, 'update', $bLive))->toBe(200)
        ->and(dpewStatus($a, 'view', $strangerVisit))->toBe(403)
        ->and(dpewScope()->applyVisitScopeForUser($a, ClinicVisit::query())->pluck('id')->all())->toContain($bLive->id)
        ->and(dpewScope()->readModeFor($a, (int) test()->dpewPatient->id))->toBe(DoctorPatientScopeService::READ_HISTORY)
        ->and(dpewScope()->readModeFor($a, (int) $stranger->id))->toBe(DoctorPatientScopeService::READ_NONE);
});

/*
|--------------------------------------------------------------------------
| C — reads with the flag ON
|--------------------------------------------------------------------------
*/

it('lets a doctor read their own visit at any date, with no history outside service', function () {
    dpewFlag(true);
    $a = test()->dpewUserA;
    $ownLastYear = dpewCompleted(test()->dpewDoctorA, '2025-06-01 03:00:00');
    $bLastMonth = dpewCompleted(test()->dpewDoctorB, '2026-10-01 03:00:00');

    expect(dpewScope()->readModeFor($a, (int) test()->dpewPatient->id))->toBe(DoctorPatientScopeService::READ_OWN)
        ->and(dpewStatus($a, 'view', $ownLastYear))->toBe(200)
        ->and(dpewStatus($a, 'view', $bLastMonth))->toBe(404)
        ->and(dpewScope()->doctorCanAccessPatient($a, test()->dpewPatient))->toBeFalse();

    $patientDecision = dpewScope()->authorizePatientAccess($a, test()->dpewPatient);
    expect($patientDecision->denied())->toBeTrue()->and($patientDecision->status())->toBe(404);

    $this->actingAs($a)->get(route('rme.visits.show', $ownLastYear))->assertOk();
    $this->actingAs($a)->get(route('rme.visits.show', $bLastMonth))->assertNotFound();
});

it('opens the patient history inside an own in-service encounter, documents included', function () {
    dpewFlag(true);
    $a = test()->dpewUserA;
    dpewVisit(test()->dpewDoctorA, ClinicVisit::STATUS_IN_PROGRESS);
    $bHistory = dpewCompleted(test()->dpewDoctorB, '2026-10-01 03:00:00');
    $consent = RmeVisitConsent::factory()->create(['clinic_visit_id' => $bHistory->id]);
    $odontogram = Odontogram::factory()->create(['clinic_visit_id' => $bHistory->id, 'branch_id' => test()->dpewBranch->id]);
    $prescription = RmePrescription::factory()->withStoredCanvases()->unsigned()->create([
        'clinic_visit_id' => $bHistory->id,
        'branch_id' => test()->dpewBranch->id,
        'patient_id' => test()->dpewPatient->id,
        'doctor_id' => test()->dpewDoctorB->id,
    ]);

    expect(dpewScope()->readModeFor($a, (int) test()->dpewPatient->id))->toBe(DoctorPatientScopeService::READ_HISTORY)
        ->and(dpewScope()->doctorCanAccessPatient($a, test()->dpewPatient))->toBeTrue()
        ->and(dpewStatus($a, 'view', $bHistory))->toBe(200)
        ->and(dpewStatus($a, 'view', $consent))->toBe(200)
        ->and(dpewStatus($a, 'view', $odontogram))->toBe(200)
        ->and(dpewStatus($a, 'view', $prescription))->toBe(200);

    $this->actingAs($a)->get(route('rme.visits.show', $bHistory))->assertOk();
});

it('keeps the window open for an encounter completed today and closes it for one completed yesterday', function () {
    dpewFlag(true);
    $a = test()->dpewUserA;
    $bHistory = dpewCompleted(test()->dpewDoctorB, '2026-10-01 03:00:00');
    $own = dpewCompleted(test()->dpewDoctorA, '2026-11-17 01:30:00'); // 09:30 WITA today

    expect(dpewStatus($a, 'view', $bHistory))->toBe(200);

    $own->forceFill(['completed_at' => '2026-11-16 09:00:00', 'visit_date' => '2026-11-16'])->save();

    // A new request: the memo is per routed request, and this is a direct call.
    expect(dpewScope()->readModeFor($a, (int) test()->dpewPatient->id))->toBe(DoctorPatientScopeService::READ_OWN)
        ->and(dpewStatus($a, 'view', $bHistory))->toBe(404);
});

it('never shows another doctor\'s pre-exam encounter, even inside an own in-service one', function (string $status) {
    dpewFlag(true);
    $a = test()->dpewUserA;
    dpewVisit(test()->dpewDoctorA, ClinicVisit::STATUS_CASHIER_PENDING);
    $bLive = dpewVisit(test()->dpewDoctorB, $status);

    expect(dpewStatus($a, 'view', $bLive))->toBe(404);
    $this->actingAs($a)->get(route('rme.visits.show', $bLive))->assertNotFound();
})->with([ClinicVisit::STATUS_REGISTERED, ClinicVisit::STATUS_WAITING, ClinicVisit::STATUS_IN_PROGRESS]);

it('never opens a window from a cancelled visit or from an assignment row', function (string $type) {
    dpewFlag(true);
    $a = test()->dpewUserA;
    $bHistory = dpewCompleted(test()->dpewDoctorB, '2026-10-01 03:00:00');
    dpewVisit(test()->dpewDoctorA, ClinicVisit::STATUS_CANCELLED, ['cancelled_at' => '2026-11-17 01:00:00']);

    PatientDoctorAssignment::factory()->create([
        'patient_id' => test()->dpewPatient->id,
        'doctor_id' => test()->dpewDoctorA->id,
        'assignment_type' => $type,
        'unassigned_at' => null,
    ]);

    expect(dpewScope()->readModeFor($a, (int) test()->dpewPatient->id))->toBe(DoctorPatientScopeService::READ_OWN)
        ->and(dpewStatus($a, 'view', $bHistory))->toBe(404)
        ->and(dpewScope()->doctorCanAccessPatient($a, test()->dpewPatient))->toBeFalse();

    // A patient the doctor never treated, with only an assignment row: NONE.
    $assignedOnly = Patient::factory()->create(['branch_id' => test()->dpewBranch->id]);
    PatientDoctorAssignment::factory()->create([
        'patient_id' => $assignedOnly->id,
        'doctor_id' => test()->dpewDoctorA->id,
        'assignment_type' => $type,
        'unassigned_at' => null,
    ]);

    expect(dpewScope()->readModeFor($a, (int) $assignedOnly->id))->toBe(DoctorPatientScopeService::READ_NONE);
})->with([PatientDoctorAssignment::TYPE_MANUAL, PatientDoctorAssignment::TYPE_SHARED, PatientDoctorAssignment::TYPE_AUTO_VISIT]);

/*
|--------------------------------------------------------------------------
| D — writes with the flag ON: the own in-service encounter only
|--------------------------------------------------------------------------
*/

it('lets a doctor write on their own in-service encounter', function () {
    dpewFlag(true);
    $a = test()->dpewUserA;
    $own = dpewVisit(test()->dpewDoctorA, ClinicVisit::STATUS_IN_PROGRESS);

    expect(dpewStatus($a, 'update', $own))->toBe(200)
        ->and(dpewStatus($a, 'transition', $own))->toBe(200)
        ->and(dpewStatus($a, 'completeExamination', $own))->toBe(200)
        ->and(dpewStatus($a, 'create', [RmePrescription::class, $own]))->toBe(200)
        ->and(dpewStatus($a, 'create', [RmeVisitConsent::class, $own]))->toBe(200)
        ->and(dpewScope()->doctorMayWriteVisit($a, $own))->toBeTrue()
        ->and(app(PrescriptionAuthoringAuthority::class)->permits($a, $own))->toBeTrue();
});

it('refuses writes on another doctor\'s encounter the doctor may read, with 403 "ditangani dokter lain"', function () {
    dpewFlag(true);
    $a = test()->dpewUserA;
    dpewVisit(test()->dpewDoctorA, ClinicVisit::STATUS_CASHIER_PENDING); // opens the history window
    $bAtCashier = dpewVisit(test()->dpewDoctorB, ClinicVisit::STATUS_CASHIER_PENDING);
    $odontogram = Odontogram::factory()->create(['clinic_visit_id' => $bAtCashier->id, 'branch_id' => test()->dpewBranch->id]);
    $prescription = RmePrescription::factory()->withStoredCanvases()->unsigned()->create([
        'clinic_visit_id' => $bAtCashier->id,
        'branch_id' => test()->dpewBranch->id,
        'patient_id' => test()->dpewPatient->id,
        'doctor_id' => test()->dpewDoctorB->id,
    ]);

    expect(dpewStatus($a, 'view', $bAtCashier))->toBe(200);

    foreach ([
        ['update', $bAtCashier],
        ['transition', $bAtCashier],
        ['completeExamination', $bAtCashier],
        ['create', [RmePrescription::class, $bAtCashier]],
        ['update', $prescription],
        ['create', [RmeVisitConsent::class, $bAtCashier]],
        ['author', [Odontogram::class, $bAtCashier]],
        ['update', $odontogram],
    ] as [$ability, $arguments]) {
        $decision = Gate::forUser($a)->inspect($ability, $arguments);

        expect($decision->denied())->toBeTrue("{$ability} must be refused")
            ->and($decision->status() ?? 403)->toBe(403)
            ->and((string) $decision->message())->toContain(DPEW_OTHER_DOCTOR);
    }

    expect(dpewScope()->doctorMayWriteVisit($a, $bAtCashier))->toBeFalse()
        ->and(app(PrescriptionAuthoringAuthority::class)->permits($a, $bAtCashier))->toBeFalse();

    $this->actingAs($a)
        ->post(route('rme.visits.transition', $bAtCashier), ['status' => ClinicVisit::STATUS_CANCELLED])
        ->assertForbidden();
});

it('refuses writes on the doctor\'s own encounter once it is out of service', function () {
    dpewFlag(true);
    $a = test()->dpewUserA;
    $ownYesterday = dpewCompleted(test()->dpewDoctorA, '2026-11-16 09:00:00');

    $decision = Gate::forUser($a)->inspect('update', $ownYesterday);

    expect($decision->denied())->toBeTrue()
        ->and($decision->status() ?? 403)->toBe(403)
        ->and(dpewScope()->doctorMayWriteVisit($a, $ownYesterday))->toBeFalse();
});

it('re-asserts the own-encounter rule in the RME authoring third layer even past the policy', function () {
    dpewFlag(true);
    $a = test()->dpewUserA;
    $b = test()->dpewUserB;
    dpewVisit(test()->dpewDoctorA, ClinicVisit::STATUS_CASHIER_PENDING); // A: history window only
    $bLive = dpewVisit(test()->dpewDoctorB, ClinicVisit::STATUS_IN_PROGRESS);
    RmeVisitConsent::factory()->create(['clinic_visit_id' => $bLive->id]);

    // The policy layer is bypassed for everyone: the service still decides.
    Gate::before(fn () => true);

    // The same calls answer true with the flag OFF (next test), so the refusal
    // here is the own-encounter rule and nothing else; the treating doctor's
    // own write right is pinned on the predicate (B works another room today).
    expect(app(RmeVisitConsentService::class)->canAuthorRmeForPatient((int) test()->dpewPatient->id, $a))->toBeFalse()
        ->and(app(PrescriptionAuthoringAuthority::class)->permits($a, $bLive))->toBeFalse()
        ->and(dpewScope()->doctorMayWriteVisit($a, $bLive))->toBeFalse()
        ->and(dpewScope()->doctorMayWriteVisit($b, $bLive))->toBeTrue();
});

it('keeps co-authoring in the RME third layer with the flag OFF (OD-5-1 unchanged)', function () {
    dpewFlag(false);
    $a = test()->dpewUserA;
    dpewVisit(test()->dpewDoctorA, ClinicVisit::STATUS_CASHIER_PENDING);
    $bLive = dpewVisit(test()->dpewDoctorB, ClinicVisit::STATUS_IN_PROGRESS);
    RmeVisitConsent::factory()->create(['clinic_visit_id' => $bLive->id]);

    expect(app(RmeVisitConsentService::class)->canAuthorRmeForPatient((int) test()->dpewPatient->id, $a))->toBeTrue()
        ->and(app(PrescriptionAuthoringAuthority::class)->permits($a, $bLive))->toBeTrue();
});

it('keeps Owner, Super Admin and Supervisor RME exempt from the encounter scope', function () {
    dpewFlag(true);
    $bLive = dpewVisit(test()->dpewDoctorB, ClinicVisit::STATUS_IN_PROGRESS);
    $supervisor = rmeClinicalAuthor();
    $owner = rmeClinicalReader();

    expect(dpewScope()->shouldApplyDoctorScope($supervisor))->toBeFalse()
        ->and(dpewScope()->readModeFor($supervisor, (int) test()->dpewPatient->id))->toBe(DoctorPatientScopeService::READ_HISTORY)
        ->and(dpewScope()->doctorMayWriteVisit($supervisor, $bLive))->toBeTrue()
        ->and(dpewScope()->doctorCanAccessVisit($owner, $bLive))->toBeTrue()
        ->and(dpewScope()->doctorCanAccessVisit(superAdmin(), $bLive))->toBeTrue();

    $this->actingAs(superAdmin())->get(route('rme.visits.show', $bLive))->assertOk();
});

/*
|--------------------------------------------------------------------------
| E — lists mirror the per-record checks
|--------------------------------------------------------------------------
*/

it('lists exactly the visits and patients the per-record checks admit', function (bool $inService) {
    dpewFlag(true);
    $a = test()->dpewUserA;
    $other = Patient::factory()->create(['branch_id' => test()->dpewBranch->id]);
    $own = $inService
        ? dpewVisit(test()->dpewDoctorA, ClinicVisit::STATUS_IN_PROGRESS)
        : dpewCompleted(test()->dpewDoctorA, '2026-11-16 09:00:00');

    $visits = collect([
        $own,
        dpewCompleted(test()->dpewDoctorA, '2025-01-01 03:00:00'),
        dpewCompleted(test()->dpewDoctorB, '2026-10-01 03:00:00'),
        dpewVisit(test()->dpewDoctorB, ClinicVisit::STATUS_CASHIER_PENDING),
        dpewVisit(test()->dpewDoctorB, ClinicVisit::STATUS_CANCELLED),
        dpewVisit(test()->dpewDoctorB, ClinicVisit::STATUS_WAITING),
        dpewVisit(test()->dpewDoctorB, ClinicVisit::STATUS_COMPLETED, ['patient_id' => $other->id, 'completed_at' => '2026-10-02 03:00:00']),
    ]);

    $listed = dpewScope()->applyVisitScopeForUser($a, ClinicVisit::query())->pluck('id')->sort()->values()->all();
    $admitted = $visits->filter(fn (ClinicVisit $visit) => dpewScope()->doctorCanAccessVisit($a, $visit))->pluck('id')->sort()->values()->all();

    $patients = collect([test()->dpewPatient, $other]);
    $listedPatients = dpewScope()->applyPatientScopeForUser($a, Patient::query())->pluck('id')->sort()->values()->all();
    $admittedPatients = $patients->filter(fn (Patient $patient) => dpewScope()->doctorCanAccessPatient($a, $patient))->pluck('id')->sort()->values()->all();

    expect($listed)->toBe($admitted)
        ->and($listedPatients)->toBe($admittedPatients)
        ->and($listed)->toContain($own->id)
        ->and(count($admitted))->toBe($inService ? 5 : 2);
})->with(['inside an own in-service encounter' => [true], 'outside any service' => [false]]);

it('shows the doctor the same rows on Daftar Kunjungan that each row would open', function () {
    dpewFlag(true);
    $a = test()->dpewUserA;
    $own = dpewVisit(test()->dpewDoctorA, ClinicVisit::STATUS_IN_PROGRESS);
    $bHistory = dpewCompleted(test()->dpewDoctorB, '2026-11-10 03:00:00', ['visit_date' => '2026-11-10']);
    $bLive = dpewVisit(test()->dpewDoctorB, ClinicVisit::STATUS_WAITING);

    $html = $this->actingAs($a)->get(route('rme.visits.index', ['date_from' => '2026-11-01', 'date_to' => '2026-11-30']))->assertOk()->getContent();

    expect($html)->toContain($own->visit_number)
        ->and($html)->toContain($bHistory->visit_number)
        ->and($html)->not->toContain($bLive->visit_number);
});

/*
|--------------------------------------------------------------------------
| F — one window query per patient per routed request
|--------------------------------------------------------------------------
*/

it('memoises the read mode per patient for the routed request only', function () {
    dpewFlag(true);
    dpewVisit(test()->dpewDoctorA, ClinicVisit::STATUS_IN_PROGRESS);
    $history = collect(range(1, 4))->map(fn (int $i) => dpewCompleted(test()->dpewDoctorB, sprintf('2026-10-0%d 03:00:00', $i)));
    $a = test()->dpewUserA;

    $windowQueries = 0;
    DB::listen(function (QueryExecuted $query) use (&$windowQueries): void {
        if (str_contains($query->sql, 'completed_at') && str_contains($query->sql, 'trx_clinic_visits')) {
            $windowQueries++;
        }
    });

    Route::middleware('web')->get('/__dpew-memo', function () use ($history, $a) {
        foreach ($history as $visit) {
            abort_unless(app(DoctorPatientScopeService::class)->doctorCanAccessVisit($a, $visit), 500);
        }

        return 'ok';
    });

    $this->actingAs($a)->get('/__dpew-memo')->assertOk();

    expect($windowQueries)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| G — BranchContext and the rule 138 room scope are unchanged
|--------------------------------------------------------------------------
*/

it('leaves BranchContext and the room scope exactly as they were', function () {
    $a = test()->dpewUserA;
    $bOtherRoom = dpewVisit(test()->dpewDoctorB, ClinicVisit::STATUS_IN_PROGRESS, ['clinic_room_id' => test()->dpewOtherRoom->id]);

    dpewFlag(false);
    $branchOff = app(BranchContext::class)->forUser($a);
    $roomOff = app(DoctorRoomScopeService::class)->authorizeActiveVisitRoom($a, $bOtherRoom);
    $roomSqlOff = app(DoctorRoomScopeService::class)->applyRoomScope($a, ClinicVisit::query())->toRawSql();

    dpewFlag(true);
    $roomOn = app(DoctorRoomScopeService::class)->authorizeActiveVisitRoom($a, $bOtherRoom);

    expect(app(BranchContext::class)->forUser($a))->toBe($branchOff)
        ->and($roomOn->message())->toBe($roomOff->message())
        ->and(app(DoctorRoomScopeService::class)->applyRoomScope($a, ClinicVisit::query())->toRawSql())->toBe($roomSqlOff);

    // The room rule still answers first, with its own 403.
    $decision = Gate::forUser($a)->inspect('view', $bOtherRoom);
    expect($decision->status() ?? 403)->toBe(403)
        ->and($decision->message())->toBe($roomOff->message());
});

it('keeps the unlinked-doctor refusal a 403 with its own message', function () {
    dpewFlag(true);
    $unlinked = User::factory()->create()->assignRole('Doctor');
    $visit = dpewVisit(test()->dpewDoctorB, ClinicVisit::STATUS_COMPLETED, ['completed_at' => '2026-10-01 03:00:00']);

    $decision = dpewScope()->authorizeVisitAccess($unlinked, $visit);

    expect($decision->denied())->toBeTrue()
        ->and($decision->status())->toBeNull()
        ->and($decision->message())->toBe('Akun dokter belum terhubung ke Master Dokter.');
});
