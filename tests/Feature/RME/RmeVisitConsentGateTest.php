<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\MedicalRecord\Models\MedicalRecord;
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

function consentVisit(?Branch $branch = null, string $status = ClinicVisit::STATUS_CASHIER_PENDING): ClinicVisit
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
| A — the gate itself
|--------------------------------------------------------------------------
*/

it('refuses payment when no consent has been signed', function () {
    $visit = consentVisit();
    $invoice = consentInvoiceFor($visit);

    expect(fn () => app(RmePaymentService::class)->pay($invoice, $this->cashier, payDataFor(200000)))
        ->toThrow(ValidationException::class);

    expect($invoice->refresh()->status)->not->toBe(RmeInvoice::STATUS_PAID);
});

it('THE FIX — a crafted request claiming consent cannot author its own consent', function () {
    // This is exactly the payload that used to settle an invoice outright.
    $visit = consentVisit();
    $invoice = consentInvoiceFor($visit);

    expect(fn () => app(RmePaymentService::class)->pay($invoice, $this->cashier, payDataFor(200000, [
        'consent_signed_by_patient' => true,
        'consent_signed_by_doctor' => true,
    ])))->toThrow(ValidationException::class);

    expect($invoice->refresh()->status)->not->toBe(RmeInvoice::STATUS_PAID);
    // And it must not have written the attestation onto the visit either.
    expect($visit->refresh()->consent_signed_by_patient)->toBeFalse();
    expect($visit->hasSignedConsentDocument())->toBeFalse();
});

it('allows payment once a consent has actually been signed', function () {
    $visit = consentVisit();
    $invoice = consentInvoiceFor($visit);
    signConsentFor($visit);

    $payment = app(RmePaymentService::class)->pay($invoice->refresh(), $this->cashier, payDataFor(200000));

    expect($payment->amount)->toEqual(200000.0);
    expect($invoice->refresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});

it('keeps partial payment behaviour intact behind the consent gate', function () {
    $visit = consentVisit();
    $invoice = consentInvoiceFor($visit);
    signConsentFor($visit);

    app(RmePaymentService::class)->pay($invoice->refresh(), $this->cashier, payDataFor(50000));

    $invoice->refresh();
    expect($invoice->status)->toBe(RmeInvoice::STATUS_PARTIAL);
    expect($invoice->remainingAmount())->toEqual(150000.0);
    // The partial-payment rule still completes the visit and leaves a receivable.
    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);
});

it('refuses payment when the only consent has been voided', function () {
    $visit = consentVisit();
    $invoice = consentInvoiceFor($visit);
    $consent = signConsentFor($visit);

    app(RmeVisitConsentService::class)->void($consent, $this->consentOperator, 'Salah pasien.');

    expect(fn () => app(RmePaymentService::class)->pay($invoice->refresh(), $this->cashier, payDataFor(200000)))
        ->toThrow(ValidationException::class);
});

it('allows payment again once a replacement consent is signed', function () {
    $visit = consentVisit();
    $invoice = consentInvoiceFor($visit);
    $first = signConsentFor($visit);

    app(RmeVisitConsentService::class)->void($first, $this->consentOperator, 'Salah pasien.');
    signConsentFor($visit);

    $payment = app(RmePaymentService::class)->pay($invoice->refresh(), $this->cashier, payDataFor(200000));

    expect($payment->amount)->toEqual(200000.0);
});

/*
|--------------------------------------------------------------------------
| B — consent identity cannot be borrowed
|--------------------------------------------------------------------------
*/

it('collects a prior receivable whose own visit predates consent', function () {
    /*
     * SCOPE OF THE GATE — the current visit only.
     *
     * A carry-over payment settles invoices from EARLIER visits too. Those are
     * deliberately not gated on their own consent: consent is consent to
     * TREATMENT, and collecting an outstanding debt is not a new treatment.
     *
     * The decisive argument is production data. Every receivable that predates
     * this sprint has no signed consent by definition, so gating parent visits
     * would make all historical debt permanently uncollectable — a worse outcome
     * than the bypass this sprint closes.
     *
     * This test pins that, so a future "tighten the gate" change cannot quietly
     * strand real money.
     */
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);

    // An old visit with an unpaid balance and NO consent at all.
    $priorVisit = consentVisit();
    $priorVisit->forceFill(['patient_id' => $patient->id])->save();
    $priorInvoice = consentInvoiceFor($priorVisit->refresh(), 100000);
    expect($priorVisit->refresh()->hasSignedConsentDocument())->toBeFalse();

    // Today's visit, properly consented.
    $currentVisit = consentVisit();
    $currentVisit->forceFill(['patient_id' => $patient->id])->save();
    $currentInvoice = consentInvoiceFor($currentVisit->refresh(), 50000);
    signConsentFor($currentVisit->refresh());

    $result = app(RmePaymentService::class)->allocateVisitPayment(
        $currentInvoice->refresh(),
        $this->cashier,
        payDataFor(150000),
        [$priorInvoice->id],
    );

    // The old debt is collected even though its visit never had a consent...
    expect($priorInvoice->refresh()->status)->toBe(RmeInvoice::STATUS_PAID);
    // ...and today's treatment was still gated on today's consent.
    expect($currentInvoice->refresh()->status)->toBe(RmeInvoice::STATUS_PAID);
    expect($result->allocatedToParent)->toEqual(100000.0);
});

it('still refuses the whole carry-over batch when the CURRENT visit lacks consent', function () {
    // The other half of the same rule: prior receivables are collectable, but
    // they are not a way to pay for un-consented treatment today.
    $patient = Patient::factory()->create(['branch_id' => $this->branch->id]);

    $priorVisit = consentVisit();
    $priorVisit->forceFill(['patient_id' => $patient->id])->save();
    $priorInvoice = consentInvoiceFor($priorVisit->refresh(), 100000);

    $currentVisit = consentVisit();
    $currentVisit->forceFill(['patient_id' => $patient->id])->save();
    $currentInvoice = consentInvoiceFor($currentVisit->refresh(), 50000);

    expect(fn () => app(RmePaymentService::class)->allocateVisitPayment(
        $currentInvoice->refresh(),
        $this->cashier,
        payDataFor(150000),
        [$priorInvoice->id],
    ))->toThrow(ValidationException::class);

    expect($priorInvoice->refresh()->status)->not->toBe(RmeInvoice::STATUS_PAID);
});

it('does not let another visit consent satisfy this visit', function () {
    $visitA = consentVisit();
    $visitB = consentVisit();

    signConsentFor($visitA);
    $invoiceB = consentInvoiceFor($visitB);

    expect(fn () => app(RmePaymentService::class)->pay($invoiceB, $this->cashier, payDataFor(200000)))
        ->toThrow(ValidationException::class);
});

it('does not let another patient consent satisfy this visit', function () {
    $patientA = Patient::factory()->create(['branch_id' => $this->branch->id]);
    $patientB = Patient::factory()->create(['branch_id' => $this->branch->id]);

    $visitA = consentVisit();
    $visitA->forceFill(['patient_id' => $patientA->id])->save();
    $visitB = consentVisit();
    $visitB->forceFill(['patient_id' => $patientB->id])->save();

    signConsentFor($visitA->refresh());

    $invoiceB = consentInvoiceFor($visitB->refresh());

    expect(fn () => app(RmePaymentService::class)->pay($invoiceB, $this->cashier, payDataFor(200000)))
        ->toThrow(ValidationException::class);
});

it('does not let a consent from another branch satisfy this visit', function () {
    $otherBranch = Branch::factory()->create([
        'code' => 'CNS2',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);

    $otherVisit = consentVisit($otherBranch);
    signConsentFor($otherVisit);

    $visit = consentVisit();
    $invoice = consentInvoiceFor($visit);

    expect(fn () => app(RmePaymentService::class)->pay($invoice, $this->cashier, payDataFor(200000)))
        ->toThrow(ValidationException::class);
});

/*
|--------------------------------------------------------------------------
| C — signing rules
|--------------------------------------------------------------------------
*/

it('refuses to sign before the doctor has finished the examination', function () {
    $visit = consentVisit(status: ClinicVisit::STATUS_IN_PROGRESS);

    expect(fn () => signConsentFor($visit))->toThrow(ValidationException::class);
    expect(RmeVisitConsent::count())->toBe(0);
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

it('accepts a consent whose documentation answer is TIDAK and still opens payment', function () {
    // Refusing publication must never cost the patient their treatment or block
    // the cashier. This is the rule that keeps consent from becoming coercive.
    $visit = consentVisit();
    $invoice = consentInvoiceFor($visit);

    $consent = signConsentFor($visit, ['documentation_consent' => false]);

    expect($consent->documentation_consent)->toBeFalse();
    expect($consent->allowsDocumentationPublication())->toBeFalse();

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
});

it('treats a legacy cashier attestation as history but not as a payment gate', function () {
    // A visit settled before this sprint keeps its truthful historical record...
    $visit = consentVisit();
    $visit->forceFill([
        'consent_signed_by_patient' => true,
        'consent_signed_by_doctor' => true,
        'consent_verified_at' => now(),
    ])->save();

    expect($visit->refresh()->hasVerifiedConsent())->toBeTrue();

    // ...but that attestation is NOT a signed document and must not pay anything.
    expect($visit->hasSignedConsentDocument())->toBeFalse();

    $invoice = consentInvoiceFor($visit);
    expect(fn () => app(RmePaymentService::class)->pay($invoice, $this->cashier, payDataFor(200000)))
        ->toThrow(ValidationException::class);
});
