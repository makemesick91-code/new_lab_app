<?php

/**
 * Sprint 58.7 — RME Registered Patient Queue Page (Antrian Pasien).
 *
 * Dedicated post-registration queue for Admin Klinik. Lists active (non-terminal)
 * RME visits across the active RME branch set, with and without an assigned room,
 * and reuses the Sprint 58.6 assign-room route for the per-row room selector.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Carbon::setTestNow(Carbon::parse('2026-06-23 09:00:00'));

    $this->atg = Branch::factory()->create(['code' => 'ATG7', 'name' => 'Cabang Antang', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->tkm = Branch::factory()->create(['code' => 'TKM7', 'name' => 'Cabang Telkomas', 'is_active' => true, 'is_rme_enabled' => true]);

    $this->doctor = Doctor::factory()->create(['name' => 'drg. Uji']);
    $this->admin = userWith(['view_clinic_visits', 'manage_clinic_visits']);
});

afterEach(fn () => Carbon::setTestNow());

/** Create a registered queue visit with a named patient at a branch. */
function queueVisit(Branch $branch, string $patientName, array $overrides = []): ClinicVisit
{
    $patient = Patient::factory()->create(['branch_id' => $branch->id, 'name' => $patientName]);

    return ClinicVisit::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => test()->doctor->id,
        'clinic_room_id' => null,
        'status' => ClinicVisit::STATUS_REGISTERED,
    ], $overrides));
}

// --- Visibility ----------------------------------------------------------------

it('shows a registered patient on the queue page', function () {
    queueVisit($this->atg, 'Pasien Terdaftar');

    $this->actingAs($this->admin)
        ->get(route('rme.patient-queue.index'))
        ->assertOk()
        ->assertViewIs('rme.patient-queue.index')
        ->assertSee('Antrian Pasien')
        ->assertSee('Pasien Terdaftar');
});

it('shows Belum dipilih for a patient without a room', function () {
    queueVisit($this->atg, 'Pasien Tanpa Ruangan');

    $this->actingAs($this->admin)
        ->get(route('rme.patient-queue.index'))
        ->assertOk()
        ->assertSee('Belum dipilih');
});

it('shows the room name for a patient with a room', function () {
    $room = ClinicRoom::factory()->create(['branch_id' => $this->atg->id, 'name' => 'Ruang Antang 5']);
    queueVisit($this->atg, 'Pasien Sudah Ruangan', ['clinic_room_id' => $room->id, 'status' => ClinicVisit::STATUS_WAITING]);

    $this->actingAs($this->admin)
        ->get(route('rme.patient-queue.index'))
        ->assertOk()
        ->assertSee('Ruang Antang 5');
});

it('excludes terminal visits from the queue', function () {
    queueVisit($this->atg, 'Pasien Selesai', ['status' => ClinicVisit::STATUS_COMPLETED]);
    queueVisit($this->atg, 'Pasien Batal', ['status' => ClinicVisit::STATUS_CANCELLED]);

    $this->actingAs($this->admin)
        ->get(route('rme.patient-queue.index'))
        ->assertOk()
        ->assertDontSee('Pasien Selesai')
        ->assertDontSee('Pasien Batal');
});

// --- Filters -------------------------------------------------------------------

it('searches the queue by patient name', function () {
    queueVisit($this->atg, 'Pasien Dicari');
    queueVisit($this->atg, 'Pasien Lain');

    $this->actingAs($this->admin)
        ->get(route('rme.patient-queue.index', ['search' => 'Dicari']))
        ->assertOk()
        ->assertSee('Pasien Dicari')
        ->assertDontSee('Pasien Lain');
});

it('filters the queue by Belum dipilih (unassigned room)', function () {
    $room = ClinicRoom::factory()->create(['branch_id' => $this->atg->id, 'name' => 'Ruang Terisi']);
    queueVisit($this->atg, 'Pasien Tanpa Ruang Filter');
    queueVisit($this->atg, 'Pasien Dengan Ruang Filter', ['clinic_room_id' => $room->id, 'status' => ClinicVisit::STATUS_WAITING]);

    $this->actingAs($this->admin)
        ->get(route('rme.patient-queue.index', ['room_status' => 'unassigned']))
        ->assertOk()
        ->assertSee('Pasien Tanpa Ruang Filter')
        ->assertDontSee('Pasien Dengan Ruang Filter');
});

it('filters the queue by Sudah dipilih (assigned room)', function () {
    $room = ClinicRoom::factory()->create(['branch_id' => $this->atg->id, 'name' => 'Ruang Assigned']);
    queueVisit($this->atg, 'Pasien Tanpa Ruang Assigned');
    queueVisit($this->atg, 'Pasien Dengan Ruang Assigned', ['clinic_room_id' => $room->id, 'status' => ClinicVisit::STATUS_WAITING]);

    $this->actingAs($this->admin)
        ->get(route('rme.patient-queue.index', ['room_status' => 'assigned']))
        ->assertOk()
        ->assertSee('Pasien Dengan Ruang Assigned')
        ->assertDontSee('Pasien Tanpa Ruang Assigned');
});

// --- Room assignment from the queue (reuses Sprint 58.6 route) ------------------

it('lets Admin Klinik assign a same-branch room from the queue page', function () {
    $room = ClinicRoom::factory()->create(['branch_id' => $this->atg->id, 'name' => 'Ruang Antang Q']);
    $visit = queueVisit($this->atg, 'Pasien Assign');

    $this->actingAs(userInRole('Admin Klinik'))
        ->from(route('rme.patient-queue.index'))
        ->patch(route('rme.visits.assign-room', $visit), ['clinic_room_id' => $room->id])
        ->assertRedirect();

    expect($visit->refresh()->clinic_room_id)->toBe($room->id);
});

it('rejects assigning a room from another branch via the queue page', function () {
    $otherRoom = ClinicRoom::factory()->create(['branch_id' => $this->tkm->id]);
    $visit = queueVisit($this->atg, 'Pasien Assign Lintas');

    $this->actingAs(userInRole('Admin Klinik'))
        ->from(route('rme.patient-queue.index'))
        ->patch(route('rme.visits.assign-room', $visit), ['clinic_room_id' => $otherRoom->id])
        ->assertSessionHasErrors('clinic_room_id');

    expect($visit->refresh()->clinic_room_id)->toBeNull();
});

// --- Branch isolation ----------------------------------------------------------

it('does not leak another branch patient when scoped by branch filter', function () {
    queueVisit($this->atg, 'Pasien Antang Queue');
    queueVisit($this->tkm, 'Pasien Telkomas Queue');

    $this->actingAs($this->admin)
        ->get(route('rme.patient-queue.index', ['branch_id' => $this->atg->id]))
        ->assertOk()
        ->assertSee('Pasien Antang Queue')
        ->assertDontSee('Pasien Telkomas Queue');
});

// --- Privacy -------------------------------------------------------------------

it('does not expose sensitive patient fields on the queue page', function () {
    $patient = Patient::factory()->create([
        'branch_id' => $this->atg->id,
        'name' => 'Pasien Privasi Antrian',
        'ktp_number' => '7300009999990001',
        'phone' => '081255550000',
        'address' => 'Jalan Sangat Rahasia No. 7',
    ]);
    ClinicVisit::factory()->create([
        'branch_id' => $this->atg->id,
        'patient_id' => $patient->id,
        'doctor_id' => $this->doctor->id,
        'clinic_room_id' => null,
        'status' => ClinicVisit::STATUS_REGISTERED,
    ]);

    $this->actingAs($this->admin)
        ->get(route('rme.patient-queue.index'))
        ->assertOk()
        ->assertSee('Pasien Privasi Antrian')
        ->assertDontSee('7300009999990001')
        ->assertDontSee('081255550000')
        ->assertDontSee('Jalan Sangat Rahasia No. 7');
});

// --- Access control ------------------------------------------------------------

it('forbids a role without clinic-visit permissions from the queue page', function () {
    $this->actingAs(userInRole('Admin Warehouse'))
        ->get(route('rme.patient-queue.index'))
        ->assertForbidden();
});

// --- Regressions ---------------------------------------------------------------

it('still loads the existing visits list page', function () {
    $this->actingAs($this->admin)
        ->get(route('rme.visits.index'))
        ->assertOk();
});

it('still loads the RME dashboard', function () {
    $this->actingAs($this->admin)
        ->get(route('rme.dashboard'))
        ->assertOk();
});
