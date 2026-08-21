<?php

// Sprint 62.1 — RME Doctor-to-Cashier Completion Gate
//
// Workflow under test:
//   Pendaftaran → Antrian → Input Ruangan → Pemeriksaan Dokter → Consent
//   → Status Selesai Pemeriksaan (cashier_pending) → Kasir / Pembayaran
//   → Status Selesai Visit (completed).
//
// Core invariants:
//   - The cashier cannot bill/pay before the doctor completes examination.
//   - The doctor can only advance to "Selesai Pemeriksaan" (cashier_pending),
//     never directly to "Selesai Visit" (completed).
//   - "Selesai Visit" is reached only after the cashier settles the invoice.
//   - Consent + room gates remain active.
//   - Supervisor RME may access the workflow but cannot bypass the gates.

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use Database\Seeders\BranchSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->branch = Branch::factory()->create(['code' => 'ATG3', 'is_rme_enabled' => true]);

    // FIX-05 — closing an examination needs the clinical capability, not just
    // the broad manage_clinic_visits the front office also holds.
    $this->doctorUser = userWith(['view_clinic_visits', 'manage_clinic_visits', 'complete_rme_examination']);
    $this->cashier = userWith(['manage_rme_billing']);

    // CORRECTIVE-02 condition 3 — an RME write needs a resolvable, authorized
    // actor; the gate fails closed without one. Individual tests override this.
    $this->actingAs($this->doctorUser);
});

// ─── Helpers ────────────────────────────────────────────────────────────────

function gateInProgressVisit(Branch $branch, array $overrides = []): ClinicVisit
{
    return ClinicVisit::factory()->inProgress()->create(array_merge([
        'branch_id' => $branch->id,
    ], $overrides));
}

function gateBillableVisit(Branch $branch): array
{
    $visit = ClinicVisit::factory()->cashierPending()->create(['branch_id' => $branch->id]);
    $record = MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    // FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-01 — RME payment now requires a
    // signed PERSETUJUAN TINDAKAN MEDIS. These tests are about the doctor →
    // cashier completion gate, so the fixture simply gives the visit one. The
    // consent gate itself has its own explicit test below.
    rmeSignedConsentFor($visit);

    return [$visit, $record];
}

function gateInvoiceFor(ClinicVisit $visit, $cashier, float $unitPrice = 150000): RmeInvoice
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

function gatePaymentPayload(array $overrides = []): array
{
    return array_merge([
        'amount' => 150000,
        'paid_at' => now()->toDateString(),
        'consent_signed_by_patient' => '1',
        'consent_signed_by_doctor' => '1',
    ], $overrides);
}

// ─── Rule 1: Cashier cannot pay before doctor completes examination ──────────

it('rejects billing before the doctor completes examination with the gate message', function () {
    $this->actingAs($this->cashier);

    $visit = gateInProgressVisit($this->branch);

    expect(fn () => gateInvoiceFor($visit, $this->cashier))
        ->toThrow(
            ValidationException::class,
            'Pembayaran belum dapat diproses karena pemeriksaan dokter belum selesai.'
        );
});

// ─── Rule 2: Doctor marks "Selesai Pemeriksaan" ──────────────────────────────

it('doctor can mark examination complete, moving the visit to cashier_pending', function () {
    $visit = gateInProgressVisit($this->branch);
    // CORRECTIVE-03 — a consented examination is the only one that can be finished.
    rmeSignedConsentFor($visit);

    $this->actingAs($this->doctorUser)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_CASHIER_PENDING])
        ->assertRedirect(route('rme.visits.show', $visit))
        ->assertSessionHas('status', 'Pemeriksaan selesai, pasien masuk ke kasir.');

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING);
});

// ─── Rule 2: RME finalization is NOT the doctor's "Selesai Pemeriksaan" ──────
//
// SUPERSEDED by FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-01. Finalizing a
// clinical DOCUMENT no longer completes the EXAMINATION. What this test still
// pins is preserved and inverted: the visit stays in_progress, and it reaches the
// cashier queue only through the doctor's explicit "Selesai Pemeriksaan".

it('finalizing the RME leaves the visit in_progress until the doctor explicitly completes the examination', function () {
    $visit = gateInProgressVisit($this->branch);
    rmeSignedConsentFor($visit);
    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);
    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $record->id,
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    app(MedicalRecordService::class)->finalize($record);

    // THE INVERSION: the document is final, the examination is not over.
    expect($record->refresh()->status)->toBe(MedicalRecord::STATUS_FINAL)
        ->and($visit->refresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);

    // ...and it is NOT yet in the cashier queue.
    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.index'))
        ->assertOk()
        ->assertDontSee($visit->visit_number);

    // The doctor's explicit action is what hands the patient over.
    $this->actingAs($this->doctorUser)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_CASHIER_PENDING]);

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.index'))
        ->assertOk()
        ->assertSee($visit->visit_number);
});

// ─── Rule 3 & 4: Doctor cannot mark visit fully completed ────────────────────

it('doctor cannot mark an in_progress visit as fully completed via the transition route', function () {
    $visit = gateInProgressVisit($this->branch);

    $this->actingAs($this->doctorUser)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_COMPLETED])
        ->assertSessionHasErrors('status');

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

it('doctor cannot mark a cashier_pending visit as completed via the transition route', function () {
    $visit = ClinicVisit::factory()->cashierPending()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->doctorUser)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_COMPLETED])
        ->assertSessionHasErrors('status');

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING);
});

it('service blocks completing a visit that has not reached the cashier with the friendly message', function () {
    $visit = gateInProgressVisit($this->branch);

    expect(fn () => app(ClinicVisitService::class)->transitionStatus($visit, ClinicVisit::STATUS_COMPLETED))
        ->toThrow(
            ValidationException::class,
            'Visit belum dapat diselesaikan total karena pembayaran belum selesai.'
        );

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

// ─── Rule 5: Full payment marks the visit "Selesai Visit" ────────────────────

it('full payment marks the invoice PAID and the visit completed', function () {
    $this->actingAs($this->cashier);

    [$visit] = gateBillableVisit($this->branch);
    $invoice = gateInvoiceFor($visit, $this->cashier, 150000);

    $this->post(route('rme.cashier.payment.store', [$visit, $invoice]), gatePaymentPayload(['amount' => 150000]))
        ->assertRedirect();

    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($visit->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);
});

// ─── Rule 6: Partial payment completes the visit, balance becomes piutang ────
// Hotfix (rme-partial-payment-completes-visit): a partial payment counts as
// "sudah membayar" — the visit becomes "Selesai Visit" (completed) and the
// remaining balance stays an active receivable, collected at a future follow-up.

it('partial payment keeps the invoice PARTIAL and completes the visit', function () {
    $this->actingAs($this->cashier);

    [$visit] = gateBillableVisit($this->branch);
    $invoice = gateInvoiceFor($visit, $this->cashier, 150000);

    $this->post(route('rme.cashier.payment.store', [$visit, $invoice]), gatePaymentPayload(['amount' => 50000]))
        ->assertRedirect();

    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PARTIAL)
        ->and($visit->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);
});

it('a partially paid invoice still appears as an active receivable after the visit completes', function () {
    $this->actingAs($this->cashier);

    [$visit] = gateBillableVisit($this->branch);
    $invoice = gateInvoiceFor($visit, $this->cashier, 150000);

    $this->post(route('rme.cashier.payment.store', [$visit, $invoice]), gatePaymentPayload(['amount' => 50000]));

    expect($visit->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED)
        ->and($invoice->fresh()->remainingAmount())->toBe(100000.0);

    // PARTIAL invoice with remaining > 0 stays an active receivable / piutang
    // even though the visit is completed.
    $this->get(route('rme.cashier.receivables'))
        ->assertOk()
        ->assertSee($invoice->invoice_number);
});

// ─── Rule 6: Unpaid invoice does not complete the visit ──────────────────────

it('an unpaid invoice leaves the visit at cashier_pending', function () {
    $this->actingAs($this->cashier);

    [$visit] = gateBillableVisit($this->branch);
    $invoice = gateInvoiceFor($visit, $this->cashier, 150000);

    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and($visit->fresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING);
});

// ─── Rule 7: Consent is NOT a payment gate ───────────────────────────────────
//
// SUPERSEDED by FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-03. Consent is
// taken at the START of the examination and gates RME authoring; payment does not
// consult it. The assertion is inverted rather than deleted, so a reintroduced
// payment consent gate fails here.

it('accepts payment even when no consent document exists for the visit', function () {
    $this->actingAs($this->cashier);

    [$visit] = gateBillableVisit($this->branch);
    $invoice = gateInvoiceFor($visit, $this->cashier, 150000);

    $visit->consents()->delete();

    $this->post(route('rme.cashier.payment.store', [$visit, $invoice]), [
        'amount' => 150000,
        'paid_at' => now()->toDateString(),
    ])->assertSessionHasNoErrors();

    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($visit->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);
});

// ─── Rule 8: Room gate still blocks doctor examination ───────────────────────

it('a roomless active visit cannot open the medical record (room gate intact)', function () {
    $visit = gateInProgressVisit($this->branch, ['clinic_room_id' => null]);

    expect($visit->requiresRoomBeforeExam())->toBeTrue();

    $this->actingAs($this->doctorUser)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertRedirect(route('rme.visits.show', $visit))
        ->assertSessionHas('error', 'Pasien belum ditempatkan ke ruangan perawatan.');
});

// ─── Rule 9: Supervisor RME can access but cannot bypass gates ───────────────

it('supervisor RME can access the cashier queue but cannot bypass the completion gate', function () {
    $supervisor = userInRole('Supervisor RME');

    $this->actingAs($supervisor)
        ->get(route('rme.cashier.index'))
        ->assertOk();

    // Cannot skip the cashier to mark a visit completed.
    $visit = gateInProgressVisit($this->branch);
    $this->actingAs($supervisor)
        ->post(route('rme.visits.transition', $visit), ['status' => ClinicVisit::STATUS_COMPLETED])
        ->assertSessionHasErrors('status');
    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);

    // Cannot bill before the doctor completes examination.
    $billTarget = gateInProgressVisit($this->branch);
    expect(fn () => gateInvoiceFor($billTarget, $supervisor))
        ->toThrow(ValidationException::class);
});

// ─── Rule 10: Owner KPI dashboard still renders (read-only) ──────────────────

it('owner KPI dashboard still renders with the gated status logic', function () {
    $owner = userWith(['view_owner_dashboard']);

    // Some pipeline state across statuses so KPI aggregation runs.
    gateInProgressVisit($this->branch);
    ClinicVisit::factory()->cashierPending()->create(['branch_id' => $this->branch->id]);
    ClinicVisit::factory()->completed()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk();
});

// ─── Rule 11/12: Paid visit completes and leaves active receivables ──────────

it('a fully paid invoice completes the visit and is excluded from active receivables', function () {
    $this->actingAs($this->cashier);

    [$visit] = gateBillableVisit($this->branch);
    $invoice = gateInvoiceFor($visit, $this->cashier, 150000);

    $this->post(route('rme.cashier.payment.store', [$visit, $invoice]), gatePaymentPayload(['amount' => 150000]));

    expect($visit->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);

    // Zero-remaining (PAID) invoices are excluded from active receivables.
    $this->get(route('rme.cashier.receivables'))
        ->assertOk()
        ->assertDontSee($invoice->invoice_number);
});
