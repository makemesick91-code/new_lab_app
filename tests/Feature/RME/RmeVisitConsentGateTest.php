<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-01 — signed PERSETUJUAN TINDAKAN
 * MEDIS as the RME payment gate.
 *
 * The defect this closes: the gate used to be two booleans supplied BY THE
 * PAYMENT REQUEST. RmePaymentService wrote them onto the visit and then
 * asserted against the row it had just written, so a POST carrying
 * consent_signed_by_patient=1&consent_signed_by_doctor=1 paid an invoice with
 * no document, no signature and no patient involvement.
 *
 * The gate now reads persisted evidence that only the consent service can
 * create, and only from a real signature.
 */
beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake('local');

    $this->branch = Branch::factory()->create([
        'code' => 'CNS1',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);

    $this->cashier = userWith(['manage_rme_billing', 'manage_rme_consents', 'view_rme_consents']);
    $this->consentOperator = $this->cashier;
});

// CORRECTIVE-01 — consent is signable only while the examination is running, so
// the default fixture is an in-progress encounter. Payment tests pass
// STATUS_CASHIER_PENDING explicitly, because raising an invoice needs that state.
function consentVisit(?Branch $branch = null, string $status = ClinicVisit::STATUS_IN_PROGRESS): ClinicVisit
{
    $branch ??= test()->branch;

    $visit = ClinicVisit::factory()->create([
        'branch_id' => $branch->id,
        'status' => $status,
    ]);

    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    return $visit->refresh();
}

function consentInvoiceFor(ClinicVisit $visit, float $amount = 200000): RmeInvoice
{
    $treatment = Treatment::factory()->create(['is_active' => true]);

    return app(RmeInvoiceService::class)->create($visit, test()->cashier, [
        'items' => [[
            'treatment_id' => $treatment->id,
            'description' => 'Konsultasi',
            'qty' => 1,
            'unit_price' => $amount,
        ]],
    ]);
}

function consentPayload(array $overrides = []): array
{
    return array_merge([
        'template_code' => 'PERSETUJUAN_TINDAKAN_MEDIS',
        'consenter_relationship' => 'self',
        'medical_action' => 'Pencabutan gigi 36',
        'treatment_summary' => 'Pencabutan gigi',
        'documentation_consent' => false,
        'consenter_signature' => validPodSignatureData(),
    ], $overrides);
}

function signConsentFor(ClinicVisit $visit, array $overrides = []): RmeVisitConsent
{
    return app(RmeVisitConsentService::class)->sign($visit, test()->consentOperator, consentPayload($overrides));
}

function payDataFor(float $amount, array $overrides = []): array
{
    return array_merge([
        'amount' => $amount,
        'paid_at' => now()->toDateTimeString(),
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| A — the gate itself: consent gates RME AUTHORING, not payment
|--------------------------------------------------------------------------
|
| SUPERSEDED by FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 (FIX-02 / FIX-03).
|
| Sections A and B used to prove a PAYMENT gate. That gate is removed: consent is
| consent to TREATMENT, so it now guards the moment treatment is recorded — the
| doctor's examination — and payment does not consult it at all.
|
| The assertions are converted, not dropped. Every adversarial property the old
| section proved (a request cannot author its own consent; another visit's,
| another patient's or another branch's consent does not satisfy this one; a
| voided consent satisfies nothing) still matters — it now applies to the
| authoring gate. The payment cases are INVERTED, so reintroducing a payment
| consent gate — which would make the historical receivables book uncollectable —
| fails here.
*/

it('lets a payment settle even though no consent has been signed', function () {
    $visit = consentVisit(status: ClinicVisit::STATUS_CASHIER_PENDING);
    $invoice = consentInvoiceFor($visit);

    app(RmePaymentService::class)->pay($invoice, $this->cashier, payDataFor(200000));

    expect($invoice->refresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($visit->refresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);
});

it('THE FIX — a crafted request claiming consent cannot author its own consent', function () {
    // The original defect: the payment request supplied the booleans, the service
    // wrote them onto the visit, then asserted against what it had just written.
    // Both the write path and the payment gate are gone. What remains provable —
    // and what matters — is that no request field, and no legacy attestation
    // column, can create the evidence. Only a real signature can.
    $visit = consentVisit(status: ClinicVisit::STATUS_IN_PROGRESS);

    expect(fn () => app(MedicalRecordService::class)->createDraft($visit, $this->cashier->id))
        ->toThrow(ValidationException::class);

    $visit->forceFill([
        'consent_signed_by_patient' => true,
        'consent_signed_by_doctor' => true,
    ])->save();

    expect(fn () => app(MedicalRecordService::class)->createDraft($visit->refresh(), $this->cashier->id))
        ->toThrow(ValidationException::class)
        ->and(RmeVisitConsent::count())->toBe(0);
});

it('keeps partial payment behaviour intact without any consent', function () {
    $visit = consentVisit(status: ClinicVisit::STATUS_CASHIER_PENDING);
    $invoice = consentInvoiceFor($visit);

    app(RmePaymentService::class)->pay($invoice, $this->cashier, payDataFor(50000));

    expect($invoice->refresh()->status)->toBe(RmeInvoice::STATUS_PARTIAL)
        ->and($visit->refresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);
});

it('does not let a voided consent block a payment', function () {
    $visit = consentVisit();
    $consent = signConsentFor($visit);
    app(RmeVisitConsentService::class)->void($consent, $this->consentOperator, 'salah pasien');

    // The examination finishes, then the cashier takes payment.
    $visit->forceFill(['status' => ClinicVisit::STATUS_CASHIER_PENDING])->save();
    $invoice = consentInvoiceFor($visit->refresh());
    app(RmePaymentService::class)->pay($invoice, $this->cashier, payDataFor(200000));

    expect($invoice->refresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});

it('still collects an instalment on a visit that was completed before this sprint', function () {
    // The receivables book that predates the consent architecture must stay
    // collectible. That was true under the old gate only by exemption; it is now
    // true by construction, because payment never asks about consent.
    $visit = consentVisit(status: ClinicVisit::STATUS_CASHIER_PENDING);
    $invoice = consentInvoiceFor($visit);

    app(RmePaymentService::class)->pay($invoice, $this->cashier, payDataFor(50000));
    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);

    app(RmePaymentService::class)->pay($invoice->refresh(), $this->cashier, payDataFor(150000));

    expect($invoice->refresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});

/*
|--------------------------------------------------------------------------
| B — consent identity cannot be borrowed (now proved on the AUTHORING gate)
|--------------------------------------------------------------------------
*/

it('does not let another visit consent satisfy this visit', function () {
    $other = consentVisit(status: ClinicVisit::STATUS_IN_PROGRESS);
    signConsentFor($other);

    $visit = consentVisit(status: ClinicVisit::STATUS_IN_PROGRESS);
    $visit->forceFill(['patient_id' => $other->patient_id])->save();

    expect(fn () => app(MedicalRecordService::class)->createDraft($visit->refresh(), $this->cashier->id))
        ->toThrow(ValidationException::class);
});

it('does not let another patient consent satisfy this visit', function () {
    $other = consentVisit(status: ClinicVisit::STATUS_IN_PROGRESS);
    signConsentFor($other);

    $visit = consentVisit(status: ClinicVisit::STATUS_IN_PROGRESS);

    expect(fn () => app(MedicalRecordService::class)->createDraft($visit, $this->cashier->id))
        ->toThrow(ValidationException::class);
});

it('does not let a consent from another branch satisfy this visit', function () {
    $otherBranch = Branch::factory()->create(['code' => 'SUN4', 'is_rme_enabled' => true]);
    $other = consentVisit($otherBranch, ClinicVisit::STATUS_IN_PROGRESS);
    signConsentFor($other);

    $visit = consentVisit(status: ClinicVisit::STATUS_IN_PROGRESS);

    expect(fn () => app(MedicalRecordService::class)->createDraft($visit, $this->cashier->id))
        ->toThrow(ValidationException::class);
});

/*
|--------------------------------------------------------------------------
| C — signing rules
|--------------------------------------------------------------------------
*/

it('signs as soon as the doctor has started the examination', function () {
    // SUPERSEDED by FIX-02: consent is taken at the START of the examination,
    // because consent to a treatment has to be given before the treatment.
    $visit = consentVisit(status: ClinicVisit::STATUS_IN_PROGRESS);

    $consent = signConsentFor($visit);

    expect($consent->exists)->toBeTrue()
        ->and(app(RmeVisitConsentService::class)->hasValidConsent($visit->refresh()))->toBeTrue();
});

it('refuses to sign on a terminal visit', function () {
    $visit = consentVisit(status: ClinicVisit::STATUS_COMPLETED);

    expect(fn () => signConsentFor($visit))->toThrow(ValidationException::class);
});

it('refuses to sign without a signature', function () {
    $visit = consentVisit();

    expect(fn () => signConsentFor($visit, ['consenter_signature' => null]))
        ->toThrow(ValidationException::class);
    expect(RmeVisitConsent::count())->toBe(0);
});

it('refuses a signature that is not a real PNG', function () {
    $visit = consentVisit();

    expect(fn () => signConsentFor($visit, ['consenter_signature' => 'data:image/png;base64,'.base64_encode('not a png')]))
        ->toThrow(ValidationException::class);
});

it('requires an explicit YA or TIDAK for the documentation clause', function () {
    $visit = consentVisit();

    $payload = consentPayload();
    unset($payload['documentation_consent']);

    expect(fn () => app(RmeVisitConsentService::class)->sign($visit, $this->consentOperator, $payload))
        ->toThrow(ValidationException::class);
});

it('accepts a consent whose documentation answer is TIDAK and still opens RME authoring', function () {
    // Refusing publication must never cost the patient their treatment or block
    // the cashier. This is the rule that keeps consent from becoming coercive.
    $visit = consentVisit();

    $consent = signConsentFor($visit, ['documentation_consent' => false]);

    expect($consent->documentation_consent)->toBeFalse();
    expect($consent->allowsDocumentationPublication())->toBeFalse();

    // Refusing publication does not block RME authoring...
    expect(app(RmeVisitConsentService::class)->canAuthorRmeForPatient($visit->patient_id, $this->consentOperator))
        ->toBeTrue();

    // ...nor the cashier, once the doctor has finished.
    $visit->forceFill(['status' => ClinicVisit::STATUS_CASHIER_PENDING])->save();
    $invoice = consentInvoiceFor($visit->refresh());
    $payment = app(RmePaymentService::class)->pay($invoice->refresh(), $this->cashier, payDataFor(200000));
    expect($payment->amount)->toEqual(200000.0);
});

it('takes the doctor from the visit, never from the request', function () {
    $visit = consentVisit();

    $consent = signConsentFor($visit, ['doctor_id' => 999999]);

    expect($consent->doctor_id)->toBe($visit->doctor_id);
});

it('copies the patient identity when the patient signs for themselves', function () {
    $visit = consentVisit();
    $patient = $visit->patient;

    // A crafted name must not override the canonical patient record.
    $consent = signConsentFor($visit, [
        'consenter_relationship' => 'self',
        'consenter_name' => 'NAMA PALSU',
    ]);

    expect($consent->consenter_name)->toBe($patient->name);
    expect($consent->patient_name_snapshot)->toBe($patient->name);
});

it('records a family member as a distinct consenter from the patient', function () {
    $visit = consentVisit();

    $consent = signConsentFor($visit, [
        'consenter_relationship' => 'ayah',
        'consenter_name' => 'Bapak Pasien',
        'consenter_identity_number' => '7371010101010001',
    ]);

    expect($consent->consenter_relationship)->toBe('ayah');
    expect($consent->consenter_name)->toBe('Bapak Pasien');
    expect($consent->consenter_name)->not->toBe($consent->patient_name_snapshot);
});

/*
|--------------------------------------------------------------------------
| D — signed consent is evidence
|--------------------------------------------------------------------------
*/

it('freezes the agreed wording so later template edits cannot rewrite history', function () {
    $visit = consentVisit();
    $consent = signConsentFor($visit);

    $originalClause = $consent->content_snapshot['clauses'][1];

    config()->set('rme_consent.templates.PERSETUJUAN_TINDAKAN_MEDIS.clauses.1', 'WORDING YANG DIUBAH KEMUDIAN');

    expect($consent->refresh()->content_snapshot['clauses'][1])->toBe($originalClause);
    expect($consent->content_snapshot['clauses'][1])->not->toBe('WORDING YANG DIUBAH KEMUDIAN');
});

it('stores the signature on a private disk and never as a public url', function () {
    $visit = consentVisit();
    $consent = signConsentFor($visit);

    expect($consent->consenter_signature_path)->toStartWith('rme-consents/');
    Storage::disk('local')->assertExists($consent->consenter_signature_path);
    // The public disk must not receive consent evidence.
    expect(Storage::disk('public')->exists($consent->consenter_signature_path))->toBeFalse();
});

it('never serialises the identity number or signature paths', function () {
    $visit = consentVisit();
    $consent = signConsentFor($visit, [
        'consenter_relationship' => 'ayah',
        'consenter_name' => 'Bapak Pasien',
        'consenter_identity_number' => '7371010101010001',
    ]);

    $array = $consent->toArray();

    expect($array)->not->toHaveKey('consenter_identity_number');
    expect($array)->not->toHaveKey('patient_identity_number_snapshot');
    expect($array)->not->toHaveKey('consenter_signature_path');
    expect(json_encode($consent))->not->toContain('7371010101010001');
});

it('refuses to void a consent twice', function () {
    $visit = consentVisit();
    $consent = signConsentFor($visit);

    app(RmeVisitConsentService::class)->void($consent, $this->consentOperator, 'Salah pasien.');

    expect(fn () => app(RmeVisitConsentService::class)->void($consent->refresh(), $this->consentOperator, 'Lagi.'))
        ->toThrow(ValidationException::class);
});

it('requires a reason to void a consent', function () {
    $visit = consentVisit();
    $consent = signConsentFor($visit);

    expect(fn () => app(RmeVisitConsentService::class)->void($consent, $this->consentOperator, '   '))
        ->toThrow(ValidationException::class);

    expect($consent->refresh()->isVoided())->toBeFalse();
});

it('keeps the voided consent as evidence rather than deleting it', function () {
    $visit = consentVisit();
    $consent = signConsentFor($visit);

    app(RmeVisitConsentService::class)->void($consent, $this->consentOperator, 'Salah pasien.');

    expect(RmeVisitConsent::find($consent->id))->not->toBeNull();
    expect($consent->refresh()->void_reason)->toBe('Salah pasien.');
    expect($consent->consenter_signature_path)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| E — the workflow contract is unchanged
|--------------------------------------------------------------------------
*/

it('leaves the doctor to cashier transition exactly as it was', function () {
    // Sprint 62.1: a doctor advances to cashier_pending, never to completed.
    expect(ClinicVisit::VALID_TRANSITIONS[ClinicVisit::STATUS_IN_PROGRESS])
        ->toBe([ClinicVisit::STATUS_CASHIER_PENDING, ClinicVisit::STATUS_CANCELLED]);

    expect(ClinicVisit::VALID_TRANSITIONS[ClinicVisit::STATUS_CASHIER_PENDING])
        ->toBe([ClinicVisit::STATUS_COMPLETED]);

    // FIX-01: and signing a consent does not advance anything by itself.
    $visit = consentVisit(status: ClinicVisit::STATUS_IN_PROGRESS);
    signConsentFor($visit);

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('treats a legacy cashier attestation as history but not as a payment gate', function () {
    // A visit settled before this sprint keeps its truthful historical record...
    $visit = consentVisit(status: ClinicVisit::STATUS_CASHIER_PENDING);
    $visit->forceFill([
        'consent_signed_by_patient' => true,
        'consent_signed_by_doctor' => true,
        'consent_verified_at' => now(),
    ])->save();

    expect($visit->refresh()->hasVerifiedConsent())->toBeTrue();

    // ...but that attestation is NOT a signed document, and must never be
    // mistaken for one by any gate. SUPERSEDED by FIX-02/FIX-03: it no longer
    // gates payment (nothing does), and it does not satisfy the AUTHORING gate
    // either — which is the property that actually needs protecting now.
    expect($visit->hasSignedConsentDocument())->toBeFalse();

    $invoice = consentInvoiceFor($visit);
    app(RmePaymentService::class)->pay($invoice, $this->cashier, payDataFor(200000));
    expect($invoice->refresh()->status)->toBe(RmeInvoice::STATUS_PAID);

    $live = consentVisit(status: ClinicVisit::STATUS_IN_PROGRESS);
    $live->forceFill([
        'consent_signed_by_patient' => true,
        'consent_signed_by_doctor' => true,
        'consent_verified_at' => now(),
    ])->save();

    expect(fn () => app(MedicalRecordService::class)->createDraft($live->refresh(), $this->cashier->id))
        ->toThrow(ValidationException::class);
});
