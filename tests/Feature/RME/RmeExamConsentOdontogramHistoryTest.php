<?php

// FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3
//
// The canonical RME workflow after this sprint:
//
//   Pendaftaran → Antrian → Dokter "Mulai Pemeriksaan" → in_progress
//   → Persetujuan Tindakan Medis (unlocks RME authoring)
//   → dokter menulis RME + odontogram
//   → RME dan/atau odontogram lengkap → visit TETAP in_progress
//   → Dokter "Selesai Pemeriksaan" → cashier_pending
//   → Kasir / Pembayaran (TANPA pemeriksaan consent) → completed
//
// The four invariants under test:
//   FIX-01  Clinical-document completeness never completes an examination.
//   FIX-02  Consent gates CURRENT-VISIT RME AUTHORING, never reading history.
//   FIX-03  Consent is not part of payment eligibility at all.
//   FIX-04  Patient odontogram history is read-only; the ACTIVE odontogram
//           workflow is unchanged.

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Services\MedicalRecordDiagnosisService;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Odontogram\Services\OdontogramService;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Database\Seeders\BranchSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->branch = Branch::factory()->create(['code' => 'ATG3', 'is_rme_enabled' => true]);
    $this->otherBranch = Branch::factory()->create(['code' => 'SUN4', 'is_rme_enabled' => true]);

    $this->doctorUser = userWith([
        'view_clinic_visits', 'manage_clinic_visits', 'complete_rme_examination', 'manage_rme_consents',
    ]);
    $this->cashier = userWith(['manage_rme_billing']);

    // CORRECTIVE-02 condition 3 — an RME write needs a resolvable, authorized
    // actor; the gate fails closed without one. Individual tests override this.
    $this->actingAs($this->doctorUser);
});

// ─── Helpers ────────────────────────────────────────────────────────────────

function examVisit(Branch $branch, array $overrides = []): ClinicVisit
{
    return ClinicVisit::factory()->inProgress()->create(array_merge([
        'branch_id' => $branch->id,
    ], $overrides));
}

/** A draft medical record with the mandatory handwriting already saved. */
function examRecordWithHandwriting(ClinicVisit $visit): MedicalRecord
{
    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $record->id,
        'clinic_visit_id' => $visit->id,
    ]);

    return $record;
}

/** A saved odontogram carrying real recorded teeth. */
function examOdontogramFor(ClinicVisit $visit, array $teeth = ['11' => ['status' => 'caries']]): Odontogram
{
    return Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'status' => Odontogram::STATUS_FINALIZED,
        'tooth_map_payload' => ['teeth' => $teeth],
    ]);
}

function examBillableVisit(Branch $branch): array
{
    $visit = ClinicVisit::factory()->cashierPending()->create(['branch_id' => $branch->id]);
    $record = MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    return [$visit, $record];
}

function examInvoiceFor(ClinicVisit $visit, $cashier, float $unitPrice = 150000): RmeInvoice
{
    return app(RmeInvoiceService::class)->create($visit, $cashier, [
        'items' => [[
            'treatment_id' => null,
            'description' => 'Tindakan RME',
            'qty' => 1,
            'unit_price' => $unitPrice,
            'discount' => 0,
        ]],
    ]);
}

/*
|--------------------------------------------------------------------------
| FIX-01 — Explicit doctor examination completion
|--------------------------------------------------------------------------
|
| Completing a clinical DOCUMENT is not the same fact as completing an
| EXAMINATION. Nothing a doctor writes may hand the patient to the cashier.
*/

it('keeps the visit in_progress while the RME is still incomplete', function () {
    $visit = examVisit($this->branch);

    MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
    ]);

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('keeps the visit in_progress when the RME is complete but not finalized', function () {
    $visit = examVisit($this->branch);
    examRecordWithHandwriting($visit);

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('keeps the visit in_progress when the odontogram is complete', function () {
    $visit = examVisit($this->branch);
    examOdontogramFor($visit);

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('keeps the visit in_progress when BOTH the RME and the odontogram are complete', function () {
    $visit = examVisit($this->branch);
    examRecordWithHandwriting($visit);
    examOdontogramFor($visit);

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('keeps the visit in_progress even after the RME is FINALIZED', function () {
    // THE FIX. Finalization used to transition the visit to cashier_pending, so
    // finishing a document silently ended the examination.
    $visit = examVisit($this->branch);
    rmeSignedConsentFor($visit);
    $record = examRecordWithHandwriting($visit);

    app(MedicalRecordService::class)->finalize($record);

    expect($record->refresh()->status)->toBe(MedicalRecord::STATUS_FINAL)
        ->and($visit->refresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('moves the visit to cashier_pending only when the doctor explicitly completes the examination', function () {
    $visit = examVisit($this->branch);
    rmeSignedConsentFor($visit);
    $record = examRecordWithHandwriting($visit);
    app(MedicalRecordService::class)->finalize($record);

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);

    $this->actingAs($this->doctorUser)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_CASHIER_PENDING])
        ->assertRedirect(route('rme.visits.show', $visit));

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING);
});

it('denies examination completion to Admin Klinik', function () {
    $visit = examVisit($this->branch);
    $adminKlinik = userInRole('Admin Klinik');

    $this->actingAs($adminKlinik)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_CASHIER_PENDING])
        ->assertForbidden();

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('denies examination completion to the cashier', function () {
    $visit = examVisit($this->branch);

    $this->actingAs($this->cashier)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_CASHIER_PENDING])
        ->assertForbidden();

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('denies examination completion to a doctor outside the visit branch', function () {
    $visit = examVisit($this->otherBranch);

    // The actor holds the clinical permission but is scoped to a different branch
    // through the visit's own branch, so the policy must still refuse.
    $outsider = userWith(['view_clinic_visits', 'manage_clinic_visits']);

    $this->actingAs($outsider)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_CASHIER_PENDING])
        ->assertForbidden();

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('still refuses to mark a visit completed from anywhere but the cashier', function () {
    // Sprint 62.1 invariant, preserved: only a settled payment completes a visit.
    $visit = examVisit($this->branch);

    expect(fn () => app(ClinicVisitService::class)
        ->transitionStatus($visit, ClinicVisit::STATUS_COMPLETED))
        ->toThrow(ValidationException::class);
});

/*
|--------------------------------------------------------------------------
| FIX-02 — Consent as the RME authoring gate
|--------------------------------------------------------------------------
*/

it('refuses to create an RME sheet for a live visit without consent', function () {
    $visit = examVisit($this->branch);

    expect(fn () => app(MedicalRecordService::class)->createDraft($visit, $this->doctorUser->id))
        ->toThrow(ValidationException::class);

    expect(MedicalRecord::where('clinic_visit_id', $visit->id)->count())->toBe(0);
});

it('refuses to edit a live visit RME without consent', function () {
    $visit = examVisit($this->branch);
    $record = examRecordWithHandwriting($visit);

    expect(fn () => app(MedicalRecordService::class)->updateDraft($record, ['notes' => 'diubah']))
        ->toThrow(ValidationException::class);

    expect($record->refresh()->notes)->not->toBe('diubah');
});

it('refuses to finalize a live visit RME without consent', function () {
    $visit = examVisit($this->branch);
    $record = examRecordWithHandwriting($visit);

    expect(fn () => app(MedicalRecordService::class)->finalize($record))
        ->toThrow(ValidationException::class);

    expect($record->refresh()->status)->toBe(MedicalRecord::STATUS_DRAFT);
});

it('refuses to save handwriting for a live visit without consent', function () {
    $visit = examVisit($this->branch);
    $record = examRecordWithHandwriting($visit);
    $before = MedicalRecordHandwriting::count();

    $this->actingAs($this->doctorUser)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('rme.visits.medical-record.handwriting.store', [$visit, $record]), [
            'handwriting_data' => 'data:image/png;base64,'.base64_encode('not-a-real-png'),
            'page_number' => 2,
        ])
        ->assertSessionHasErrors();

    expect(MedicalRecordHandwriting::count())->toBe($before);
});

it('allows RME authoring once a consent has actually been signed', function () {
    $visit = examVisit($this->branch);
    rmeSignedConsentFor($visit);

    $record = app(MedicalRecordService::class)->createDraft($visit, $this->doctorUser->id);
    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $record->id,
        'clinic_visit_id' => $visit->id,
    ]);

    app(MedicalRecordService::class)->updateDraft($record, ['notes' => 'catatan']);
    app(MedicalRecordService::class)->finalize($record);

    expect($record->refresh()->status)->toBe(MedicalRecord::STATUS_FINAL)
        ->and($record->notes)->toBe('catatan');
});

it('still allows RME authoring when documentation/publication consent is TIDAK', function () {
    // Clause 8 is a separate decision. Refusing publication is not refusing treatment.
    $visit = examVisit($this->branch);
    rmeSignedConsentFor($visit)->forceFill(['documentation_consent' => false])->save();

    $record = app(MedicalRecordService::class)->createDraft($visit, $this->doctorUser->id);

    expect($record->exists)->toBeTrue();
});

it('does not let a consent signed for another visit unlock this visit', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $other = examVisit($this->branch, ['patient_id' => $patient->id]);
    $visit = examVisit($this->branch, ['patient_id' => $patient->id]);

    rmeSignedConsentFor($other);

    expect(fn () => app(MedicalRecordService::class)->createDraft($visit, $this->doctorUser->id))
        ->toThrow(ValidationException::class);
});

it('does not let another patient consent unlock this visit', function () {
    $visit = examVisit($this->branch);
    $otherPatientVisit = examVisit($this->branch);
    rmeSignedConsentFor($otherPatientVisit);

    expect(fn () => app(MedicalRecordService::class)->createDraft($visit, $this->doctorUser->id))
        ->toThrow(ValidationException::class);
});

it('does not let a consent from another branch unlock this visit', function () {
    $visit = examVisit($this->branch);
    $crossBranchVisit = examVisit($this->otherBranch);
    rmeSignedConsentFor($crossBranchVisit);

    expect(fn () => app(MedicalRecordService::class)->createDraft($visit, $this->doctorUser->id))
        ->toThrow(ValidationException::class);
});

it('ignores a voided consent', function () {
    $visit = examVisit($this->branch);
    rmeSignedConsentFor($visit)->forceFill(['voided_at' => now()])->save();

    expect(fn () => app(MedicalRecordService::class)->createDraft($visit, $this->doctorUser->id))
        ->toThrow(ValidationException::class);
});

it('never lets a request assert its own consent', function () {
    // The gate reads a signed document from the database. There is no request
    // field it consults, so nothing a client sends can satisfy it.
    $visit = examVisit($this->branch);
    $record = examRecordWithHandwriting($visit);

    $this->actingAs($this->doctorUser)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('rme.visits.medical-record.finalize', [$visit, $record]), [
            'consent' => true,
            'consent_signed_by_patient' => 1,
            'consent_signed_by_doctor' => 1,
            'signed' => true,
        ]);

    expect($record->refresh()->status)->toBe(MedicalRecord::STATUS_DRAFT);
});

it('refuses to revise a terminal visit record when no examination is running, and allows it during one', function () {
    /*
     * CORRECTIVE-02. Historical correction is not a standing right: writing into a
     * patient's record requires a legitimate encounter. What Sprint 59 actually
     * protects — that a FINALIZED record is not frozen — is preserved: during an
     * authorized consented examination the old record IS editable.
     */
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $visit = ClinicVisit::factory()->completed()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $patient->id,
    ]);
    $record = examRecordWithHandwriting($visit);

    // No examination running: refused.
    expect(fn () => app(MedicalRecordService::class)->updateDraft($record, ['notes' => 'koreksi historis']))
        ->toThrow(ValidationException::class);

    // The patient comes in and is examined: the old record becomes editable again.
    $live = examVisit($this->branch, ['patient_id' => $patient->id]);
    rmeSignedConsentFor($live);

    app(MedicalRecordService::class)->updateDraft($record, ['notes' => 'koreksi historis']);

    expect($record->refresh()->notes)->toBe('koreksi historis');
});

it('lets consent be signed as soon as the examination starts', function () {
    $visit = examVisit($this->branch);

    expect(app(RmeVisitConsentService::class)->isSignable($visit))->toBeTrue();
});

it('refuses to sign consent for a visit that is already finished', function () {
    $visit = ClinicVisit::factory()->completed()->create(['branch_id' => $this->branch->id]);

    expect(app(RmeVisitConsentService::class)->isSignable($visit))->toBeFalse()
        ->and(fn () => app(RmeVisitConsentService::class)->assertSignable($visit))
        ->toThrow(ValidationException::class);
});

it('never blocks READING the RME workspace when consent is missing', function () {
    // Withholding the clinical history from the doctor deciding the treatment
    // would be unsafe. Only writes wait for the signature.
    $visit = examVisit($this->branch);
    examRecordWithHandwriting($visit);

    $this->actingAs($this->doctorUser)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->followingRedirects()
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| FIX-03 — Cashier / payment consent independence
|--------------------------------------------------------------------------
*/

it('accepts a full payment on a visit that has NO consent at all', function () {
    [$visit] = examBillableVisit($this->branch);
    $invoice = examInvoiceFor($visit, $this->cashier);

    expect($visit->consents()->count())->toBe(0);

    app(RmePaymentService::class)->pay($invoice, $this->cashier, [
        'amount' => 150000,
        'paid_at' => now()->toDateString(),
    ]);

    expect($invoice->refresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($visit->refresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);
});

it('accepts a partial payment without consent and keeps the remainder as a receivable', function () {
    [$visit] = examBillableVisit($this->branch);
    $invoice = examInvoiceFor($visit, $this->cashier);

    app(RmePaymentService::class)->pay($invoice, $this->cashier, [
        'amount' => 50000,
        'paid_at' => now()->toDateString(),
    ]);

    expect($invoice->refresh()->status)->toBe(RmeInvoice::STATUS_PARTIAL)
        ->and($visit->refresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);
});

it('keeps a historical receivable collectible when no consent exists', function () {
    [$visit] = examBillableVisit($this->branch);
    $invoice = examInvoiceFor($visit, $this->cashier);

    app(RmePaymentService::class)->pay($invoice, $this->cashier, ['amount' => 50000, 'paid_at' => now()->toDateString()]);
    app(RmePaymentService::class)->pay($invoice->refresh(), $this->cashier, ['amount' => 100000, 'paid_at' => now()->toDateString()]);

    expect($invoice->refresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});

it('does not accept consent fields on the payment request any more', function () {
    [$visit] = examBillableVisit($this->branch);
    $invoice = examInvoiceFor($visit, $this->cashier);

    $this->actingAs($this->cashier)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('rme.cashier.payment.store', [$visit, $invoice]), [
            'amount' => 150000,
            'paid_at' => now()->toDateString(),
        ])
        ->assertSessionHasNoErrors();

    expect($invoice->refresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});

it('does not render a consent blocker on the cashier payment page', function () {
    [$visit] = examBillableVisit($this->branch);
    $invoice = examInvoiceFor($visit, $this->cashier);

    $this->actingAs($this->cashier)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.cashier.payment.create', [$visit, $invoice]))
        ->assertOk()
        ->assertDontSee('Consent belum ditandatangani')
        ->assertDontSee('consent_signed_by_patient', false)
        ->assertDontSee('consent_signed_by_doctor', false);
});

it('still refuses payment from a cashier outside the invoice branch', function () {
    [$visit] = examBillableVisit($this->otherBranch);
    $invoice = examInvoiceFor($visit, $this->cashier);

    $outsider = userWith(['manage_rme_billing']);
    rmeMakeKasirActive($outsider, $this->branch);

    $this->actingAs($outsider)
        ->get(route('rme.cashier.payment.create', [$visit, $invoice]))
        ->assertForbidden();
});

it('still refuses payment from an actor without billing permission', function () {
    [$visit] = examBillableVisit($this->branch);
    $invoice = examInvoiceFor($visit, $this->cashier);

    $this->actingAs($this->doctorUser)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.cashier.payment.create', [$visit, $invoice]))
        ->assertForbidden();
});

it('still refuses to raise an invoice before the doctor finishes the examination', function () {
    // The billing gate is untouched — and after FIX-01 it is now the doctor's
    // explicit action, not a document, that opens it.
    $visit = examVisit($this->branch);

    expect(fn () => examInvoiceFor($visit, $this->cashier))->toThrow(ValidationException::class);
});

/*
|--------------------------------------------------------------------------
| FIX-04 — Patient odontogram history (read-only)
|--------------------------------------------------------------------------
*/

it('shows a previous visit odontogram in the patient history', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $previous = ClinicVisit::factory()->completed()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $patient->id,
        'visit_date' => now()->subMonth()->toDateString(),
    ]);
    examOdontogramFor($previous);

    $current = examVisit($this->branch, ['patient_id' => $patient->id]);

    $history = app(OdontogramService::class)->patientHistoryForVisit($current, $this->doctorUser);

    expect($history)->toHaveCount(1)
        ->and($history->first()['visit_id'])->toBe($previous->id)
        ->and($history->first()['source'])->toBe('native');
});

it('never lists the current visit odontogram as its own history', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $current = examVisit($this->branch, ['patient_id' => $patient->id]);
    examOdontogramFor($current);

    $history = app(OdontogramService::class)->patientHistoryForVisit($current, $this->doctorUser);

    expect($history)->toHaveCount(0);
});

it('excludes empty odontogram drafts from the history', function () {
    // Opening the odontogram page auto-creates an empty draft; an empty draft is
    // not a clinical finding.
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $previous = ClinicVisit::factory()->completed()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $patient->id,
    ]);
    Odontogram::factory()->create([
        'clinic_visit_id' => $previous->id,
        'branch_id' => $this->branch->id,
        'tooth_map_payload' => null,
    ]);

    $current = examVisit($this->branch, ['patient_id' => $patient->id]);

    expect(app(OdontogramService::class)->patientHistoryForVisit($current, $this->doctorUser))->toHaveCount(0);
});

it('never leaks another patient odontogram into the history', function () {
    $otherPatientVisit = ClinicVisit::factory()->completed()->create(['branch_id' => $this->branch->id]);
    examOdontogramFor($otherPatientVisit);

    $current = examVisit($this->branch);

    expect(app(OdontogramService::class)->patientHistoryForVisit($current, $this->doctorUser))->toHaveCount(0);
});

it('never leaks an odontogram from a non-RME branch into the history', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $mainBranch = Branch::where('code', Branch::MAIN_CODE)->first();

    $nonRmeVisit = ClinicVisit::factory()->completed()->create([
        'branch_id' => $mainBranch->id,
        'patient_id' => $patient->id,
    ]);
    examOdontogramFor($nonRmeVisit);

    $current = examVisit($this->branch, ['patient_id' => $patient->id]);

    expect(app(OdontogramService::class)->patientHistoryForVisit($current, $this->doctorUser))->toHaveCount(0);
});

it('shows the odontogram history even when the current visit has no consent', function () {
    // Consent gates writing, never reading clinical history.
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $previous = ClinicVisit::factory()->completed()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $patient->id,
    ]);
    examOdontogramFor($previous);

    $current = examVisit($this->branch, ['patient_id' => $patient->id]);

    expect($current->consents()->count())->toBe(0)
        ->and(app(OdontogramService::class)->patientHistoryForVisit($current, $this->doctorUser))->toHaveCount(1);
});

it('renders the read-only history section on the odontogram page without edit controls', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $previous = ClinicVisit::factory()->completed()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $patient->id,
    ]);
    examOdontogramFor($previous);

    $current = examVisit($this->branch, ['patient_id' => $patient->id]);

    $response = $this->actingAs($this->doctorUser)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.visits.odontogram.show', $current))
        ->assertOk()
        ->assertSee('Riwayat Odontogram Pasien')
        ->assertSee('Hanya Baca');

    // The history rows must never offer a mutation endpoint for a past chart.
    $html = $response->getContent();
    expect($html)->not->toContain(route('rme.odontograms.update', $previous->odontogram))
        ->and($html)->not->toContain(route('rme.odontograms.finalize', $previous->odontogram));
});

it('keeps the ACTIVE odontogram editable exactly as before', function () {
    // FIX-04 must not turn into a blanket read-only policy.
    $visit = examVisit($this->branch);
    $odontogram = app(OdontogramService::class)->getOrCreateForVisit($visit, $this->doctorUser);

    app(OdontogramService::class)->updatePlaceholder($odontogram, [
        'tooth_map_payload' => ['teeth' => ['16' => ['status' => 'filling']]],
    ], $this->doctorUser);

    expect($odontogram->refresh()->tooth_map_payload['teeth'])->toHaveKey('16');
});

it('does not let saving an odontogram change the visit status', function () {
    $visit = examVisit($this->branch);
    $odontogram = app(OdontogramService::class)->getOrCreateForVisit($visit, $this->doctorUser);

    app(OdontogramService::class)->updatePlaceholder($odontogram, [
        'tooth_map_payload' => ['teeth' => ['21' => ['status' => 'caries']]],
    ], $this->doctorUser);
    app(OdontogramService::class)->finalize($odontogram, $this->doctorUser);

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

/*
|--------------------------------------------------------------------------
| Adversarial regressions — found by security review, fixed, now pinned
|--------------------------------------------------------------------------
*/

it('refuses to write into a patient record while they have an open unconsented visit, whatever visit the request names', function () {
    /*
     * THE BYPASS. The gate used to take "which visit is this for" from the
     * request. Sprint 64.0.2 stores new handwriting on the patient's CANONICAL
     * record — for a returning patient an older, TERMINAL, therefore EXEMPT
     * visit — so opening the book from the Rekam Medis list (ordinary navigation,
     * no crafting) let a doctor write today's note with no consent anywhere.
     */
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);

    // The patient's first visit: long finished, canonical, owns the book.
    $canonical = ClinicVisit::factory()->completed()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $patient->id,
        'visit_date' => now()->subYear()->toDateString(),
    ]);
    $canonicalRecord = examRecordWithHandwriting($canonical);

    // Today's encounter: live, and NOT consented.
    $today = examVisit($this->branch, ['patient_id' => $patient->id]);

    // Naming only the exempt terminal visit must not unlock the book.
    expect(fn () => app(MedicalRecordService::class)->updateDraft($canonicalRecord, ['notes' => 'catatan hari ini']))
        ->toThrow(ValidationException::class);

    expect($canonicalRecord->refresh()->notes)->not->toBe('catatan hari ini');

    // Consent for the LIVE encounter is what unlocks it.
    rmeSignedConsentFor($today);
    app(MedicalRecordService::class)->updateDraft($canonicalRecord, ['notes' => 'catatan hari ini']);

    expect($canonicalRecord->refresh()->notes)->toBe('catatan hari ini');
});

it('refuses a handwriting POST that omits source_visit_id to dodge the gate', function () {
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $canonical = ClinicVisit::factory()->completed()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $patient->id,
        'visit_date' => now()->subYear()->toDateString(),
    ]);
    $record = examRecordWithHandwriting($canonical);
    examVisit($this->branch, ['patient_id' => $patient->id]);

    $before = MedicalRecordHandwriting::count();

    $this->actingAs($this->doctorUser)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('rme.visits.medical-record.handwriting.store', [$canonical, $record]), [
            'handwriting_data' => 'data:image/png;base64,'.base64_encode('x'),
            'page_number' => 2,
        ])
        ->assertSessionHasErrors();

    expect(MedicalRecordHandwriting::count())->toBe($before);
});

it('refuses historical revision for a patient with no open visit, but keeps it READABLE', function () {
    // CORRECTIVE-02 — absence of a blocker is not authority to write. Reading is
    // never gated, so the history stays available to the treating doctor.
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $old = ClinicVisit::factory()->completed()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $patient->id,
    ]);
    $record = examRecordWithHandwriting($old);

    expect(fn () => app(MedicalRecordService::class)->updateDraft($record, ['notes' => 'koreksi historis']))
        ->toThrow(ValidationException::class);

    $this->actingAs($this->doctorUser)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->followingRedirects()
        ->get(route('rme.visits.medical-record.show', $old))
        ->assertOk();
});

it('refuses a structured diagnosis write on a live visit without consent', function () {
    $visit = examVisit($this->branch);
    $record = examRecordWithHandwriting($visit);

    $diagnosis = ClinicalDiagnosis::factory()->create([
        'status' => ClinicalDiagnosis::STATUS_ACTIVE,
    ]);

    expect(fn () => app(MedicalRecordDiagnosisService::class)->record(
        $record,
        ['clinical_diagnosis_id' => $diagnosis->id, 'diagnosis_role' => 'primary'],
        $this->doctorUser,
    ))->toThrow(ValidationException::class);
});

it('scopes the odontogram history to the working branch, not the whole RME estate', function () {
    /*
     * A context-bound operator (Kasir / Admin Klinik / Perawat) is pinned to one
     * branch by RmeWorkingBranchScope, and the doctor scope does not narrow them.
     * Without the working-branch intersection they would read a patient's
     * clinical odontogram findings from every branch in the estate, in bulk.
     */
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);

    $here = ClinicVisit::factory()->completed()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $patient->id,
    ]);
    examOdontogramFor($here);

    $elsewhere = ClinicVisit::factory()->completed()->create([
        'branch_id' => $this->otherBranch->id,
        'patient_id' => $patient->id,
    ]);
    examOdontogramFor($elsewhere);

    $current = examVisit($this->branch, ['patient_id' => $patient->id]);

    $kasir = userWith(['manage_rme_billing', 'view_clinic_visits']);
    rmeMakeKasirActive($kasir, $this->branch);

    $history = app(OdontogramService::class)->patientHistoryForVisit($current, $kasir);

    expect($history->pluck('visit_id')->all())->toBe([$here->id]);
});
