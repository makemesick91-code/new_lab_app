<?php

/**
 * Hotfix Sprint 60.8 — RME Room Assignment Gate Before Doctor Examination.
 *
 * Business rule: room assignment is a queue-stage requirement. After registration
 * a patient enters the queue; FO/operator assigns a treatment room while the
 * patient is already queued. The doctor must NOT be able to open/continue
 * examination input (Rekam Medis / Odontogram) until a room is assigned.
 *
 * The gate is enforced at the route/middleware level (EnsureVisitRoomAssigned),
 * not only by hiding Blade buttons. Terminal and cashier_pending visits are
 * exempt so Sprint 59 post-examination editing is never blocked. No payment,
 * consent, invoice, or receivable behaviour changes.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->atg = Branch::factory()->create(['code' => 'ATG7', 'name' => 'Cabang Antang', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->tkm = Branch::factory()->create(['code' => 'TKM7', 'name' => 'Cabang Telkomas', 'is_active' => true, 'is_rme_enabled' => true]);

    $this->doctor = Doctor::factory()->create(['name' => 'drg. Uji']);
    $this->admin = userWith(['view_clinic_visits', 'manage_clinic_visits']);
    $this->cashier = userWith(['manage_rme_billing']);
});

/** Create a visit (roomless by default) with a named patient at a branch. */
function gateVisit(Branch $branch, string $patientName = 'Pasien Uji', array $overrides = []): ClinicVisit
{
    $patient = Patient::factory()->create([
        'branch_id' => $branch->id,
        'name' => $patientName,
        'ktp_number' => fake()->unique()->numerify('73##############'),
    ]);

    return ClinicVisit::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => test()->doctor->id,
        'clinic_room_id' => null,
        'status' => ClinicVisit::STATUS_WAITING,
    ], $overrides));
}

function gateMedicalRecord(ClinicVisit $visit): MedicalRecord
{
    return MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);
}

// ─── Model gate semantics ─────────────────────────────────────────────────────

it('flags an active roomless visit as requiring a room before exam', function () {
    expect(gateVisit($this->atg)->requiresRoomBeforeExam())->toBeTrue();
});

it('does not require a room once one is assigned', function () {
    $room = ClinicRoom::factory()->create(['branch_id' => $this->atg->id]);
    $visit = gateVisit($this->atg, overrides: ['clinic_room_id' => $room->id]);

    expect($visit->requiresRoomBeforeExam())->toBeFalse();
});

it('exempts terminal visits from the room gate (Sprint 59 editing preserved)', function () {
    expect(gateVisit($this->atg, overrides: ['status' => ClinicVisit::STATUS_COMPLETED])->requiresRoomBeforeExam())->toBeFalse()
        ->and(gateVisit($this->atg, overrides: ['status' => ClinicVisit::STATUS_CANCELLED])->requiresRoomBeforeExam())->toBeFalse();
});

// ─── Queue-stage room assignment ──────────────────────────────────────────────

it('lets FO/operator assign a room after the visit is in the queue', function () {
    $room = ClinicRoom::factory()->create(['branch_id' => $this->atg->id, 'name' => 'Ruang Antang 1']);
    rmeMakeDoctorOnline($this->doctor, $this->atg, $room);
    $visit = gateVisit($this->atg, overrides: ['doctor_id' => null]);

    $this->actingAs($this->admin)
        ->from(route('rme.visits.show', $visit))
        ->patch(route('rme.visits.assign-room', $visit), ['clinic_room_id' => $room->id])
        ->assertRedirect();

    expect($visit->refresh()->clinic_room_id)->toBe($room->id)
        ->and($visit->doctor_id)->toBe($this->doctor->id);
});

it('scopes room options to the visit branch only', function () {
    $otherRoom = ClinicRoom::factory()->create(['branch_id' => $this->tkm->id]);
    $visit = gateVisit($this->atg);

    $this->actingAs($this->admin)
        ->from(route('rme.visits.show', $visit))
        ->patch(route('rme.visits.assign-room', $visit), ['clinic_room_id' => $otherRoom->id])
        ->assertSessionHasErrors('clinic_room_id');

    expect($visit->refresh()->clinic_room_id)->toBeNull();
});

it('rejects reassigning a room on a completed visit', function () {
    $room = ClinicRoom::factory()->create(['branch_id' => $this->atg->id]);
    $visit = gateVisit($this->atg, overrides: ['status' => ClinicVisit::STATUS_COMPLETED]);

    $this->actingAs($this->admin)
        ->from(route('rme.visits.show', $visit))
        ->patch(route('rme.visits.assign-room', $visit), ['clinic_room_id' => $room->id])
        ->assertSessionHasErrors('clinic_room_id');

    expect($visit->refresh()->clinic_room_id)->toBeNull();
});

// ─── Doctor examination gate ──────────────────────────────────────────────────

it('blocks opening the medical record when no room is assigned', function () {
    $visit = gateVisit($this->atg);
    gateMedicalRecord($visit);

    $this->actingAs($this->admin)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertRedirect(route('rme.visits.show', $visit))
        ->assertSessionHas('error', 'Pasien belum ditempatkan ke ruangan perawatan.');
});

it('blocks creating a medical record via direct POST when no room is assigned', function () {
    $visit = gateVisit($this->atg);

    $this->actingAs($this->admin)
        ->post(route('rme.visits.medical-record.store', $visit), ['notes' => 'x'])
        ->assertRedirect(route('rme.visits.show', $visit))
        ->assertSessionHas('error', 'Pasien belum ditempatkan ke ruangan perawatan.');

    expect($visit->refresh()->medicalRecord)->toBeNull();
});

it('blocks opening the odontogram when no room is assigned', function () {
    $visit = gateVisit($this->atg);

    $this->actingAs($this->admin)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertRedirect(route('rme.visits.show', $visit))
        ->assertSessionHas('error', 'Pasien belum ditempatkan ke ruangan perawatan.');
});

it('allows opening the medical record once a room is assigned', function () {
    $room = ClinicRoom::factory()->create(['branch_id' => $this->atg->id]);
    $visit = gateVisit($this->atg, overrides: ['clinic_room_id' => $room->id]);
    gateMedicalRecord($visit);

    $this->actingAs($this->admin)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertViewIs('rme.visits.medical-record.show');
});

it('allows opening the odontogram once a room is assigned', function () {
    $room = ClinicRoom::factory()->create(['branch_id' => $this->atg->id]);
    $visit = gateVisit($this->atg, overrides: ['clinic_room_id' => $room->id]);

    $this->actingAs($this->admin)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertOk()
        ->assertViewIs('rme.visits.odontogram.show');
});

it('still allows editing a finalized terminal visit with no room (Sprint 59)', function () {
    $visit = gateVisit($this->atg, overrides: ['status' => ClinicVisit::STATUS_COMPLETED]);
    gateMedicalRecord($visit);

    $this->actingAs($this->admin)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk();
});

// ─── Visit detail visibility ──────────────────────────────────────────────────

it('shows the waiting-room warning on the visit detail page when roomless', function () {
    $visit = gateVisit($this->atg);

    $this->actingAs($this->admin)
        ->get(route('rme.visits.show', $visit))
        ->assertOk()
        ->assertSee('Menunggu Penempatan Ruangan')
        ->assertSee('Pemeriksaan terkunci');
});

it('does not expose the patient KTP on the visit detail page', function () {
    $visit = gateVisit($this->atg);

    $this->actingAs($this->admin)
        ->get(route('rme.visits.show', $visit))
        ->assertOk()
        ->assertDontSee($visit->patient->ktp_number);
});

// ─── Cashier handoff visibility ───────────────────────────────────────────────

it('shows the room name on the cashier handoff queue when assigned', function () {
    $room = ClinicRoom::factory()->create(['branch_id' => $this->atg->id, 'name' => 'Ruang Kasir 3']);
    gateVisit($this->atg, 'Pasien Ada Ruangan', ['clinic_room_id' => $room->id, 'status' => ClinicVisit::STATUS_CASHIER_PENDING]);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.handoff'))
        ->assertOk()
        ->assertSee('Ruang Kasir 3');
});

it('flags a roomless active visit as not ready on the cashier handoff queue', function () {
    gateVisit($this->atg, 'Pasien Belum Ruangan', ['status' => ClinicVisit::STATUS_REGISTERED]);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.handoff'))
        ->assertOk()
        ->assertSee('Menunggu Ruangan')
        ->assertSee('Belum siap diperiksa');
});

it('does not expose the patient KTP on the cashier handoff queue', function () {
    $visit = gateVisit($this->atg, 'Pasien Rahasia', ['status' => ClinicVisit::STATUS_REGISTERED]);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.handoff'))
        ->assertOk()
        ->assertDontSee($visit->patient->ktp_number);
});
