<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\Patient\Models\Patient;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Carbon;
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

it('createDraft creates medical record without SOAP fields', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);

    $record = app(MedicalRecordService::class)->createDraft($visit);

    expect($record->subjective)->toBeNull()
        ->and($record->objective)->toBeNull()
        ->and($record->assessment)->toBeNull()
        ->and($record->plan)->toBeNull()
        ->and($record->notes)->toBeNull();
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

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $draft->id,
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

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

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $draft->id,
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

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

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $record->id,
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
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

// --- UI tests (Sprint 20 Phase 1.2.4) ---

it('clinic visit show displays create medical record action when no record exists', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);

    $this->actingAs($manager)
        ->get(route('rme.visits.show', $visit))
        ->assertOk()
        ->assertSee('Rekam medis belum dibuat.')
        ->assertSee('Buat Rekam Medis');
});

it('clinic visit show displays view medical record action when record exists', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);
    MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    $this->actingAs($manager)
        ->get(route('rme.visits.show', $visit))
        ->assertOk()
        ->assertSee('Lihat Rekam Medis')
        ->assertDontSee('Buat Rekam Medis');
});

it('doctor-facing show page hides SOAP editable fields for draft record', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);
    MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    $this->actingAs($manager)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertDontSee('Simpan Draft')
        ->assertDontSee('name="subjective"', false)
        ->assertDontSee('name="objective"', false)
        ->assertDontSee('name="assessment"', false)
        ->assertDontSee('name="plan"', false)
        ->assertSee('id="rme-canvas"', false)
        ->assertSee('Simpan Tulisan Tangan')
        ->assertSee('Bersihkan')
        ->assertSee('Isi Rekam Medis lengkap');
});

it('medical record show page hides draft form for final record', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $record = MedicalRecord::factory()->final()->create(['branch_id' => $branch->id]);
    $visit = $record->clinicVisit;

    $this->actingAs($manager)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertDontSee('Simpan Draft')
        ->assertDontSee('name="subjective"', false)
        ->assertDontSee('Data SOAP (Legacy)');
});

it('finalized show page hides empty SOAP labels', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $record = MedicalRecord::factory()->final()->create(['branch_id' => $branch->id]);
    $visit = $record->clinicVisit;

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $record->id,
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $this->actingAs($manager)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('RME Tulisan Tangan')
        ->assertDontSee('Subjective')
        ->assertDontSee('Objective')
        ->assertDontSee('Assessment');
});

it('medical record show page displays finalize action for draft record', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);
    MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    $this->actingAs($manager)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('Finalisasi');
});

it('medical record show page hides finalize action for final record', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $record = MedicalRecord::factory()->final()->create(['branch_id' => $branch->id]);
    $visit = $record->clinicVisit;

    $this->actingAs($manager)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertDontSee('Finalisasi');
});

it('manager can update SOAP fields from show page', function () {
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
            'subjective' => 'nyeri kepala',
            'objective' => 'tekanan darah tinggi',
            'assessment' => 'hipertensi',
            'plan' => 'resepkan obat',
        ])
        ->assertRedirect(route('rme.visits.medical-record.show', $visit));

    $this->assertDatabaseHas('trx_medical_records', [
        'id' => $record->id,
        'subjective' => 'nyeri kepala',
        'objective' => 'tekanan darah tinggi',
        'assessment' => 'hipertensi',
        'plan' => 'resepkan obat',
    ]);
});

it('final record remains read-only from UI perspective', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $record = MedicalRecord::factory()->final()->create([
        'branch_id' => $branch->id,
        'subjective' => 'original subjective',
    ]);
    $visit = $record->clinicVisit;

    $this->actingAs($manager)
        ->patch(route('rme.visits.medical-record.update', [$visit, $record]), [
            'subjective' => 'coba ubah paksa',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors(['status']);

    $this->assertDatabaseHas('trx_medical_records', [
        'id' => $record->id,
        'subjective' => 'original subjective',
    ]);
});

// --- Metadata & finalization polish (Sprint 20 Phase 1.2.5) ---

it('finalize sets finalized_at', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);

    $service = app(MedicalRecordService::class);
    $draft = $service->createDraft($visit);

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $draft->id,
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $final = $service->finalize($draft);

    expect($final->finalized_at)->not->toBeNull();
    expect($final->fresh()->finalized_at)->not->toBeNull();
    $this->assertDatabaseHas('trx_medical_records', [
        'id' => $final->id,
        'status' => MedicalRecord::STATUS_FINAL,
    ]);
});

it('finalize does not overwrite finalized_at when already final', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);

    $service = app(MedicalRecordService::class);
    $draft = $service->createDraft($visit);

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $draft->id,
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    $first = $service->finalize($draft);
    $originalFinalizedAt = $first->fresh()->finalized_at;

    Carbon::setTestNow(now()->addHour());

    $service->finalize($first->fresh());

    Carbon::setTestNow();

    expect($first->fresh()->finalized_at->toDateTimeString())
        ->toBe($originalFinalizedAt->toDateTimeString());
});

it('show page displays recorded_by and created_at metadata', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);
    MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
        'recorded_by' => $manager->id,
    ]);

    $this->actingAs($manager)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('Dicatat oleh')
        ->assertSee($manager->name)
        ->assertSee('Dibuat pada');
});

it('final show page displays finalized_at', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();

    $finalizedAt = Carbon::parse('2026-01-15 09:30:00');
    $record = MedicalRecord::factory()->final()->create([
        'branch_id' => $branch->id,
        'finalized_at' => $finalizedAt,
    ]);
    $visit = $record->clinicVisit;

    $this->actingAs($manager)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('Difinalisasi pada')
        ->assertSee('15/01/2026 09:30');
});

// ---------------------------------------------------------------------------
// Phase 1.2.6 — Medical Record Index / Search
// ---------------------------------------------------------------------------

it('manager can view medical record index page', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    MedicalRecord::factory()->create(['branch_id' => $branch->id]);

    $this->actingAs($manager)
        ->get(route('rme.medical-records.index'))
        ->assertOk();
});

it('viewer can view medical record index page', function () {
    $viewer = userWith(['view_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    MedicalRecord::factory()->create(['branch_id' => $branch->id]);

    $this->actingAs($viewer)
        ->get(route('rme.medical-records.index'))
        ->assertOk();
});

it('user without permission cannot view medical record index', function () {
    $user = userWith([]);

    $this->actingAs($user)
        ->get(route('rme.medical-records.index'))
        ->assertForbidden();
});

it('index only shows records from active branch', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $otherBranch = Branch::factory()->create();

    $patient = Patient::factory()->create(['name' => 'Pasien Aktif']);
    $otherPatient = Patient::factory()->create(['name' => 'Pasien Lain']);

    MedicalRecord::factory()->create(['branch_id' => $branch->id, 'patient_id' => $patient->id]);
    MedicalRecord::factory()->create(['branch_id' => $otherBranch->id, 'patient_id' => $otherPatient->id]);

    $this->actingAs($manager)
        ->get(route('rme.medical-records.index'))
        ->assertOk()
        ->assertSee('Pasien Aktif')
        ->assertDontSee('Pasien Lain');
});

it('index can filter by status draft', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();

    $patient = Patient::factory()->create(['name' => 'Pasien Draft']);
    $patientFinal = Patient::factory()->create(['name' => 'Pasien Final']);

    MedicalRecord::factory()->create(['branch_id' => $branch->id, 'patient_id' => $patient->id, 'status' => MedicalRecord::STATUS_DRAFT]);
    MedicalRecord::factory()->final()->create(['branch_id' => $branch->id, 'patient_id' => $patientFinal->id]);

    $this->actingAs($manager)
        ->get(route('rme.medical-records.index', ['status' => 'draft']))
        ->assertOk()
        ->assertSee('Pasien Draft')
        ->assertDontSee('Pasien Final');
});

it('index can filter by status final', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();

    $patient = Patient::factory()->create(['name' => 'Pasien Draft']);
    $patientFinal = Patient::factory()->create(['name' => 'Pasien Final']);

    MedicalRecord::factory()->create(['branch_id' => $branch->id, 'patient_id' => $patient->id, 'status' => MedicalRecord::STATUS_DRAFT]);
    MedicalRecord::factory()->final()->create(['branch_id' => $branch->id, 'patient_id' => $patientFinal->id]);

    $this->actingAs($manager)
        ->get(route('rme.medical-records.index', ['status' => 'final']))
        ->assertOk()
        ->assertSee('Pasien Final')
        ->assertDontSee('Pasien Draft');
});

it('index can search by patient name', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();

    $patient = Patient::factory()->create(['name' => 'Budi Santoso']);
    $otherPatient = Patient::factory()->create(['name' => 'Siti Rahayu']);

    MedicalRecord::factory()->create(['branch_id' => $branch->id, 'patient_id' => $patient->id]);
    MedicalRecord::factory()->create(['branch_id' => $branch->id, 'patient_id' => $otherPatient->id]);

    $this->actingAs($manager)
        ->get(route('rme.medical-records.index', ['search' => 'budi']))
        ->assertOk()
        ->assertSee('Budi Santoso')
        ->assertDontSee('Siti Rahayu');
});

it('index can search by doctor name', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();

    $doctor = Doctor::factory()->create(['name' => 'Dr. Hendra']);
    $otherDoctor = Doctor::factory()->create(['name' => 'Dr. Wulan']);

    MedicalRecord::factory()->create(['branch_id' => $branch->id, 'doctor_id' => $doctor->id]);
    MedicalRecord::factory()->create(['branch_id' => $branch->id, 'doctor_id' => $otherDoctor->id]);

    $this->actingAs($manager)
        ->get(route('rme.medical-records.index', ['search' => 'hendra']))
        ->assertOk()
        ->assertSee('Dr. Hendra')
        ->assertDontSee('Dr. Wulan');
});

it('index can filter by visit date range', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();

    $patient = Patient::factory()->create(['name' => 'Pasien Januari']);
    $patientMarch = Patient::factory()->create(['name' => 'Pasien Maret']);

    $visitJan = ClinicVisit::factory()->create([
        'branch_id' => $branch->id,
        'visit_date' => '2026-01-15',
        'patient_id' => $patient->id,
    ]);
    $visitMar = ClinicVisit::factory()->create([
        'branch_id' => $branch->id,
        'visit_date' => '2026-03-20',
        'patient_id' => $patientMarch->id,
    ]);

    MedicalRecord::factory()->create(['branch_id' => $branch->id, 'clinic_visit_id' => $visitJan->id, 'patient_id' => $patient->id]);
    MedicalRecord::factory()->create(['branch_id' => $branch->id, 'clinic_visit_id' => $visitMar->id, 'patient_id' => $patientMarch->id]);

    $this->actingAs($manager)
        ->get(route('rme.medical-records.index', ['visit_date_from' => '2026-01-01', 'visit_date_to' => '2026-01-31']))
        ->assertOk()
        ->assertSee('Pasien Januari')
        ->assertDontSee('Pasien Maret');
});

it('index shows empty state when no records exist', function () {
    $manager = userWith(['manage_clinic_visits']);

    $this->actingAs($manager)
        ->get(route('rme.medical-records.index'))
        ->assertOk()
        ->assertSee('Belum ada rekam medis.');
});

it('index links to medical record show page', function () {
    $manager = userWith(['manage_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();

    $record = MedicalRecord::factory()->create(['branch_id' => $branch->id]);
    $visit = $record->clinicVisit;

    $this->actingAs($manager)
        ->get(route('rme.medical-records.index'))
        ->assertOk()
        ->assertSee(route('rme.visits.medical-record.show', $visit));
});

// --- Phase 1.2.9 — Permission UI & read-only viewer ---

it('viewer can view medical record show page', function () {
    $viewer = userWith(['view_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);
    MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    $this->actingAs($viewer)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('Rekam Medis');
});

it('viewer sees legacy read-only SOAP when data exists on draft record show page', function () {
    $viewer = userWith(['view_clinic_visits']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);
    MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
        'subjective' => 'Pasien mengeluh pusing',
        'objective' => 'TD 120/80',
        'assessment' => 'Hipertensi ringan',
        'plan' => 'Istirahat cukup',
    ]);

    $this->actingAs($viewer)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('Pasien mengeluh pusing')
        ->assertSee('TD 120/80')
        ->assertDontSee('name="subjective"', false)
        ->assertDontSee('name="objective"', false)
        ->assertDontSee('Simpan Draft')
        ->assertDontSee('Finalisasi');
});
