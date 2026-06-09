<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use Database\Seeders\BranchSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
});

it('medical record factory can create record', function () {
    $record = MedicalRecord::factory()->create();

    expect($record)->toBeInstanceOf(MedicalRecord::class)
        ->and($record->id)->toBeInt()
        ->and($record->clinic_visit_id)->toBeInt()
        ->and($record->branch_id)->toBeInt()
        ->and($record->patient_id)->toBeInt()
        ->and($record->doctor_id)->toBeInt();
});

it('medical record default status is draft', function () {
    $record = MedicalRecord::factory()->create();

    expect($record->status)->toBe(MedicalRecord::STATUS_DRAFT);
});

it('clinic visit has one medical record relation works', function () {
    $visit = ClinicVisit::factory()->create();
    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    $loaded = $visit->medicalRecord;

    expect($loaded)->toBeInstanceOf(MedicalRecord::class)
        ->and($loaded->id)->toBe($record->id);
});

it('medical record belongs to clinic visit relation works', function () {
    $visit = ClinicVisit::factory()->create();
    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    $loaded = $record->clinicVisit;

    expect($loaded)->toBeInstanceOf(ClinicVisit::class)
        ->and($loaded->id)->toBe($visit->id);
});

it('medical record can be final using factory state', function () {
    $record = MedicalRecord::factory()->final()->create();

    expect($record->status)->toBe(MedicalRecord::STATUS_FINAL);
});

// --- Service layer tests (Sprint 20 Phase 1.2.2) ---

it('service can create draft medical record for clinic visit', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);

    $record = app(MedicalRecordService::class)->createDraft($visit);

    expect($record)->toBeInstanceOf(MedicalRecord::class)
        ->and($record->status)->toBe(MedicalRecord::STATUS_DRAFT)
        ->and($record->clinic_visit_id)->toBe($visit->id);
});

it('createDraft copies branch_id, patient_id, doctor_id from clinic visit', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);

    $record = app(MedicalRecordService::class)->createDraft($visit);

    expect($record->branch_id)->toBe($visit->branch_id)
        ->and($record->patient_id)->toBe($visit->patient_id)
        ->and($record->doctor_id)->toBe($visit->doctor_id);
});

it('createDraft ignores unsafe branch_id, patient_id, doctor_id, status, recorded_by in data', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);

    $record = app(MedicalRecordService::class)->createDraft($visit, null, [
        'branch_id' => 9999,
        'patient_id' => 9999,
        'doctor_id' => 9999,
        'status' => MedicalRecord::STATUS_FINAL,
        'recorded_by' => 9999,
        'subjective' => 'keluhan nyeri',
    ]);

    expect($record->branch_id)->toBe($visit->branch_id)
        ->and($record->patient_id)->toBe($visit->patient_id)
        ->and($record->doctor_id)->toBe($visit->doctor_id)
        ->and($record->status)->toBe(MedicalRecord::STATUS_DRAFT)
        ->and($record->recorded_by)->toBeNull()
        ->and($record->subjective)->toBe('keluhan nyeri');
});

it('createDraft prevents duplicate medical record for same visit', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);

    $service = app(MedicalRecordService::class);
    $service->createDraft($visit);

    expect(fn () => $service->createDraft($visit))
        ->toThrow(ValidationException::class);
});

it('service can finalize draft medical record', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);

    $service = app(MedicalRecordService::class);
    $draft = $service->createDraft($visit);

    $final = $service->finalize($draft);

    expect($final->status)->toBe(MedicalRecord::STATUS_FINAL);
});

it('finalize is idempotent when record is already final', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);

    $service = app(MedicalRecordService::class);
    $draft = $service->createDraft($visit);
    $service->finalize($draft);

    $finalAgain = $service->finalize($draft->refresh());

    expect($finalAgain->status)->toBe(MedicalRecord::STATUS_FINAL);
});

it('finalize rejects record from another branch', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $otherBranch = Branch::factory()->create();
    $record = MedicalRecord::factory()->create(['branch_id' => $otherBranch->id]);

    expect(fn () => app(MedicalRecordService::class)->finalize($record))
        ->toThrow(ValidationException::class);
});

// --- Service: updateDraft (Sprint 20 Phase 1.2.3) ---

it('updateDraft updates SOAP fields on draft record', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);
    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    $updated = app(MedicalRecordService::class)->updateDraft($record, [
        'subjective' => 'nyeri gigi',
        'objective' => 'bengkak',
    ]);

    expect($updated->subjective)->toBe('nyeri gigi')
        ->and($updated->objective)->toBe('bengkak');
});

it('updateDraft ignores unsafe fields', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);
    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    $updated = app(MedicalRecordService::class)->updateDraft($record, [
        'subjective' => 'nyeri',
        'branch_id' => 9999,
        'status' => MedicalRecord::STATUS_FINAL,
    ]);

    expect($updated->branch_id)->toBe($branch->id)
        ->and($updated->status)->toBe(MedicalRecord::STATUS_DRAFT);
});

it('updateDraft rejects final record', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    // Let factory create its own visit to avoid visit_number collision within the same test
    $record = MedicalRecord::factory()->final()->create(['branch_id' => $branch->id]);

    expect(fn () => app(MedicalRecordService::class)->updateDraft($record, ['subjective' => 'nyeri']))
        ->toThrow(ValidationException::class);
});

// --- HTTP layer (Sprint 20 Phase 1.2.3) ---

it('manager can store medical record for clinic visit', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);

    $this->actingAs($manager)
        ->post(route('rme.visits.medical-record.store', $visit), [
            'subjective' => 'keluhan nyeri gigi',
        ])
        ->assertRedirect(route('rme.visits.medical-record.show', $visit));

    expect(MedicalRecord::where('clinic_visit_id', $visit->id)->exists())->toBeTrue();
});

it('store ignores unsafe fields through request and service', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);

    $this->actingAs($manager)
        ->post(route('rme.visits.medical-record.store', $visit), [
            'subjective' => 'keluhan',
            'branch_id' => 9999,
            'status' => MedicalRecord::STATUS_FINAL,
        ])
        ->assertRedirect();

    $record = MedicalRecord::where('clinic_visit_id', $visit->id)->firstOrFail();
    expect($record->branch_id)->toBe($branch->id)
        ->and($record->status)->toBe(MedicalRecord::STATUS_DRAFT);
});

it('viewer cannot store medical record', function () {
    $viewer = userWith(['view_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);

    $this->actingAs($viewer)
        ->post(route('rme.visits.medical-record.store', $visit))
        ->assertForbidden();
});

it('user from another branch cannot store medical record', function () {
    $manager = userWith(['manage_clinic_visits']);
    $otherBranch = Branch::factory()->create();
    $visit = ClinicVisit::factory()->create(['branch_id' => $otherBranch->id]);

    $this->actingAs($manager)
        ->post(route('rme.visits.medical-record.store', $visit))
        ->assertForbidden();
});

it('manager can update draft medical record', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);
    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.visits.medical-record.update', [$visit, $record]), [
            'subjective' => 'nyeri diperbarui',
        ])
        ->assertRedirect(route('rme.visits.medical-record.show', $visit));

    expect($record->fresh()->subjective)->toBe('nyeri diperbarui');
});

it('manager cannot update final medical record', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    // Use factory's own visit so no visit_number collision within the same test
    $record = MedicalRecord::factory()->final()->create(['branch_id' => $branch->id]);
    $visit = $record->clinicVisit;

    $this->actingAs($manager)
        ->from(route('rme.visits.medical-record.show', $visit))
        ->patch(route('rme.visits.medical-record.update', [$visit, $record]), [
            'subjective' => 'coba ubah',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors(['status']);
});

it('manager can finalize draft medical record', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);
    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    $this->actingAs($manager)
        ->post(route('rme.visits.medical-record.finalize', [$visit, $record]))
        ->assertRedirect(route('rme.visits.medical-record.show', $visit));

    expect($record->fresh()->status)->toBe(MedicalRecord::STATUS_FINAL);
});

it('nested medical record route rejects mismatched visit and record', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);
    $otherVisit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);
    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $otherVisit->id,
        'branch_id' => $branch->id,
        'patient_id' => $otherVisit->patient_id,
        'doctor_id' => $otherVisit->doctor_id,
    ]);

    $this->actingAs($manager)
        ->patch(route('rme.visits.medical-record.update', [$visit, $record]))
        ->assertNotFound();
});

it('manager can view medical record show page', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);
    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    $this->actingAs($manager)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk();
});
