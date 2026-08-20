<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake('local');

    $this->branch = Branch::factory()->create([
        'code' => 'ZZT1', 'is_active' => true, 'is_rme_enabled' => true,
    ]);
    $this->cashier = userWith(['manage_rme_billing', 'manage_rme_consents', 'view_rme_consents']);
    $this->consentOperator = $this->cashier;
});

it('REPRO: a legacy PARTIAL invoice on a completed visit is permanently unpayable', function () {
    // 1. A visit that reached the cashier and was invoiced.
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => ClinicVisit::STATUS_CASHIER_PENDING,
    ]);
    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);
    $visit->refresh();

    $treatment = Treatment::factory()->create(['is_active' => true]);
    $invoice = app(RmeInvoiceService::class)->create($visit, $this->cashier, [
        'items' => [[
            'treatment_id' => $treatment->id,
            'description' => 'Konsultasi',
            'qty' => 1,
            'unit_price' => 200000,
        ]],
    ]);

    // 2. A partial payment: the shipped hotfix completes the visit.
    rmeSignedConsentFor($visit);
    app(RmePaymentService::class)->pay($invoice->refresh(), $this->cashier, [
        'amount' => 50000,
        'paid_at' => now()->toDateTimeString(),
    ]);

    $invoice->refresh();
    $visit->refresh();
    expect($invoice->status)->toBe(RmeInvoice::STATUS_PARTIAL);
    expect($visit->status)->toBe(ClinicVisit::STATUS_COMPLETED);
    expect($invoice->remainingAmount())->toEqual(150000.0);

    // 3. Simulate a PRE-DEPLOY receivable: the consent table is new, so every
    //    visit that existed at deploy time has no consent row at all.
    $visit->consents()->delete();
    expect($visit->refresh()->hasSignedConsentDocument())->toBeFalse();

    // 4. The invoice is still listed + linked as payable on the Piutang page.
    expect($invoice->isPayable())->toBeTrue();

    // 5. Direct collection of the installment now throws.
    $payFailed = false;
    try {
        app(RmePaymentService::class)->pay($invoice->refresh(), $this->cashier, [
            'amount' => 150000,
            'paid_at' => now()->toDateTimeString(),
        ]);
    } catch (ValidationException $e) {
        $payFailed = true;
        dump('PAY BLOCKED: '.json_encode($e->errors()));
    }
    expect($payFailed)->toBeTrue();

    // 6. And the remedy is unreachable: the visit is terminal, so no consent
    //    can ever be signed for it.
    $signFailed = false;
    try {
        app(RmeVisitConsentService::class)->sign($visit->refresh(), $this->consentOperator, [
            'template_code' => 'PERSETUJUAN_TINDAKAN_MEDIS',
            'consenter_relationship' => 'self',
            'medical_action' => 'Pencabutan gigi 36',
            'treatment_summary' => 'Pencabutan gigi',
            'documentation_consent' => false,
            'consenter_signature' => validPodSignatureData(),
        ]);
    } catch (ValidationException $e) {
        $signFailed = true;
        dump('SIGN BLOCKED: '.json_encode($e->errors()));
    }
    expect($signFailed)->toBeTrue();

    expect($invoice->refresh()->remainingAmount())->toEqual(150000.0);
});
