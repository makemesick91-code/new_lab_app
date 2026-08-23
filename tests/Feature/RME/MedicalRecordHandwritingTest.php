<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\Patient\Models\Patient;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Storage::fake('public');
    Storage::fake('clinical_evidence');

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->manager = userWith(['manage_clinic_visits']);
    $this->viewer = userWith(['view_clinic_visits']);

    $this->clinic = Clinic::factory()->create();
    $this->patient = Patient::factory()->create();
    $this->doctor = Doctor::factory()->create();

    $this->visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
    ]);

    // FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / CORRECTIVE-02 — writing a
    // patient's RME now requires a legitimate ACTIVE encounter: in_progress plus a
    // signed Persetujuan Tindakan Medis. These tests are about medical-record
    // mechanics, not the gate, so the fixture establishes that encounter. The gate
    // itself is under test in RmeExamConsentCorrectiveTest.
    rmeActiveConsentedEncounter($this->visit);

    $this->record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
    ]);
});

// Minimal valid PNG base64 (10x10, has drawn content — treated as real handwriting)
function validHandwritingData(): string
{
    return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC';
}

// Fully transparent 20x20 RGBA PNG — the output of a blank/cleared canvas (Sprint 59.1)
function blankHandwritingData(): string
{
    return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABQAAAAUCAYAAACNiR0NAAAAFUlEQVR4nGNgGAWjYBSMglEwCqgDAAZUAAGDHP/NAAAAAElFTkSuQmCC';
}

it('authorized user can save handwriting', function () {
    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.handwriting.store', [$this->visit, $this->record]), [
            'handwriting_data' => validHandwritingData(),
        ])
        ->assertRedirect(route('rme.visits.medical-record.show', $this->visit));

    expect(MedicalRecordHandwriting::where('medical_record_id', $this->record->id)->exists())->toBeTrue();
});

it('saving handwriting preserves existing SOAP data', function () {
    $this->record->update([
        'subjective' => 'keluhan sebelumnya',
        'assessment' => 'diagnosis lama',
    ]);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.handwriting.store', [$this->visit, $this->record]), [
            'handwriting_data' => validHandwritingData(),
        ])
        ->assertRedirect(route('rme.visits.medical-record.show', $this->visit));

    $fresh = $this->record->fresh();
    expect($fresh->subjective)->toBe('keluhan sebelumnya')
        ->and($fresh->assessment)->toBe('diagnosis lama');
});

it('saved handwriting is linked to the correct medical record and visit', function () {
    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.handwriting.store', [$this->visit, $this->record]), [
            'handwriting_data' => validHandwritingData(),
        ]);

    $hw = MedicalRecordHandwriting::where('medical_record_id', $this->record->id)->first();

    expect($hw)->not->toBeNull()
        ->and($hw->clinic_visit_id)->toBe($this->visit->id)
        ->and($hw->branch_id)->toBe($this->branch->id);
});

it('empty handwriting_data is rejected', function () {
    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.handwriting.store', [$this->visit, $this->record]), [
            'handwriting_data' => '',
        ])
        ->assertSessionHasErrors(['handwriting_data']);
});

it('missing handwriting_data is rejected', function () {
    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.handwriting.store', [$this->visit, $this->record]), [])
        ->assertSessionHasErrors(['handwriting_data']);
});

it('invalid base64 is rejected', function () {
    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.handwriting.store', [$this->visit, $this->record]), [
            'handwriting_data' => 'not-valid-base64!!!',
        ])
        ->assertSessionHasErrors(['handwriting_data']);
});

it('viewer cannot save handwriting', function () {
    $this->actingAs($this->viewer)
        ->post(route('rme.visits.medical-record.handwriting.store', [$this->visit, $this->record]), [
            'handwriting_data' => validHandwritingData(),
        ])
        ->assertForbidden();
});

it('can save handwriting on finalized medical record (Sprint 59)', function () {
    $this->record->update([
        'status' => MedicalRecord::STATUS_FINAL,
        'finalized_at' => now(),
    ]);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.handwriting.store', [$this->visit, $this->record]), [
            'handwriting_data' => validHandwritingData(),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->record->fresh()->hasHandwriting())->toBeTrue();
});

it('hasHandwriting returns false when no handwriting exists', function () {
    expect($this->record->hasHandwriting())->toBeFalse();
});

it('hasHandwriting returns true after saving', function () {
    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $this->record->id,
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $this->branch->id,
    ]);

    expect($this->record->fresh()->hasHandwriting())->toBeTrue();
});

it('canFinalizeRme returns false for finalized record', function () {
    $service = app(MedicalRecordService::class);

    $this->record->update(['status' => MedicalRecord::STATUS_FINAL, 'finalized_at' => now()]);

    expect($service->canFinalizeRme($this->record->fresh()))->toBeFalse();
});

it('canFinalizeRme returns true for draft record with handwriting', function () {
    $service = app(MedicalRecordService::class);

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $this->record->id,
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $this->branch->id,
    ]);

    expect($service->canFinalizeRme($this->record->fresh()))->toBeTrue();
});

it('canFinalizeRme returns false for draft record without handwriting', function () {
    $service = app(MedicalRecordService::class);

    expect($service->canFinalizeRme($this->record))->toBeFalse();
});

it('hasRequiredHandwriting returns false when no handwriting', function () {
    $service = app(MedicalRecordService::class);

    expect($service->hasRequiredHandwriting($this->record))->toBeFalse();
});

it('hasRequiredHandwriting returns true when handwriting exists', function () {
    $service = app(MedicalRecordService::class);

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $this->record->id,
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $this->branch->id,
    ]);

    expect($service->hasRequiredHandwriting($this->record->fresh()))->toBeTrue();
});

it('draft show page displays saved handwriting preview', function () {
    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.handwriting.store', [$this->visit, $this->record]), [
            'handwriting_data' => validHandwritingData(),
        ]);

    $handwriting = MedicalRecordHandwriting::where('medical_record_id', $this->record->id)->firstOrFail();

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $this->visit))
        ->assertOk()
        ->assertSee('RME Tulisan Tangan Lengkap')
        ->assertSee('Tersimpan pada')
        ->assertSee($handwriting->previewUrl(), false)
        ->assertSee('id="rme-canvas"', false)
        ->assertSee('Simpan Tulisan Tangan');
});

it('final show page displays saved handwriting preview and keeps the canvas editable (Sprint 59)', function () {
    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.handwriting.store', [$this->visit, $this->record]), [
            'handwriting_data' => validHandwritingData(),
        ]);

    app(MedicalRecordService::class)->finalize($this->record->fresh());

    $handwriting = MedicalRecordHandwriting::where('medical_record_id', $this->record->id)->firstOrFail();

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $this->visit))
        ->assertOk()
        ->assertSee('RME Tulisan Tangan')
        ->assertSee('Tersimpan pada')
        ->assertSee($handwriting->previewUrl(), false)
        // Sprint 59 — handwriting remains revisable after finalization.
        ->assertSee('id="rme-canvas"', false)
        ->assertSee('Simpan Tulisan Tangan');
});

it('show page displays empty handwriting message when none exists', function () {
    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $this->visit))
        ->assertOk()
        ->assertSee('Belum ada handwriting RM. Silakan isi dan simpan tulisan tangan sebelum finalisasi.');
});

it('viewer sees handwriting preview on draft without edit canvas', function () {
    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $this->record->id,
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $this->branch->id,
        'handwriting_path' => 'handwritings/test/preview.png',
    ]);

    Storage::disk('clinical_evidence')->put('handwritings/test/preview.png', base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC',
        true
    ));

    $handwriting = MedicalRecordHandwriting::where('medical_record_id', $this->record->id)->firstOrFail();

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.medical-record.show', $this->visit))
        ->assertOk()
        ->assertSee('RME Tulisan Tangan')
        ->assertSee($handwriting->previewUrl(), false)
        ->assertDontSee('id="rme-canvas"', false)
        ->assertDontSee('Simpan Tulisan Tangan');
});

it('previewUrl supports data image paths', function () {
    $dataUrl = validHandwritingData();

    $handwriting = MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $this->record->id,
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $this->branch->id,
        'handwriting_path' => $dataUrl,
    ]);

    expect($handwriting->previewUrl())->toBe($dataUrl);
});

it('show page loads existing handwriting back into the editable canvas (Sprint 59.1)', function () {
    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.handwriting.store', [$this->visit, $this->record]), [
            'handwriting_data' => validHandwritingData(),
        ]);

    $handwriting = MedicalRecordHandwriting::where('medical_record_id', $this->record->id)->firstOrFail();

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $this->visit))
        ->assertOk()
        // The canvas carries the saved handwriting URL so JS can redraw it,
        // i.e. the canvas never opens blank when handwriting already exists.
        ->assertSee('data-existing-src="'.$handwriting->previewUrl().'"', false);
});

it('blank/transparent handwriting submission does not erase existing handwriting (Sprint 59.1)', function () {
    // Seed an existing saved handwriting.
    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.handwriting.store', [$this->visit, $this->record]), [
            'handwriting_data' => validHandwritingData(),
        ]);

    $existing = MedicalRecordHandwriting::where('medical_record_id', $this->record->id)->firstOrFail();
    $existingPath = $existing->handwriting_path;

    // Submitting a blank (fully transparent) canvas must be rejected and must
    // NOT overwrite the previously stored handwriting.
    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.handwriting.store', [$this->visit, $this->record]), [
            'handwriting_data' => blankHandwritingData(),
        ])
        ->assertSessionHasErrors(['handwriting_data']);

    $fresh = $this->record->fresh()->latestHandwriting();
    expect($fresh)->not->toBeNull()
        ->and($fresh->handwriting_path)->toBe($existingPath);
    expect(Storage::disk('clinical_evidence')->exists($existingPath))->toBeTrue();
});

it('blank handwriting submission is rejected even when none exists yet (Sprint 59.1)', function () {
    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.handwriting.store', [$this->visit, $this->record]), [
            'handwriting_data' => blankHandwritingData(),
        ])
        ->assertSessionHasErrors(['handwriting_data']);

    expect($this->record->fresh()->hasHandwriting())->toBeFalse();
});

it('blank submission does not erase handwriting on a finalized record (Sprint 59.1)', function () {
    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.handwriting.store', [$this->visit, $this->record]), [
            'handwriting_data' => validHandwritingData(),
        ]);

    app(MedicalRecordService::class)->finalize($this->record->fresh());

    $existing = MedicalRecordHandwriting::where('medical_record_id', $this->record->id)->firstOrFail();
    $existingPath = $existing->handwriting_path;

    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.handwriting.store', [$this->visit, $this->record]), [
            'handwriting_data' => blankHandwritingData(),
        ])
        ->assertSessionHasErrors(['handwriting_data']);

    expect($this->record->fresh()->latestHandwriting()->handwriting_path)->toBe($existingPath);
});

it('valid handwriting update overwrites the stored path with real content (Sprint 59.1)', function () {
    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.handwriting.store', [$this->visit, $this->record]), [
            'handwriting_data' => validHandwritingData(),
        ]);

    $firstPath = MedicalRecordHandwriting::where('medical_record_id', $this->record->id)->firstOrFail()->handwriting_path;

    // A second valid (merged old+new) submission replaces the stored image and
    // keeps non-blank content available.
    $this->actingAs($this->manager)
        ->post(route('rme.visits.medical-record.handwriting.store', [$this->visit, $this->record]), [
            'handwriting_data' => validHandwritingData(),
        ])
        ->assertSessionHasNoErrors();

    $latest = $this->record->fresh()->latestHandwriting();
    expect($latest)->not->toBeNull();
    expect(Storage::disk('clinical_evidence')->exists($latest->handwriting_path))->toBeTrue();
});

it('latestHandwriting returns the most recently saved record', function () {
    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $this->record->id,
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $this->branch->id,
        'handwriting_path' => 'handwritings/test/older.png',
        'saved_at' => now()->subHour(),
    ]);

    $latest = MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $this->record->id,
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $this->branch->id,
        'handwriting_path' => 'handwritings/test/newer.png',
        'saved_at' => now(),
    ]);

    expect($this->record->fresh()->latestHandwriting()?->id)->toBe($latest->id);
});
