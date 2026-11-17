<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M2.1/M2.2 — PR A4 (rule 243 §2.12)
|--------------------------------------------------------------------------
| Revocation, and the A3 re-review preconditions (a)(b)(c) of §2.8 item 8,
| under `rme.doctor_encounter_scope`:
|  - with the flag ON, `ClinicVisitService::assignRoom()` changes the visit's
|    doctor only while it is `registered` or `waiting`; an `in_progress` visit
|    moves room only (rule 228 §4 extended, owner default 2), Super Admin
|    included (the rule lives in the service, past `Gate::before`);
|  - with the flag ON, a doctor change or a cancel ends the former doctor's
|    `auto_visit` assignment for that patient (unassigned_at + reason code)
|    inside the same transaction, unless that doctor is still the doctor of
|    another live visit of the patient, audited with ids only; manual/shared
|    rows are never touched;
|  - (a) at the workspace anchor the room gate chooses the doctor's working
|    visit on the server; (b) the workflow tabs never show an unreadable
|    anchor; (c) another doctor's roomless pre-exam visit answers 404, not the
|    room redirect;
|  - with the flag OFF every one of these is exactly today's behaviour.
|
| Selected by the existing `DoctorPatientScope` critical-gate token in both
| runner variants; declared in critical_gate_mandatory_suites. Helper prefix
| `dpsr` is unique (the critical gate loads every suite into one process).
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwritingPage;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Models\PatientDoctorAssignment;
use App\Modules\RME\Services\PatientDoctorAssignmentService;
use App\Support\Storage\ClinicalEvidenceStorage;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

const DPSR_FLAG = 'rme.doctor_encounter_scope';

const DPSR_REVOKED = 'PATIENT_DOCTOR_ASSIGNMENT_REVOKED';

/** The tooltip of the locked workflow tabs of a roomless visit. */
const DPSR_ROOMLESS_TABS = 'Pasien belum ditempatkan ke ruangan perawatan';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-18 02:00:00', 'UTC')); // 10:00 WITA

    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake(ClinicalEvidenceStorage::diskName());
    Storage::fake('public');

    test()->dpsrBranch = Branch::factory()->create(['code' => 'SR01', 'is_rme_enabled' => true]);
    $clinic = Clinic::factory()->create();

    foreach (['A', 'B', 'C'] as $name) {
        test()->{'dpsrRoom'.$name} = ClinicRoom::factory()->create(['branch_id' => test()->dpsrBranch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    }

    // Doctor A works room A, doctor B room B; room C has no doctor of its own
    // until a test puts one there.
    foreach (['A', 'B'] as $name) {
        $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'branch_id' => test()->dpsrBranch->id, 'name' => 'drg. Rahasia Dokter '.$name]);
        $user = User::factory()->create()->assignRole('Doctor');
        $doctor->update(['user_id' => $user->id]);
        $doctor->branches()->sync([(int) test()->dpsrBranch->id]);
        rmeMakeDoctorOnline($doctor, test()->dpsrBranch, test()->{'dpsrRoom'.$name}, $user);
        $user->givePermissionTo(['view_clinic_visits', 'manage_clinic_visits']);

        test()->{'dpsrDoctor'.$name} = $doctor->refresh();
        test()->{'dpsrUser'.$name} = $user->refresh();
    }

    $admin = User::factory()->create()->assignRole('Admin Klinik');
    $admin->givePermissionTo(['manage_clinic_visits', 'view_clinic_visits']);
    rmeMakeAdminClinicActive($admin, test()->dpsrBranch);
    test()->dpsrAdmin = $admin->refresh();

    test()->dpsrPatient = Patient::factory()->create([
        'branch_id' => test()->dpsrBranch->id,
        'name' => 'Pasien Rahasia Revokasi',
        'ktp_number' => '7371010101010001',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function dpsrFlag(bool $on): void
{
    $flags = config('feature_flags.flags', []);
    $flags[DPSR_FLAG]['default'] = $on;
    $flags[DPSR_FLAG]['env_value'] = null;
    config()->set('feature_flags.flags', $flags);
}

function dpsrVisit(Doctor $doctor, string $status, array $overrides = []): ClinicVisit
{
    return ClinicVisit::factory()->create(array_merge([
        'branch_id' => test()->dpsrBranch->id,
        'patient_id' => test()->dpsrPatient->id,
        'doctor_id' => $doctor->id,
        'clinic_room_id' => test()->dpsrRoomA->id,
        'status' => $status,
        'visit_date' => '2026-11-18',
    ], $overrides))->refresh();
}

/** The auto_visit row the queue placement of $visit wrote (the real producer). */
function dpsrPlaced(ClinicVisit $visit): PatientDoctorAssignment
{
    return app(PatientDoctorAssignmentService::class)->ensureAssignedFromVisit($visit, test()->dpsrAdmin);
}

function dpsrActiveRow(Doctor $doctor, ?Patient $patient = null): ?PatientDoctorAssignment
{
    return PatientDoctorAssignment::query()
        ->where('patient_id', ($patient ?? test()->dpsrPatient)->id)
        ->where('doctor_id', $doctor->id)
        ->whereNull('unassigned_at')
        ->first();
}

function dpsrMove(User $actor, ClinicVisit $visit, ClinicRoom $room): void
{
    test()->actingAs($actor)
        ->from(route('rme.patient-queue.index'))
        ->patch(route('rme.visits.assign-room', $visit), ['clinic_room_id' => $room->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
}

function dpsrRevocations(): Collection
{
    return AuditLog::query()->where('action', DPSR_REVOKED)->orderBy('id')->get();
}

/*
|--------------------------------------------------------------------------
| 1 — the doctor change and its revocation
|--------------------------------------------------------------------------
*/

it('R-01 changes the doctor of a queued visit and ends the former doctor\'s auto_visit row (flag ON)', function (string $status) {
    dpsrFlag(true);
    $visit = dpsrVisit(test()->dpsrDoctorA, $status);
    $row = dpsrPlaced($visit);

    dpsrMove(test()->dpsrAdmin, $visit, test()->dpsrRoomB);

    $fresh = $visit->fresh();
    $row->refresh();

    expect((int) $fresh->doctor_id)->toBe((int) test()->dpsrDoctorB->id)
        ->and((int) $fresh->clinic_room_id)->toBe((int) test()->dpsrRoomB->id)
        ->and($row->unassigned_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and($row->unassigned_reason)->toBe('visit_doctor_changed')
        ->and(dpsrActiveRow(test()->dpsrDoctorA))->toBeNull()
        ->and(dpsrActiveRow(test()->dpsrDoctorB)?->assignment_type)->toBe(PatientDoctorAssignment::TYPE_AUTO_VISIT)
        ->and(dpsrRevocations())->toHaveCount(1);
})->with(['registered' => [ClinicVisit::STATUS_REGISTERED], 'waiting' => [ClinicVisit::STATUS_WAITING]]);

it('R-02 changes the doctor and keeps the former doctor\'s row exactly as today with the flag OFF', function (string $status) {
    dpsrFlag(false);
    $visit = dpsrVisit(test()->dpsrDoctorA, $status);
    $row = dpsrPlaced($visit);

    dpsrMove(test()->dpsrAdmin, $visit, test()->dpsrRoomB);

    expect((int) $visit->fresh()->doctor_id)->toBe((int) test()->dpsrDoctorB->id)
        ->and($row->fresh()->unassigned_at)->toBeNull()
        ->and($row->fresh()->unassigned_reason)->toBeNull()
        ->and(dpsrRevocations())->toHaveCount(0);
})->with(['registered' => [ClinicVisit::STATUS_REGISTERED], 'waiting' => [ClinicVisit::STATUS_WAITING]]);

it('R-03 moves an in_progress visit\'s room only with the flag ON, and changes its doctor as today with it OFF', function (bool $on) {
    dpsrFlag($on);
    $visit = dpsrVisit(test()->dpsrDoctorA, ClinicVisit::STATUS_IN_PROGRESS);
    $row = dpsrPlaced($visit);

    dpsrMove(test()->dpsrAdmin, $visit, test()->dpsrRoomB);

    $fresh = $visit->fresh();

    expect((int) $fresh->clinic_room_id)->toBe((int) test()->dpsrRoomB->id)
        ->and((int) $fresh->doctor_id)->toBe((int) ($on ? test()->dpsrDoctorA->id : test()->dpsrDoctorB->id))
        ->and($row->fresh()->unassigned_at)->toBeNull()
        ->and(dpsrActiveRow(test()->dpsrDoctorB) === null)->toBe($on)
        ->and(dpsrRevocations())->toHaveCount(0);
})->with(['flag ON' => [true], 'flag OFF' => [false]]);

it('R-04 keeps a cashier_pending visit\'s doctor whatever the flag (rule 228 §4 unchanged)', function (bool $on) {
    dpsrFlag($on);
    $visit = dpsrVisit(test()->dpsrDoctorA, ClinicVisit::STATUS_CASHIER_PENDING);
    $row = dpsrPlaced($visit);

    dpsrMove(test()->dpsrAdmin, $visit, test()->dpsrRoomB);

    expect((int) $visit->fresh()->doctor_id)->toBe((int) test()->dpsrDoctorA->id)
        ->and($row->fresh()->unassigned_at)->toBeNull()
        ->and(dpsrRevocations())->toHaveCount(0);
})->with(['flag ON' => [true], 'flag OFF' => [false]]);

it('R-05 ends the doctor\'s auto_visit row when the visit is cancelled (flag ON), and keeps it with the flag OFF', function (bool $on, string $status) {
    dpsrFlag($on);
    $visit = dpsrVisit(test()->dpsrDoctorA, $status);
    $row = dpsrPlaced($visit);

    test()->actingAs(test()->dpsrAdmin)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_CANCELLED])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $row->refresh();

    expect($visit->fresh()->status)->toBe(ClinicVisit::STATUS_CANCELLED)
        ->and($row->unassigned_at !== null)->toBe($on)
        ->and($row->unassigned_reason)->toBe($on ? 'visit_cancelled' : null)
        ->and(dpsrRevocations())->toHaveCount($on ? 1 : 0);
})->with(['flag ON' => [true], 'flag OFF' => [false]])
    ->with(['registered' => [ClinicVisit::STATUS_REGISTERED], 'waiting' => [ClinicVisit::STATUS_WAITING]]);

it('R-06 keeps the former doctor\'s row while that doctor is still the doctor of another visit of the patient', function () {
    dpsrFlag(true);
    // A examined the patient last month; today's queue visit moves to B.
    dpsrVisit(test()->dpsrDoctorA, ClinicVisit::STATUS_COMPLETED, ['visit_date' => '2026-10-01', 'completed_at' => '2026-10-01 03:00:00']);
    $visit = dpsrVisit(test()->dpsrDoctorA, ClinicVisit::STATUS_WAITING);
    $row = dpsrPlaced($visit);

    dpsrMove(test()->dpsrAdmin, $visit, test()->dpsrRoomB);

    expect((int) $visit->fresh()->doctor_id)->toBe((int) test()->dpsrDoctorB->id)
        ->and($row->fresh()->unassigned_at)->toBeNull()
        ->and(dpsrRevocations())->toHaveCount(0);
});

it('R-07 never ends a manual or shared row, nor a row of another patient', function () {
    dpsrFlag(true);
    $service = app(PatientDoctorAssignmentService::class);
    $visit = dpsrVisit(test()->dpsrDoctorA, ClinicVisit::STATUS_WAITING);
    // A's only row for this patient is MANUAL (plan default 1: it grants nothing
    // with the flag ON, and revocation is about the queue's auto rows only).
    $manual = $service->assignPatientToDoctor(test()->dpsrPatient, test()->dpsrDoctorA, test()->dpsrAdmin, test()->dpsrBranch);
    $other = Patient::factory()->create(['branch_id' => test()->dpsrBranch->id]);
    $otherVisit = dpsrVisit(test()->dpsrDoctorA, ClinicVisit::STATUS_WAITING, ['patient_id' => $other->id]);
    $otherRow = dpsrPlaced($otherVisit);

    dpsrMove(test()->dpsrAdmin, $visit, test()->dpsrRoomB);

    expect($manual->fresh()->unassigned_at)->toBeNull()
        ->and($otherRow->fresh()->unassigned_at)->toBeNull()
        ->and(dpsrRevocations())->toHaveCount(0);
});

it('R-08 binds Super Admin in the service: in_progress moves room only, a doctor change revokes', function () {
    dpsrFlag(true);
    $root = superAdmin();
    $service = app(ClinicVisitService::class);

    $live = dpsrVisit(test()->dpsrDoctorA, ClinicVisit::STATUS_IN_PROGRESS);
    $service->assignRoom($live, (int) test()->dpsrRoomB->id, $root);

    expect((int) $live->fresh()->doctor_id)->toBe((int) test()->dpsrDoctorA->id)
        ->and((int) $live->fresh()->clinic_room_id)->toBe((int) test()->dpsrRoomB->id);

    $other = Patient::factory()->create(['branch_id' => test()->dpsrBranch->id]);
    $queued = dpsrVisit(test()->dpsrDoctorA, ClinicVisit::STATUS_WAITING, ['patient_id' => $other->id]);
    $row = dpsrPlaced($queued);
    $service->assignRoom($queued, (int) test()->dpsrRoomB->id, $root);

    expect((int) $queued->fresh()->doctor_id)->toBe((int) test()->dpsrDoctorB->id)
        ->and($row->fresh()->unassigned_reason)->toBe('visit_doctor_changed')
        ->and((int) dpsrRevocations()->first()->performed_by)->toBe((int) $root->id);
});

it('R-09 audits a revocation with ids and a reason code only', function () {
    dpsrFlag(true);
    $visit = dpsrVisit(test()->dpsrDoctorA, ClinicVisit::STATUS_WAITING);
    $row = dpsrPlaced($visit);

    dpsrMove(test()->dpsrAdmin, $visit, test()->dpsrRoomB);

    $audit = dpsrRevocations()->sole();
    $payload = json_encode([$audit->old_values, $audit->new_values]);

    expect($audit->entity_type)->toBe('trx_rme_patient_doctor_assignments')
        ->and((int) $audit->entity_id)->toBe((int) $row->id)
        ->and((int) $audit->performed_by)->toBe((int) test()->dpsrAdmin->id)
        ->and($audit->old_values)->toBeNull()
        // Exactly these keys and values (jsonb does not keep key order).
        ->and(collect($audit->new_values)->sortKeys()->all())->toBe(collect([
            'clinic_visit_id' => (int) $visit->id,
            'patient_id' => (int) test()->dpsrPatient->id,
            'doctor_id' => (int) test()->dpsrDoctorA->id,
            'replacement_doctor_id' => (int) test()->dpsrDoctorB->id,
            'reason' => 'visit_doctor_changed',
        ])->sortKeys()->all())
        ->and($payload)->not->toContain('Rahasia')
        ->and($payload)->not->toContain('7371010101010001')
        ->and($payload)->not->toContain((string) $visit->visit_number);
});

it('R-10 keeps the visit move and the revocation one unit: a refused move ends nothing', function () {
    dpsrFlag(true);
    $visit = dpsrVisit(test()->dpsrDoctorA, ClinicVisit::STATUS_WAITING);
    $row = dpsrPlaced($visit);
    // Cancelled behind the caller's back: the locked re-check refuses the move.
    ClinicVisit::query()->whereKey($visit->id)->update(['status' => ClinicVisit::STATUS_CANCELLED]);

    expect(fn () => app(ClinicVisitService::class)->assignRoom($visit, (int) test()->dpsrRoomB->id, test()->dpsrAdmin))
        ->toThrow(ValidationException::class);

    expect($row->fresh()->unassigned_at)->toBeNull()
        ->and(dpsrRevocations())->toHaveCount(0);
});

/*
|--------------------------------------------------------------------------
| 2 — the A3 re-review preconditions (rule 243 §2.8 item 8 (a)(b)(c))
|--------------------------------------------------------------------------
*/

/** Doctor B's roomless registered visit last week: the patient's canonical anchor. */
function dpsrRoomlessAnchor(): ClinicVisit
{
    return dpsrVisit(test()->dpsrDoctorB, ClinicVisit::STATUS_REGISTERED, ['visit_date' => '2026-11-10', 'clinic_room_id' => null]);
}

function dpsrSheetWithPage(ClinicVisit $visit): MedicalRecord
{
    $sheet = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
        'canonical_visit_id' => $visit->id,
    ])->refresh();

    MedicalRecordHandwritingPage::query()->create([
        'medical_record_id' => $sheet->id,
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'doctor_id' => $visit->doctor_id,
        'page_number' => 1,
        'handwriting_path' => sprintf('rme/handwriting/dpsr-%d-1.png', $sheet->id),
        'handwriting_hash' => str_repeat('d', 64),
        'saved_at' => now(),
    ]);

    return $sheet;
}

it('(a)-01 gates the doctor\'s own roomless visit at the anchor address without any source_visit_id (flag ON)', function () {
    dpsrFlag(true);
    $anchor = dpsrRoomlessAnchor();
    $own = dpsrVisit(test()->dpsrDoctorA, ClinicVisit::STATUS_REGISTERED, ['clinic_room_id' => null]);

    test()->actingAs(test()->dpsrUserA)
        ->get(route('rme.visits.medical-record.show', $anchor))
        ->assertRedirect(route('rme.visits.show', $own));
});

it('(a)-02 never lets a source_visit_id of another doctor\'s readable visit steer the gate away (flag ON)', function () {
    dpsrFlag(true);
    $anchor = dpsrRoomlessAnchor();
    $readable = dpsrVisit(test()->dpsrDoctorB, ClinicVisit::STATUS_COMPLETED, ['visit_date' => '2026-11-12', 'completed_at' => '2026-11-12 03:00:00']);
    $own = dpsrVisit(test()->dpsrDoctorA, ClinicVisit::STATUS_REGISTERED, ['clinic_room_id' => null]);

    test()->actingAs(test()->dpsrUserA)
        ->get(route('rme.visits.medical-record.show', [$anchor, 'source_visit_id' => $readable->id]))
        ->assertRedirect(route('rme.visits.show', $own));
});

it('(a)-03 gates the doctor\'s own live encounter first, whatever source_visit_id names (flag ON)', function () {
    dpsrFlag(true);
    $anchor = dpsrRoomlessAnchor();
    $live = dpsrVisit(test()->dpsrDoctorA, ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($live);
    $roomless = dpsrVisit(test()->dpsrDoctorA, ClinicVisit::STATUS_REGISTERED, ['clinic_room_id' => null]);

    test()->actingAs(test()->dpsrUserA)
        ->get(route('rme.visits.medical-record.show', [$anchor, 'source_visit_id' => $roomless->id]))
        ->assertOk();
});

it('(a)-04 lets source_visit_id choose only among the doctor\'s own pre-exam visits (flag ON)', function () {
    dpsrFlag(true);
    $anchor = dpsrRoomlessAnchor();
    $olderRoomless = dpsrVisit(test()->dpsrDoctorA, ClinicVisit::STATUS_REGISTERED, ['visit_date' => '2026-11-15', 'clinic_room_id' => null]);
    dpsrVisit(test()->dpsrDoctorA, ClinicVisit::STATUS_WAITING); // today, placed

    // Server default: the latest own pre-exam visit (placed) — not gated.
    test()->actingAs(test()->dpsrUserA)
        ->get(route('rme.visits.medical-record.show', $anchor))
        ->assertOk();

    // The doctor names their own roomless visit: that one is gated.
    test()->actingAs(test()->dpsrUserA)
        ->get(route('rme.visits.medical-record.show', [$anchor, 'source_visit_id' => $olderRoomless->id]))
        ->assertRedirect(route('rme.visits.show', $olderRoomless));
});

it('(a)-05 keeps bouncing on the roomless anchor itself with the flag OFF', function () {
    dpsrFlag(false);
    $anchor = dpsrRoomlessAnchor();
    dpsrVisit(test()->dpsrDoctorA, ClinicVisit::STATUS_REGISTERED, ['clinic_room_id' => null]);

    test()->actingAs(test()->dpsrUserA)
        ->get(route('rme.visits.medical-record.show', $anchor))
        ->assertRedirect(route('rme.visits.show', $anchor));
});

it('(b)-01 renders the workflow tabs for the source visit when the anchor is unreadable (flag ON)', function () {
    dpsrFlag(true);
    $anchor = dpsrRoomlessAnchor();
    dpsrSheetWithPage($anchor); // the book, stored on B's roomless anchor
    $own = dpsrVisit(test()->dpsrDoctorA, ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($own);

    $response = test()->actingAs(test()->dpsrUserA)
        ->get(route('rme.visits.medical-record.show', [$anchor, 'source_visit_id' => $own->id]))
        ->assertOk()
        ->assertViewIs('rme.visits.medical-record.show');

    expect($response->viewData('anchorContentReadable'))->toBeFalse()
        ->and((int) $response->viewData('workflowNavVisit')?->id)->toBe((int) $own->id)
        ->and($response->getContent())->not->toContain(DPSR_ROOMLESS_TABS)
        ->and($response->getContent())->toContain(route('rme.visits.odontogram.show', $own));
});

it('(b)-02 omits the workflow tabs when the anchor is unreadable and there is no source visit (flag ON)', function () {
    dpsrFlag(true);
    $anchor = dpsrRoomlessAnchor();
    dpsrSheetWithPage($anchor);
    $own = dpsrVisit(test()->dpsrDoctorA, ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($own);

    $response = test()->actingAs(test()->dpsrUserA)
        ->get(route('rme.visits.medical-record.show', $anchor))
        ->assertOk();

    expect($response->viewData('workflowNavVisit'))->toBeNull()
        ->and($response->getContent())->not->toContain('Navigasi RME kunjungan')
        ->and($response->getContent())->not->toContain(DPSR_ROOMLESS_TABS);
});

it('(b)-03 renders the workflow tabs for the active sheet\'s visit exactly as before with the flag OFF', function () {
    dpsrFlag(false);
    // A placed anchor in A's room: readable with the flag OFF (rule 138 admits it).
    $anchor = dpsrVisit(test()->dpsrDoctorB, ClinicVisit::STATUS_REGISTERED, ['visit_date' => '2026-11-10']);
    dpsrSheetWithPage($anchor);
    $own = dpsrVisit(test()->dpsrDoctorA, ClinicVisit::STATUS_IN_PROGRESS);
    rmeSignedConsentFor($own);

    $response = test()->actingAs(test()->dpsrUserA)
        ->get(route('rme.visits.medical-record.show', [$anchor, 'source_visit_id' => $own->id]))
        ->assertOk();

    expect($response->viewData('anchorContentReadable'))->toBeTrue()
        ->and((int) $response->viewData('workflowNavVisit')?->id)->toBe((int) $anchor->id)
        ->and($response->getContent())->toContain('Navigasi RME kunjungan');
});

it('(c)-01 answers 404, not the room redirect, for another doctor\'s roomless pre-exam visit (flag ON)', function (string $routeName, string $method) {
    dpsrFlag(true);
    // The patient's anchor is a completed visit, so the roomless visit is not it.
    dpsrVisit(test()->dpsrDoctorB, ClinicVisit::STATUS_COMPLETED, ['visit_date' => '2026-01-05', 'completed_at' => '2026-01-05 03:00:00']);
    $roomless = dpsrVisit(test()->dpsrDoctorB, ClinicVisit::STATUS_WAITING, ['clinic_room_id' => null]);

    test()->actingAs(test()->dpsrUserA)
        ->call($method, route($routeName, $roomless))
        ->assertNotFound();
})->with([
    'odontogram page' => ['rme.visits.odontogram.show', 'GET'],
    'prescription page' => ['rme.visits.prescription.show', 'GET'],
    'RM page (not the anchor)' => ['rme.visits.medical-record.show', 'GET'],
    'prescription write' => ['rme.visits.prescription.store', 'POST'],
]);

it('(c)-02 keeps the room redirect for that visit with the flag OFF', function (string $routeName, string $method) {
    dpsrFlag(false);
    dpsrVisit(test()->dpsrDoctorB, ClinicVisit::STATUS_COMPLETED, ['visit_date' => '2026-01-05', 'completed_at' => '2026-01-05 03:00:00']);
    $roomless = dpsrVisit(test()->dpsrDoctorB, ClinicVisit::STATUS_WAITING, ['clinic_room_id' => null]);

    test()->actingAs(test()->dpsrUserA)
        ->call($method, route($routeName, $roomless))
        ->assertRedirect(route('rme.visits.show', $roomless));
})->with([
    'odontogram page' => ['rme.visits.odontogram.show', 'GET'],
    'prescription page' => ['rme.visits.prescription.show', 'GET'],
    'RM page (not the anchor)' => ['rme.visits.medical-record.show', 'GET'],
    'prescription write' => ['rme.visits.prescription.store', 'POST'],
]);

it('(c)-03 keeps the room redirect on the doctor\'s own roomless visit, and 404 on a placed one they may not read (flag ON)', function () {
    dpsrFlag(true);
    dpsrVisit(test()->dpsrDoctorB, ClinicVisit::STATUS_COMPLETED, ['visit_date' => '2026-01-05', 'completed_at' => '2026-01-05 03:00:00']);
    $own = dpsrVisit(test()->dpsrDoctorA, ClinicVisit::STATUS_WAITING, ['clinic_room_id' => null]);
    $placedOther = dpsrVisit(test()->dpsrDoctorB, ClinicVisit::STATUS_WAITING); // in A's room

    test()->actingAs(test()->dpsrUserA)
        ->get(route('rme.visits.odontogram.show', $own))
        ->assertRedirect(route('rme.visits.show', $own));

    test()->actingAs(test()->dpsrUserA)
        ->get(route('rme.visits.odontogram.show', $placedOther))
        ->assertNotFound();

    // The usual roomless queue visit has no doctor yet (the doctor comes with the
    // room): not the doctor's own, so 404 as well.
    $doctorless = ClinicVisit::factory()->create([
        'branch_id' => test()->dpsrBranch->id,
        'patient_id' => test()->dpsrPatient->id,
        'doctor_id' => null,
        'clinic_room_id' => null,
        'status' => ClinicVisit::STATUS_REGISTERED,
        'visit_date' => '2026-11-18',
    ]);

    test()->actingAs(test()->dpsrUserA)
        ->get(route('rme.visits.odontogram.show', $doctorless))
        ->assertNotFound();
});
