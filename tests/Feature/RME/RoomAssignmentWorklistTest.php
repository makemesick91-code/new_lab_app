<?php

/**
 * Sprint 58.6 — RME Room Assignment Queue & Treatment Room Worklist.
 *
 * - Room selection is removed from patient registration; new visits start with
 *   no room. Admin Klinik assigns a treatment room from the queue (same branch
 *   only) before treatment. Doctors/Perawat get a dedicated worklist that lists
 *   only room-assigned, non-terminal visits, scoped to active RME branches.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Modules\Treatment\Models\Treatment;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Carbon::setTestNow(Carbon::parse('2026-06-23 09:00:00'));

    $this->atg = Branch::factory()->create(['code' => 'ATG3', 'name' => 'Cabang Antang', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->tkm = Branch::factory()->create(['code' => 'TKM1', 'name' => 'Cabang Telkomas', 'is_active' => true, 'is_rme_enabled' => true]);

    $this->doctor = Doctor::factory()->create(['name' => 'drg. Uji']);
    $this->treatment = Treatment::factory()->create(['is_active' => true]);

    $this->admin = userWith(['view_clinic_visits', 'manage_clinic_visits']);
});

afterEach(fn () => Carbon::setTestNow());

/** Create a room-assigned, active visit with a named patient at a branch. */
function worklistVisit(Branch $branch, ClinicRoom $room, string $patientName, array $overrides = []): ClinicVisit
{
    $patient = Patient::factory()->create(['branch_id' => $branch->id, 'name' => $patientName]);

    return ClinicVisit::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => test()->doctor->id,
        'clinic_room_id' => $room->id,
        'status' => ClinicVisit::STATUS_WAITING,
    ], $overrides));
}

// --- A. Registration no longer selects a room ----------------------------------

it('does not show a room selector on the registration form', function () {
    $this->actingAs($this->admin)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->assertDontSee('clinic_room_id')
        ->assertDontSee('Tanpa ruangan');
});

it('creates a registered visit with no room assigned', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->atg->id]);

    $this->actingAs($this->admin)
        ->post(route('rme.visits.store'), [
            'branch_id' => $this->atg->id,
            'patient_id' => $patient->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertRedirect();

    $visit = ClinicVisit::where('patient_id', $patient->id)->firstOrFail();
    expect($visit->clinic_room_id)->toBeNull()
        ->and($visit->status)->toBe(ClinicVisit::STATUS_REGISTERED);
});

// --- B. Admin Klinik room assignment -------------------------------------------

it('lets Admin Klinik assign a same-branch room to a queued visit', function () {
    $room = ClinicRoom::factory()->create(['branch_id' => $this->atg->id, 'name' => 'Ruang Antang 1']);
    $patient = Patient::factory()->create(['branch_id' => $this->atg->id]);
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->atg->id,
        'patient_id' => $patient->id,
        'doctor_id' => $this->doctor->id,
        'clinic_room_id' => null,
        'status' => ClinicVisit::STATUS_WAITING,
    ]);

    $this->actingAs(userInRole('Admin Klinik'))
        ->from(route('rme.visits.index'))
        ->patch(route('rme.visits.assign-room', $visit), ['clinic_room_id' => $room->id])
        ->assertRedirect();

    expect($visit->refresh()->clinic_room_id)->toBe($room->id);
});

it('rejects assigning a room from another branch', function () {
    $otherRoom = ClinicRoom::factory()->create(['branch_id' => $this->tkm->id]);
    $patient = Patient::factory()->create(['branch_id' => $this->atg->id]);
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->atg->id,
        'patient_id' => $patient->id,
        'doctor_id' => $this->doctor->id,
        'clinic_room_id' => null,
        'status' => ClinicVisit::STATUS_WAITING,
    ]);

    $this->actingAs(userInRole('Admin Klinik'))
        ->from(route('rme.visits.index'))
        ->patch(route('rme.visits.assign-room', $visit), ['clinic_room_id' => $otherRoom->id])
        ->assertSessionHasErrors('clinic_room_id');

    expect($visit->refresh()->clinic_room_id)->toBeNull();
});

// --- C. Worklist access control ------------------------------------------------

it('lets a Doctor open the treatment room worklist', function () {
    $this->actingAs(userInRole('Doctor'))
        ->get(route('rme.treatment-room-worklist.index'))
        ->assertOk()
        ->assertViewIs('rme.visits.room-worklist');
});

it('lets a Perawat open the treatment room worklist', function () {
    $this->actingAs(userInRole('Perawat'))
        ->get(route('rme.treatment-room-worklist.index'))
        ->assertOk();
});

it('forbids a Kasir (no worklist permission) from the worklist', function () {
    $this->actingAs(userInRole('Kasir'))
        ->get(route('rme.treatment-room-worklist.index'))
        ->assertForbidden();
});

// --- C/D. Worklist data rules --------------------------------------------------

it('shows a room-assigned active visit on the worklist', function () {
    $room = ClinicRoom::factory()->create(['branch_id' => $this->atg->id]);
    worklistVisit($this->atg, $room, 'Pasien Sudah Ruangan');

    $this->actingAs(userInRole('Doctor'))
        ->get(route('rme.treatment-room-worklist.index'))
        ->assertOk()
        ->assertSee('Pasien Sudah Ruangan');
});

it('hides a visit without an assigned room from the worklist', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->atg->id, 'name' => 'Pasien Tanpa Ruangan']);
    ClinicVisit::factory()->create([
        'branch_id' => $this->atg->id,
        'patient_id' => $patient->id,
        'doctor_id' => $this->doctor->id,
        'clinic_room_id' => null,
        'status' => ClinicVisit::STATUS_WAITING,
    ]);

    $this->actingAs(userInRole('Doctor'))
        ->get(route('rme.treatment-room-worklist.index'))
        ->assertOk()
        ->assertDontSee('Pasien Tanpa Ruangan');
});

it('hides terminal visits even when a room is assigned', function () {
    $room = ClinicRoom::factory()->create(['branch_id' => $this->atg->id]);
    worklistVisit($this->atg, $room, 'Pasien Selesai', ['status' => ClinicVisit::STATUS_COMPLETED]);

    $this->actingAs(userInRole('Doctor'))
        ->get(route('rme.treatment-room-worklist.index'))
        ->assertOk()
        ->assertDontSee('Pasien Selesai');
});

it('scopes the worklist by branch filter (no cross-branch leak)', function () {
    $roomA = ClinicRoom::factory()->create(['branch_id' => $this->atg->id]);
    $roomB = ClinicRoom::factory()->create(['branch_id' => $this->tkm->id]);
    worklistVisit($this->atg, $roomA, 'Pasien Antang Worklist');
    worklistVisit($this->tkm, $roomB, 'Pasien Telkomas Worklist');

    $this->actingAs(userInRole('Doctor'))
        ->get(route('rme.treatment-room-worklist.index', ['branch_id' => $this->atg->id]))
        ->assertOk()
        ->assertSee('Pasien Antang Worklist')
        ->assertDontSee('Pasien Telkomas Worklist');
});

it('does not expose sensitive patient fields on the worklist', function () {
    $room = ClinicRoom::factory()->create(['branch_id' => $this->atg->id]);
    $patient = Patient::factory()->create([
        'branch_id' => $this->atg->id,
        'name' => 'Pasien Privasi',
        'ktp_number' => '7300001234560001',
        'phone' => '081299990000',
        'address' => 'Jalan Rahasia No. 99',
    ]);
    ClinicVisit::factory()->create([
        'branch_id' => $this->atg->id,
        'patient_id' => $patient->id,
        'doctor_id' => $this->doctor->id,
        'clinic_room_id' => $room->id,
        'status' => ClinicVisit::STATUS_WAITING,
    ]);

    $this->actingAs(userInRole('Doctor'))
        ->get(route('rme.treatment-room-worklist.index'))
        ->assertOk()
        ->assertSee('Pasien Privasi')
        ->assertDontSee('7300001234560001')
        ->assertDontSee('081299990000')
        ->assertDontSee('Jalan Rahasia No. 99');
});

// --- E. Regressions ------------------------------------------------------------

it('still shows the Ruangan column on the medical records page', function () {
    $this->actingAs($this->admin)
        ->get(route('rme.medical-records.index'))
        ->assertOk()
        ->assertSee('Ruangan');
});

it('still loads the RME dashboard', function () {
    $this->actingAs($this->admin)
        ->get(route('rme.dashboard'))
        ->assertOk();
});
