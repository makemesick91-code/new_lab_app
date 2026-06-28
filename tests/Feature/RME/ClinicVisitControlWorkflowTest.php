<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->clinic = Clinic::factory()->create();
    $this->rmeBranch = Branch::factory()->create(['code' => 'RME1', 'is_rme_enabled' => true]);
    $this->nonRmeBranch = Branch::factory()->create(['code' => 'NORM', 'is_rme_enabled' => false]);
    $this->doctor = Doctor::factory()->create();
    $this->treatment = Treatment::factory()->create(['is_active' => true]);
    $this->manager = userWith(['manage_clinic_visits', 'view_clinic_visits']);
    $this->viewer = userWith(['view_clinic_visits']);
    $this->patient = Patient::factory()->create([
        'medical_record_number' => 'DG-RME1-2026-0001',
        'branch_id' => $this->rmeBranch->id,
    ]);
});

function makeParentVisit(Patient $patient, Branch $branch, Doctor $doctor, Treatment $treatment): ClinicVisit
{
    return ClinicVisit::factory()->create([
        'branch_id' => $branch->id,
        'clinic_id' => Clinic::factory()->create()->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'initial_treatment_id' => $treatment->id,
        'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
        'queue_number' => 1,
        'visit_number' => 'VIS-PARENT-'.uniqid(),
        'created_by' => userWith(['manage_clinic_visits'])->id,
    ]);
}

it('defaults visit_type to new and follow_up_of_visit_id to null on existing visits', function () {
    $visit = makeParentVisit($this->patient, $this->rmeBranch, $this->doctor, $this->treatment);

    expect($visit->fresh()->visit_type)->toBe(ClinicVisit::VISIT_TYPE_NEW)
        ->and($visit->fresh()->follow_up_of_visit_id)->toBeNull();
});

it('exposes parent and follow-up relations on clinic visit model', function () {
    $parent = makeParentVisit($this->patient, $this->rmeBranch, $this->doctor, $this->treatment);
    $control = ClinicVisit::factory()->create([
        'branch_id' => $this->rmeBranch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'visit_type' => ClinicVisit::VISIT_TYPE_CONTROL,
        'follow_up_of_visit_id' => $parent->id,
        'queue_number' => 2,
        'visit_number' => 'VIS-CONTROL-'.uniqid(),
        'created_by' => $this->manager->id,
    ]);

    expect($control->followUpOf?->id)->toBe($parent->id)
        ->and($parent->fresh()->followUpVisits)->toHaveCount(1)
        ->and($control->isControlVisit())->toBeTrue()
        ->and($control->visitTypeLabel())->toBe('Kontrol');
});

it('creates control visit for existing patient without duplicating patient', function () {
    $parent = makeParentVisit($this->patient, $this->rmeBranch, $this->doctor, $this->treatment);
    MedicalRecord::factory()->for($parent, 'clinicVisit')->create([
        'branch_id' => $this->rmeBranch->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'notes' => 'Catatan RME lama',
    ]);
    Odontogram::factory()->create([
        'clinic_visit_id' => $parent->id,
        'branch_id' => $this->rmeBranch->id,
    ]);

    $patientCountBefore = Patient::count();

    $this->actingAs($this->manager)
        ->post(route('rme.visits.store'), [
            'branch_id' => $this->rmeBranch->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
            'visit_type' => ClinicVisit::VISIT_TYPE_CONTROL,
            'follow_up_of_visit_id' => $parent->id,
            'chief_complaint' => 'Kontrol pasca tindakan',
        ])
        ->assertRedirect();

    expect(Patient::count())->toBe($patientCountBefore);

    $control = ClinicVisit::query()
        ->where('patient_id', $this->patient->id)
        ->where('visit_type', ClinicVisit::VISIT_TYPE_CONTROL)
        ->first();

    expect($control)->not->toBeNull()
        ->and($control->follow_up_of_visit_id)->toBe($parent->id)
        ->and($control->patient_id)->toBe($this->patient->id)
        ->and($control->visit_number)->not->toBe($parent->visit_number)
        ->and($control->queue_number)->not->toBe($parent->queue_number)
        ->and($parent->fresh()->medicalRecord?->notes)->toBe('Catatan RME lama')
        ->and($parent->fresh()->odontogram)->not->toBeNull()
        ->and(Odontogram::where('clinic_visit_id', $parent->id)->count())->toBe(1);
});

it('rejects control visit without follow_up_of_visit_id', function () {
    $this->actingAs($this->manager)
        ->post(route('rme.visits.store'), [
            'branch_id' => $this->rmeBranch->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
            'visit_type' => ClinicVisit::VISIT_TYPE_CONTROL,
        ])
        ->assertSessionHasErrors('follow_up_of_visit_id');
});

it('rejects follow_up_of_visit_id from different patient', function () {
    $parent = makeParentVisit($this->patient, $this->rmeBranch, $this->doctor, $this->treatment);
    $otherPatient = Patient::factory()->create();

    $this->actingAs($this->manager)
        ->post(route('rme.visits.store'), [
            'branch_id' => $this->rmeBranch->id,
            'patient_id' => $otherPatient->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
            'visit_type' => ClinicVisit::VISIT_TYPE_CONTROL,
            'follow_up_of_visit_id' => $parent->id,
        ])
        ->assertSessionHasErrors('follow_up_of_visit_id');
});

it('rejects control visit in new patient mode', function () {
    $parent = makeParentVisit($this->patient, $this->rmeBranch, $this->doctor, $this->treatment);

    $this->actingAs(userWith(['manage_clinic_visits', 'manage patients']))
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'new',
            'visit_type' => ClinicVisit::VISIT_TYPE_CONTROL,
            'follow_up_of_visit_id' => $parent->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
            'new_patient' => [
                'name' => 'Pasien Baru Kontrol',
                'branch_id' => $this->rmeBranch->id,
                'manual_rm_number' => '0099',
            ],
        ])
        ->assertSessionHasErrors('visit_type');
});

it('rejects follow_up visit from non-rme branch', function () {
    $parent = ClinicVisit::factory()->create([
        'branch_id' => $this->nonRmeBranch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'queue_number' => 1,
        'visit_number' => 'VIS-NONRME-'.uniqid(),
        'created_by' => $this->manager->id,
    ]);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.store'), [
            'branch_id' => $this->rmeBranch->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
            'visit_type' => ClinicVisit::VISIT_TYPE_CONTROL,
            'follow_up_of_visit_id' => $parent->id,
        ])
        ->assertSessionHasErrors('follow_up_of_visit_id');
});

it('shows visit type select on create page', function () {
    $this->actingAs($this->manager)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->assertSee('Jenis Kunjungan')
        ->assertSee('Kontrol');
});

it('prefills control visit create form from query parameters', function () {
    $parent = makeParentVisit($this->patient, $this->rmeBranch, $this->doctor, $this->treatment);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.create', [
            'patient_id' => $this->patient->id,
            'visit_type' => ClinicVisit::VISIT_TYPE_CONTROL,
            'follow_up_of_visit_id' => $parent->id,
            'branch_id' => $this->rmeBranch->id,
        ]))
        ->assertOk()
        ->assertSee('value="control"', false)
        ->assertSee((string) $this->patient->id, false);
});

it('shows patient visit history and create control action on visit show', function () {
    $parent = makeParentVisit($this->patient, $this->rmeBranch, $this->doctor, $this->treatment);
    $control = ClinicVisit::factory()->create([
        'branch_id' => $this->rmeBranch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'visit_type' => ClinicVisit::VISIT_TYPE_CONTROL,
        'follow_up_of_visit_id' => $parent->id,
        'queue_number' => 2,
        'visit_number' => 'VIS-CTRL-'.uniqid(),
        'created_by' => $this->manager->id,
    ]);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.show', $control))
        ->assertOk()
        ->assertSee('Riwayat Kunjungan Pasien')
        ->assertSee($parent->visit_number)
        ->assertSee('Kontrol dari')
        ->assertSee('Buat Kontrol');

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.show', $parent))
        ->assertOk()
        ->assertSee('Riwayat Kunjungan Pasien')
        ->assertDontSee('Buat Kontrol');
});

it('shows previous visit reference on control medical record page without mutating old record', function () {
    $parent = makeParentVisit($this->patient, $this->rmeBranch, $this->doctor, $this->treatment);
    $parentMr = MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $parent->id,
        'branch_id' => $this->rmeBranch->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'notes' => 'RME final lama',
    ]);

    $control = ClinicVisit::factory()->create([
        'branch_id' => $this->rmeBranch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'visit_type' => ClinicVisit::VISIT_TYPE_CONTROL,
        'follow_up_of_visit_id' => $parent->id,
        'queue_number' => 2,
        'visit_number' => 'VIS-CTRLMR-'.uniqid(),
        'created_by' => $this->manager->id,
    ]);
    MedicalRecord::factory()->create([
        'clinic_visit_id' => $control->id,
        'branch_id' => $this->rmeBranch->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
    ]);

    // Sprint 64.0 — opening a later (control) visit's RM redirects to the
    // patient's canonical workspace with the control as the active sheet, so the
    // control header (Jenis Kunjungan / Kontrol dari) still renders.
    $this->actingAs($this->manager)
        ->followingRedirects()
        ->get(route('rme.visits.medical-record.show', $control))
        ->assertOk()
        ->assertSee('Jenis Kunjungan:')
        ->assertSee('Kontrol dari:')
        ->assertSee($parent->visit_number)
        // Sprint 59.2 — the patient visit history section was removed from the
        // Medical Record page; the parent visit reference now comes only from
        // the page header (Kontrol dari / visit number above).
        ->assertDontSee('Riwayat Kunjungan Pasien');

    expect($parentMr->fresh()->notes)->toBe('RME final lama')
        ->and($parentMr->fresh()->status)->toBe(MedicalRecord::STATUS_FINAL);
});

it('shows previous odontogram reference on control odontogram without mutating parent', function () {
    $parent = makeParentVisit($this->patient, $this->rmeBranch, $this->doctor, $this->treatment);
    $parentOdontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $parent->id,
        'branch_id' => $this->rmeBranch->id,
        'summary_notes' => 'Odontogram lama',
    ]);

    $control = ClinicVisit::factory()->create([
        'branch_id' => $this->rmeBranch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'visit_type' => ClinicVisit::VISIT_TYPE_CONTROL,
        'follow_up_of_visit_id' => $parent->id,
        'queue_number' => 2,
        'visit_number' => 'VIS-CTRLODO-'.uniqid(),
        'created_by' => $this->manager->id,
    ]);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.odontogram.show', $control))
        ->assertOk()
        ->assertSee('Odontogram Kunjungan Sebelumnya')
        ->assertSee($parent->visit_number);

    expect($parentOdontogram->fresh()->summary_notes)->toBe('Odontogram lama');
});

it('allows control visit cashier flow and does not auto-mutate parent invoice', function () {
    $cashier = userWith(['manage_rme_billing', 'view_clinic_visits']);

    $parent = ClinicVisit::factory()->cashierPending()->create([
        'branch_id' => $this->rmeBranch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'queue_number' => 1,
        'visit_number' => 'VIS-PINV-'.uniqid(),
        'created_by' => $this->manager->id,
    ]);
    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $parent->id,
        'branch_id' => $this->rmeBranch->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
    ]);
    $parentInvoice = RmeInvoice::factory()->create([
        'clinic_visit_id' => $parent->id,
        'branch_id' => $this->rmeBranch->id,
        'patient_id' => $this->patient->id,
        'status' => RmeInvoice::STATUS_PARTIAL,
        'grand_total' => 500000,
    ]);

    $control = ClinicVisit::factory()->cashierPending()->create([
        'branch_id' => $this->rmeBranch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'visit_type' => ClinicVisit::VISIT_TYPE_CONTROL,
        'follow_up_of_visit_id' => $parent->id,
        'queue_number' => 2,
        'visit_number' => 'VIS-CINV-'.uniqid(),
        'created_by' => $this->manager->id,
    ]);
    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $control->id,
        'branch_id' => $this->rmeBranch->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
    ]);
    $controlInvoice = RmeInvoice::factory()->create([
        'clinic_visit_id' => $control->id,
        'branch_id' => $this->rmeBranch->id,
        'patient_id' => $this->patient->id,
        'status' => RmeInvoice::STATUS_UNPAID,
        'grand_total' => 0,
    ]);

    $this->actingAs($cashier)
        ->get(route('rme.cashier.show', [$control, $controlInvoice]))
        ->assertOk()
        ->assertSee('Piutang Kunjungan Sebelumnya')
        ->assertSee($parentInvoice->invoice_number);

    expect($parentInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_PARTIAL)
        ->and((float) $parentInvoice->fresh()->grand_total)->toBe(500000.0);
});

it('returns patient visit options json for dropdown', function () {
    $parent = makeParentVisit($this->patient, $this->rmeBranch, $this->doctor, $this->treatment);

    $this->actingAs($this->viewer)
        ->getJson(route('rme.visits.patient-options', ['patient_id' => $this->patient->id]))
        ->assertOk()
        ->assertJsonPath('visits.0.id', $parent->id)
        ->assertJsonPath('visits.0.visit_number', $parent->visit_number);
});
