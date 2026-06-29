<?php

/**
 * Sprint 66.2 — Doctor RM visibility scope by assignment history.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\CrossBranchPatientLookupService;
use App\Modules\RME\Models\PatientDoctorAssignment;
use App\Modules\RME\Services\PatientDoctorAssignmentService;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    test()->rmeBranch = Branch::factory()->create(['code' => 'RME662', 'is_rme_enabled' => true]);
    test()->clinic = Clinic::factory()->create();
    test()->room = ClinicRoom::factory()->create([
        'branch_id' => test()->rmeBranch->id,
        'status' => ClinicRoom::STATUS_ACTIVE,
    ]);
    test()->treatment = Treatment::factory()->create(['is_active' => true]);

    test()->doctorA = Doctor::factory()->create([
        'clinic_id' => test()->clinic->id,
        'branch_id' => test()->rmeBranch->id,
    ]);
    test()->doctorAUser = User::factory()->create()->assignRole('Doctor');
    test()->doctorA->update(['user_id' => test()->doctorAUser->id]);
    test()->doctorA->branches()->sync([(int) test()->rmeBranch->id]);

    test()->doctorB = Doctor::factory()->create([
        'clinic_id' => test()->clinic->id,
        'branch_id' => test()->rmeBranch->id,
    ]);
    test()->doctorBUser = User::factory()->create()->assignRole('Doctor');
    test()->doctorB->update(['user_id' => test()->doctorBUser->id]);
    test()->doctorB->branches()->sync([(int) test()->rmeBranch->id]);

    test()->adminUser = User::factory()->create()->assignRole('Admin Klinik');
    test()->adminUser->givePermissionTo(['manage_clinic_visits', 'view_clinic_visits']);

    test()->ownerUser = User::factory()->create()->assignRole('Owner');
    test()->ownerUser->givePermissionTo(['manage_clinic_visits', 'view_clinic_visits']);

    test()->assignmentService = app(PatientDoctorAssignmentService::class);
    test()->lookupService = app(CrossBranchPatientLookupService::class);
});

function scopePatient(array $overrides = []): Patient
{
    return Patient::factory()->create(array_merge([
        'branch_id' => test()->rmeBranch->id,
        'medical_record_number' => 'DG-RME662-2026-'.fake()->unique()->numerify('####'),
    ], $overrides));
}

function scopeVisit(Patient $patient, ?Doctor $doctor = null, array $overrides = []): ClinicVisit
{
    return ClinicVisit::factory()->create(array_merge([
        'branch_id' => test()->rmeBranch->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor?->id,
        'clinic_room_id' => ClinicRoom::factory()->create(['branch_id' => test()->rmeBranch->id]),
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ], $overrides));
}

function scopeDoctorOnline(User $user, Doctor $doctor): void
{
    rmeMakeDoctorOnline($doctor, test()->rmeBranch, test()->room, $user);
    $user->givePermissionTo(['view_clinic_visits', 'manage_clinic_visits']);
}

it('lets doctor see patient actively assigned to them', function () {
    $patient = scopePatient(['name' => 'Pasien Assigned Aktif']);
    PatientDoctorAssignment::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => test()->doctorA->id,
        'branch_id' => test()->rmeBranch->id,
    ]);
    scopeVisit($patient, test()->doctorA);
    scopeDoctorOnline(test()->doctorAUser, test()->doctorA);

    $this->actingAs(test()->doctorAUser)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('Pasien Assigned Aktif');
});

it('lets doctor see patient previously assigned after unassign', function () {
    $patient = scopePatient();
    $assignment = PatientDoctorAssignment::factory()->create([
        'patient_id' => $patient->id,
        'doctor_id' => test()->doctorA->id,
        'branch_id' => test()->rmeBranch->id,
    ]);
    test()->assignmentService->unassignPatientDoctor($patient, test()->doctorA, test()->adminUser);
    scopeDoctorOnline(test()->doctorAUser, test()->doctorA);
    $visit = scopeVisit($patient, test()->doctorA);

    $this->actingAs(test()->doctorAUser)
        ->get(route('rme.visits.show', $visit))
        ->assertOk();

    expect($assignment->refresh()->unassigned_at)->not->toBeNull();
});

it('lets doctor see patient they handled via clinic visit', function () {
    $patient = scopePatient();
    $visit = scopeVisit($patient, test()->doctorA);
    scopeDoctorOnline(test()->doctorAUser, test()->doctorA);

    $this->actingAs(test()->doctorAUser)
        ->get(route('rme.visits.show', $visit))
        ->assertOk();
});

it('hides unrelated patients from doctor visit list', function () {
    $mine = scopePatient(['name' => 'Pasien Milik Dokter A']);
    $other = scopePatient(['name' => 'Pasien Orang Lain']);
    scopeVisit($mine, test()->doctorA);
    scopeVisit($other, test()->doctorB);
    scopeDoctorOnline(test()->doctorAUser, test()->doctorA);

    $response = $this->actingAs(test()->doctorAUser)
        ->get(route('rme.visits.index'));

    $response->assertOk()
        ->assertSee('Pasien Milik Dokter A')
        ->assertDontSee('Pasien Orang Lain');
});

it('forbids doctor opening unrelated visit detail by direct url', function () {
    $otherPatient = scopePatient();
    $visit = scopeVisit($otherPatient, test()->doctorB);
    scopeDoctorOnline(test()->doctorAUser, test()->doctorA);

    $this->actingAs(test()->doctorAUser)
        ->get(route('rme.visits.show', $visit))
        ->assertForbidden();
});

it('forbids doctor printing unrelated visit bundle', function () {
    $visit = scopeVisit(scopePatient(), test()->doctorB);
    scopeDoctorOnline(test()->doctorAUser, test()->doctorA);

    $this->actingAs(test()->doctorAUser)
        ->get(route('rme.visits.print', $visit))
        ->assertForbidden();
});

it('forbids doctor accessing unrelated medical record', function () {
    $patient = scopePatient();
    $visit = scopeVisit($patient, test()->doctorB);
    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'patient_id' => $patient->id,
        'branch_id' => test()->rmeBranch->id,
        'doctor_id' => test()->doctorB->id,
    ]);
    scopeDoctorOnline(test()->doctorAUser, test()->doctorA);

    $this->actingAs(test()->doctorAUser)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertForbidden();

    expect($record)->not->toBeNull();
});

it('forbids doctor accessing unrelated odontogram', function () {
    $patient = scopePatient();
    $visit = scopeVisit($patient, test()->doctorB);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => test()->rmeBranch->id,
    ]);
    scopeDoctorOnline(test()->doctorAUser, test()->doctorA);

    $this->actingAs(test()->doctorAUser)
        ->get(route('rme.visits.odontogram.show', $visit))
        ->assertForbidden();

    $this->actingAs(test()->doctorAUser)
        ->get(route('rme.odontograms.print', $odontogram))
        ->assertForbidden();
});

it('shows empty list and forbids detail for doctor without linked master doctor', function () {
    $unlinked = User::factory()->create()->assignRole('Doctor');
    $unlinked->givePermissionTo(['view_clinic_visits', 'manage_clinic_visits']);
    rmeMakeDoctorOnline(test()->doctorA, test()->rmeBranch, test()->room, $unlinked);

    $visible = scopePatient(['name' => 'Pasien Terlihat Admin']);
    scopeVisit($visible, test()->doctorB);
    $visit = scopeVisit(scopePatient(['name' => 'Pasien Tersembunyi']), test()->doctorB);

    $this->actingAs($unlinked)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertDontSee('Pasien Tersembunyi');

    $this->actingAs($unlinked)
        ->get(route('rme.visits.show', $visit))
        ->assertForbidden();
});

it('lets admin klinik see branch context patients without doctor scope', function () {
    rmeMakeAdminClinicActive(test()->adminUser, test()->rmeBranch);
    $patientA = scopePatient(['name' => 'Pasien Admin A']);
    $patientB = scopePatient(['name' => 'Pasien Admin B']);
    scopeVisit($patientA, test()->doctorA);
    scopeVisit($patientB, test()->doctorB);

    $this->actingAs(test()->adminUser)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('Pasien Admin A')
        ->assertSee('Pasien Admin B');
});

it('lets owner see all patients regardless of doctor assignment', function () {
    scopeVisit(scopePatient(['name' => 'Owner Pasien A']), test()->doctorA);
    scopeVisit(scopePatient(['name' => 'Owner Pasien B']), test()->doctorB);

    $this->actingAs(test()->ownerUser)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('Owner Pasien A')
        ->assertSee('Owner Pasien B');
});

it('creates auto assignment after assign room succeeds', function () {
    rmeMakeAdminClinicActive(test()->adminUser, test()->rmeBranch);
    rmeMakeDoctorOnline(test()->doctorA, test()->rmeBranch, test()->room);
    $patient = scopePatient();
    $visit = ClinicVisit::factory()->create([
        'branch_id' => test()->rmeBranch->id,
        'patient_id' => $patient->id,
        'doctor_id' => null,
        'clinic_room_id' => null,
        'status' => ClinicVisit::STATUS_REGISTERED,
    ]);

    $this->actingAs(test()->adminUser)
        ->patch(route('rme.visits.assign-room', $visit), ['clinic_room_id' => test()->room->id])
        ->assertRedirect();

    $assignment = PatientDoctorAssignment::query()
        ->where('patient_id', $patient->id)
        ->where('doctor_id', test()->doctorA->id)
        ->whereNull('unassigned_at')
        ->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->assignment_type)->toBe(PatientDoctorAssignment::TYPE_AUTO_VISIT)
        ->and((int) $assignment->source_visit_id)->toBe($visit->id)
        ->and((int) $assignment->branch_id)->toBe(test()->rmeBranch->id)
        ->and((int) $assignment->assigned_by)->toBe(test()->adminUser->id);
});

it('is idempotent when assign room is repeated', function () {
    rmeMakeAdminClinicActive(test()->adminUser, test()->rmeBranch);
    rmeMakeDoctorOnline(test()->doctorA, test()->rmeBranch, test()->room);
    $visit = ClinicVisit::factory()->create([
        'branch_id' => test()->rmeBranch->id,
        'patient_id' => scopePatient()->id,
        'doctor_id' => null,
        'clinic_room_id' => null,
        'status' => ClinicVisit::STATUS_REGISTERED,
    ]);

    $this->actingAs(test()->adminUser)
        ->patch(route('rme.visits.assign-room', $visit), ['clinic_room_id' => test()->room->id]);
    $this->actingAs(test()->adminUser)
        ->patch(route('rme.visits.assign-room', $visit->fresh()), ['clinic_room_id' => test()->room->id]);

    expect(PatientDoctorAssignment::query()
        ->where('patient_id', $visit->patient_id)
        ->where('doctor_id', test()->doctorA->id)
        ->whereNull('unassigned_at')
        ->count())->toBe(1);
});

it('lets doctor a and doctor b both see shared patient', function () {
    $patient = scopePatient(['name' => 'Pasien Shared']);
    scopeVisit($patient, test()->doctorA);
    test()->assignmentService->assignPatientToDoctor($patient, test()->doctorA, test()->adminUser, test()->rmeBranch);
    test()->assignmentService->sharePatientWithDoctor($patient, test()->doctorB, test()->doctorA, test()->adminUser, test()->rmeBranch);
    scopeDoctorOnline(test()->doctorAUser, test()->doctorA);
    $roomB = ClinicRoom::factory()->create([
        'branch_id' => test()->rmeBranch->id,
        'status' => ClinicRoom::STATUS_ACTIVE,
    ]);
    rmeMakeDoctorOnline(test()->doctorB, test()->rmeBranch, $roomB, test()->doctorBUser);
    test()->doctorBUser->givePermissionTo(['view_clinic_visits', 'manage_clinic_visits']);

    $this->actingAs(test()->doctorAUser)
        ->get(route('rme.visits.index'))
        ->assertSee('Pasien Shared');

    $this->actingAs(test()->doctorBUser)
        ->get(route('rme.visits.index'))
        ->assertSee('Pasien Shared');
});

it('keeps doctor a read access after unassign', function () {
    $patient = scopePatient();
    test()->assignmentService->assignPatientToDoctor($patient, test()->doctorA, test()->adminUser, test()->rmeBranch);
    test()->assignmentService->unassignPatientDoctor($patient, test()->doctorA, test()->adminUser);
    scopeDoctorOnline(test()->doctorAUser, test()->doctorA);
    $visit = scopeVisit($patient, test()->doctorA);

    $this->actingAs(test()->doctorAUser)
        ->get(route('rme.visits.show', $visit))
        ->assertOk();
});

it('prevents duplicate active assignment for same patient and doctor', function () {
    $patient = scopePatient();
    test()->assignmentService->assignPatientToDoctor($patient, test()->doctorA, test()->adminUser, test()->rmeBranch);
    $second = test()->assignmentService->assignPatientToDoctor($patient, test()->doctorA, test()->adminUser, test()->rmeBranch);

    expect(PatientDoctorAssignment::query()
        ->where('patient_id', $patient->id)
        ->where('doctor_id', test()->doctorA->id)
        ->whereNull('unassigned_at')
        ->count())->toBe(1)
        ->and($second->id)->toBe(
            PatientDoctorAssignment::query()
                ->where('patient_id', $patient->id)
                ->where('doctor_id', test()->doctorA->id)
                ->whereNull('unassigned_at')
                ->value('id')
        );
});

it('scopes rm lookup results for doctor', function () {
    $mine = scopePatient(['medical_record_number' => 'DG-SCOPE-1111', 'name' => 'Lookup Milik']);
    scopeVisit($mine, test()->doctorA);
    scopePatient(['medical_record_number' => 'DG-SCOPE-2222', 'name' => 'Lookup Bukan']);
    scopeDoctorOnline(test()->doctorAUser, test()->doctorA);

    $this->actingAs(test()->doctorAUser);
    $result = test()->lookupService->lookupByMedicalRecordNumberAcrossBranches('DG-SCOPE-1111');

    expect($result['results'])->toHaveCount(1)
        ->and($result['results'][0]['name'])->toBe('Lookup Milik');

    $empty = test()->lookupService->lookupByMedicalRecordNumberAcrossBranches('DG-SCOPE-2222');
    expect($empty['results'])->toHaveCount(0);
});

it('scopes cross branch suffix rm lookup for doctor', function () {
    $mine = scopePatient(['medical_record_number' => 'DG-OTHER-2026-4421']);
    scopeVisit($mine, test()->doctorA);
    scopePatient(['medical_record_number' => 'DG-OTHER-2026-9999']);
    scopeDoctorOnline(test()->doctorAUser, test()->doctorA);

    $this->actingAs(test()->doctorAUser);
    expect(test()->lookupService->lookupByMedicalRecordNumberAcrossBranches('4421')['results'])->toHaveCount(1)
        ->and(test()->lookupService->lookupByMedicalRecordNumberAcrossBranches('9999')['results'])->toHaveCount(0);
});

it('forbids doctor accessing patient assigned only to another doctor', function () {
    $patient = scopePatient();
    test()->assignmentService->assignPatientToDoctor($patient, test()->doctorB, test()->adminUser, test()->rmeBranch);
    $visit = scopeVisit($patient, test()->doctorB);
    scopeDoctorOnline(test()->doctorAUser, test()->doctorA);

    $this->actingAs(test()->doctorAUser)
        ->get(route('rme.visits.show', $visit))
        ->assertForbidden();
});

it('scopes patient visit options json for doctor', function () {
    $mine = scopePatient();
    $other = scopePatient();
    scopeDoctorOnline(test()->doctorAUser, test()->doctorA);
    scopeVisit($mine, test()->doctorA);

    $this->actingAs(test()->doctorAUser)
        ->getJson(route('rme.visits.patient-options', ['patient_id' => $mine->id]))
        ->assertOk();

    $this->actingAs(test()->doctorAUser)
        ->getJson(route('rme.visits.patient-options', ['patient_id' => $other->id]))
        ->assertForbidden();
});

it('lets super admin see all visits without doctor scope', function () {
    scopeVisit(scopePatient(['name' => 'Super Pasien']), test()->doctorB);
    $super = superAdmin();
    $super->givePermissionTo(['view_clinic_visits']);

    $this->actingAs($super)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('Super Pasien');
});
