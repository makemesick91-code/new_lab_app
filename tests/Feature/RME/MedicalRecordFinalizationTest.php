<?php

// Sprint 20 Phase 1.9 — RME Finalization Workflow tests

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use Database\Seeders\BranchSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->manager = userWith(['manage_clinic_visits', 'complete_rme_examination']);
    $this->viewer = userWith(['view_clinic_visits']);
});

// ─── Helpers ─────────────────────────────────────────────────────────────────

function makeVisitWithDraft(Branch $branch): array
{
    $visit = ClinicVisit::factory()->create(['branch_id' => $branch->id]);
    // FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-02 — writing a visit's RME
    // now requires a signed Persetujuan Tindakan Medis. These tests are not about
    // consent, so the fixture simply gives the visit one; the gate itself is under
    // test in RmeExamConsentOdontogramHistoryTest and RmeVisitConsentGateTest.
    rmeSignedConsentFor($visit);

    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    return [$visit, $record];
}

function addHandwriting(MedicalRecord $record, ClinicVisit $visit): MedicalRecordHandwriting
{
    return MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $record->id,
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
    ]);
}

// ─── Part A: Service layer — handwriting gate ─────────────────────────────────

it('service rejects finalization without handwriting', function () {
    $this->actingAs($this->manager);

    [$visit, $record] = makeVisitWithDraft($this->branch);

    expect(fn () => app(MedicalRecordService::class)->finalize($record))
        ->toThrow(ValidationException::class);
});

it('service allows finalization with handwriting present', function () {
    $this->actingAs($this->manager);

    [$visit, $record] = makeVisitWithDraft($this->branch);
    addHandwriting($record, $visit);

    $final = app(MedicalRecordService::class)->finalize($record);

    expect($final->status)->toBe(MedicalRecord::STATUS_FINAL);
});

it('finalization sets status to FINAL', function () {
    $this->actingAs($this->manager);

    [$visit, $record] = makeVisitWithDraft($this->branch);
    addHandwriting($record, $visit);

    app(MedicalRecordService::class)->finalize($record);

    $this->assertDatabaseHas('trx_medical_records', [
        'id' => $record->id,
        'status' => MedicalRecord::STATUS_FINAL,
    ]);
});

it('finalization sets finalized_at timestamp', function () {
    $this->actingAs($this->manager);

    [$visit, $record] = makeVisitWithDraft($this->branch);
    addHandwriting($record, $visit);

    $final = app(MedicalRecordService::class)->finalize($record);

    expect($final->finalized_at)->not->toBeNull();
    expect($final->fresh()->finalized_at)->not->toBeNull();
});

it('finalization sets finalized_by to authenticated user', function () {
    $this->actingAs($this->manager);

    [$visit, $record] = makeVisitWithDraft($this->branch);
    addHandwriting($record, $visit);

    app(MedicalRecordService::class)->finalize($record);

    $this->assertDatabaseHas('trx_medical_records', [
        'id' => $record->id,
        'finalized_by' => $this->manager->id,
    ]);
});

// ─── Part B: ClinicVisit status transition ────────────────────────────────────

it('finalization leaves an in_progress visit in_progress', function () {
    // SUPERSEDED by FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-01.
    // Completing a clinical DOCUMENT is not completing the EXAMINATION. Only the
    // doctor's explicit "Selesai Pemeriksaan" advances the visit. Inverted rather
    // than deleted, so a reintroduced implicit transition fails here.
    $this->actingAs($this->manager);

    $visit = ClinicVisit::factory()->inProgress()->create(['branch_id' => $this->branch->id]);
    rmeSignedConsentFor($visit);
    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);
    addHandwriting($record, $visit);

    app(MedicalRecordService::class)->finalize($record);

    $this->assertDatabaseHas('trx_clinic_visits', [
        'id' => $visit->id,
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ]);
});

it('finalization does not change visit status when not in_progress', function () {
    $this->actingAs($this->manager);

    // Visit in REGISTERED state (default factory)
    [$visit, $record] = makeVisitWithDraft($this->branch);
    addHandwriting($record, $visit);

    app(MedicalRecordService::class)->finalize($record);

    // Visit stays in REGISTERED — no forced transition
    $this->assertDatabaseHas('trx_clinic_visits', [
        'id' => $visit->id,
        'status' => ClinicVisit::STATUS_REGISTERED,
    ]);
});

// ─── Part C: Finalized RME remains editable (Sprint 59) ──────────────────────

it('finalized RME can still be edited via service updateDraft', function () {
    $this->actingAs($this->manager);

    [$visit, $record] = makeVisitWithDraft($this->branch);
    addHandwriting($record, $visit);

    $service = app(MedicalRecordService::class);
    $service->finalize($record);

    $updated = $service->updateDraft($record->fresh(), ['notes' => 'revisi setelah final']);

    expect($updated->notes)->toBe('revisi setelah final')
        // Status/finalized columns are preserved for backward compatibility.
        ->and($updated->status)->toBe(MedicalRecord::STATUS_FINAL)
        ->and($updated->finalized_at)->not->toBeNull();
});

it('finalized RME can still be edited via HTTP PATCH', function () {
    [$visit, $record] = makeVisitWithDraft($this->branch);
    addHandwriting($record, $visit);

    $this->actingAs($this->manager);
    app(MedicalRecordService::class)->finalize($record);

    $this->actingAs($this->manager)
        ->patch(route('rme.visits.medical-record.update', [$visit, $record->fresh()]), [
            'notes' => 'revisi via http',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($record->fresh()->notes)->toBe('revisi via http');
});

it('updating a finalized RME does not blank fields the doctor did not submit', function () {
    [$visit, $record] = makeVisitWithDraft($this->branch);
    $record->update(['subjective' => 'keluhan awal']);
    addHandwriting($record, $visit);

    $this->actingAs($this->manager);
    app(MedicalRecordService::class)->finalize($record);

    // Submit only notes — subjective must survive.
    app(MedicalRecordService::class)->updateDraft($record->fresh(), ['notes' => 'catatan baru']);

    $fresh = $record->fresh();
    expect($fresh->notes)->toBe('catatan baru')
        ->and($fresh->subjective)->toBe('keluhan awal');
});

// ─── Part D: Authorization ────────────────────────────────────────────────────

it('unauthorized user cannot finalize RME', function () {
    [$visit, $record] = makeVisitWithDraft($this->branch);
    addHandwriting($record, $visit);

    $this->actingAs($this->viewer)
        ->post(route('rme.visits.medical-record.finalize', [$visit, $record]))
        ->assertForbidden();
});

it('unauthenticated request is redirected from finalize route', function () {
    [$visit, $record] = makeVisitWithDraft($this->branch);

    $this->post(route('rme.visits.medical-record.finalize', [$visit, $record]))
        ->assertRedirect(route('login'));
});

// ─── Part E: No invoice/billing created on finalization ───────────────────────

it('finalization does not create any invoice or payment record', function () {
    $this->actingAs($this->manager);

    [$visit, $record] = makeVisitWithDraft($this->branch);
    addHandwriting($record, $visit);

    app(MedicalRecordService::class)->finalize($record);

    // Verify no billing tables were touched (tables may not exist yet — skip if absent)
    if (Schema::hasTable('trx_invoices')) {
        $this->assertDatabaseCount('trx_invoices', 0);
    }
    if (Schema::hasTable('trx_payments')) {
        $this->assertDatabaseCount('trx_payments', 0);
    }

    // Primary assertion: medical record is final
    expect($record->fresh()->status)->toBe(MedicalRecord::STATUS_FINAL);
});

// ─── Part F: Initial service unchanged after finalization ─────────────────────

it('initial service data is unchanged after finalization', function () {
    $this->actingAs($this->manager);

    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'initial_service_note' => 'Scaling dan polishing',
    ]);
    rmeSignedConsentFor($visit);

    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);
    addHandwriting($record, $visit);

    app(MedicalRecordService::class)->finalize($record);

    $this->assertDatabaseHas('trx_clinic_visits', [
        'id' => $visit->id,
        'initial_service_note' => 'Scaling dan polishing',
    ]);
});

// ─── Part G: Branch isolation ─────────────────────────────────────────────────

it('cannot finalize medical record from another branch', function () {
    $this->actingAs($this->manager);

    $otherBranch = Branch::factory()->create(['is_rme_enabled' => false]);
    $record = MedicalRecord::factory()->create(['branch_id' => $otherBranch->id]);

    expect(fn () => app(MedicalRecordService::class)->finalize($record))
        ->toThrow(ValidationException::class);
});

// ─── Part H: Show page UI ────────────────────────────────────────────────────

it('show page displays final status badge for finalized RME', function () {
    $record = MedicalRecord::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => MedicalRecord::STATUS_FINAL,
        'finalized_at' => now(),
        'finalized_by' => $this->manager->id,
    ]);
    $visit = $record->clinicVisit;

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('Final')
        ->assertSee('Difinalisasi pada')
        ->assertSee('Difinalisasi oleh');
});

it('show page shows handwriting warning when draft has no handwriting', function () {
    [$visit, $record] = makeVisitWithDraft($this->branch);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('RME belum dapat difinalkan');
});

it('show page shows finalize button when draft has handwriting', function () {
    [$visit, $record] = makeVisitWithDraft($this->branch);
    addHandwriting($record, $visit);

    $this->actingAs($this->manager)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee('Finalisasi');
});

it('finalization succeeds with empty SOAP when handwriting exists', function () {
    $this->actingAs($this->manager);

    [$visit, $record] = makeVisitWithDraft($this->branch);
    addHandwriting($record, $visit);

    $final = app(MedicalRecordService::class)->finalize($record->fresh());

    expect($final->status)->toBe(MedicalRecord::STATUS_FINAL)
        ->and($final->subjective)->toBeNull()
        ->and($final->objective)->toBeNull();
});

it('finalization preserves existing SOAP data', function () {
    $this->actingAs($this->manager);

    [$visit, $record] = makeVisitWithDraft($this->branch);
    $record->update([
        'subjective' => 'keluhan lama',
        'plan' => 'rencana lama',
    ]);
    addHandwriting($record, $visit);

    app(MedicalRecordService::class)->finalize($record->fresh());

    $fresh = $record->fresh();
    expect($fresh->subjective)->toBe('keluhan lama')
        ->and($fresh->plan)->toBe('rencana lama');
});
