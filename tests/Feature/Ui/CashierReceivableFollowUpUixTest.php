<?php

/**
 * UIX-12 — RME cashier, receivable & follow-up polish. Presentation-only; the
 * cashier / invoice / payment / receivable / carry-over / follow-up surfaces
 * adopt a standardized financial summary card (x-rme.invoice-summary → total /
 * paid / remaining / status). No payment / consent / receivable / carry-over /
 * invoice-status / visit-completion logic changes, and no controller / service /
 * repository / permission / BranchContext / route / schema change.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Artisan;

uses()->group('Ui', 'UiFoundation', 'RME', 'Cashier', 'Receivable');

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->cashier = userWith(['manage_rme_billing']);
});

function uix12UnpaidInvoice(Branch $branch, User $cashier): array
{
    $visit = ClinicVisit::factory()->cashierPending()->create(['branch_id' => $branch->id]);
    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    $invoice = app(RmeInvoiceService::class)->create($visit, $cashier, [
        'items' => [[
            'description' => 'Pembersihan Karang Gigi',
            'qty' => 1,
            'unit_price' => 500000,
            'discount' => 0,
        ]],
    ]);

    // FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-01 — RME payment now requires a
    // signed PERSETUJUAN TINDAKAN MEDIS. This suite is not about consent, so the
    // fixture simply gives the visit one.
    rmeSignedConsentFor($visit);

    return [$visit->refresh(), $invoice->refresh()];
}

// ---------------------------------------------------------------------------
// Pages still render / authorize (no logic regression)
// ---------------------------------------------------------------------------

it('renders the invoice detail with the standardized financial summary card', function () {
    [$visit, $invoice] = uix12UnpaidInvoice($this->branch, $this->cashier);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.show', [$visit, $invoice]))
        ->assertOk()
        ->assertSee('Detail Tagihan RME')
        ->assertSee('Total Tagihan')
        ->assertSee('Sudah Dibayar')
        ->assertSee('Sisa Tagihan')
        ->assertSee('Status Tagihan');
});

it('renders the payment page with the financial summary card and consent gate intact', function () {
    [$visit, $invoice] = uix12UnpaidInvoice($this->branch, $this->cashier);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.payment.create', [$visit, $invoice]))
        ->assertOk()
        ->assertSee('Total Tagihan')
        ->assertSee('Sisa Tagihan')
        // FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-01 — the panel now REPORTS the
        // consent state (this fixture's visit has a signed consent) instead of
        // asking the cashier to assert it. The checkbox field names are preserved.
        ->assertSee('Verifikasi Surat Persetujuan Tindakan')
        ->assertSee('Consent sudah ditandatangani')
        ->assertSee('consent_signed_by_patient', false)
        ->assertSee('consent_signed_by_doctor', false);
});

it('shows a partial-payment status label on the invoice list after a partial payment', function () {
    [$visit, $invoice] = uix12UnpaidInvoice($this->branch, $this->cashier);

    // Business path unchanged — a partial payment via the service.
    app(RmePaymentService::class)->pay($invoice, $this->cashier, [
        'amount' => 200000,
        'paid_at' => now()->format('Y-m-d H:i:s'),
        'consent_signed_by_patient' => true,
        'consent_signed_by_doctor' => true,
    ]);

    expect($invoice->refresh()->status)->toBe('PARTIAL');

    // The list view now has a defined label/tone for PARTIAL (clarity).
    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.show', [$visit, $invoice]))
        ->assertOk()
        ->assertSee('Cicilan / Sebagian');
});

it('still processes a full payment and renders the receipt after the polish', function () {
    [$visit, $invoice] = uix12UnpaidInvoice($this->branch, $this->cashier);

    app(RmePaymentService::class)->pay($invoice, $this->cashier, [
        'amount' => $invoice->grand_total,
        'paid_at' => now()->format('Y-m-d H:i:s'),
        'consent_signed_by_patient' => true,
        'consent_signed_by_doctor' => true,
    ]);

    expect($invoice->refresh()->status)->toBe('PAID');

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.receipt.show', [$visit, $invoice]))
        ->assertOk()
        ->assertSee('LUNAS');
});

// ---------------------------------------------------------------------------
// Design-system markers present; no legacy brand color / gold-CTA / KTP-NIK
// ---------------------------------------------------------------------------

it('defines the shared financial summary component with all four money states', function () {
    $view = file_get_contents(resource_path('views/components/rme/invoice-summary.blade.php'));

    expect($view)->toContain('Total Tagihan');
    expect($view)->toContain('Sudah Dibayar');
    expect($view)->toContain('Sisa Tagihan');
    expect($view)->toContain('Status Tagihan');
    expect($view)->toContain('x-ui.badge');
    // Presentation-only: reads accessors, never renders KTP/NIK, no legacy teal.
    expect($view)->not->toMatch('/->(?:ktp_number|ktp|nik|identity_number)\b/');
    expect($view)->not->toMatch('/\b(?:bg|text|border|ring)-teal-\d/');
});

it('reuses the shared invoice summary on the detail and payment surfaces', function () {
    foreach (['show.blade.php', 'payment/create.blade.php'] as $file) {
        $view = file_get_contents(resource_path('views/rme/cashier/'.$file));
        expect($view)->toContain('x-rme.invoice-summary');
    }
});

it('never renders KTP/NIK identity fields or gold CTAs on the polished financial surfaces', function () {
    $files = [
        'show.blade.php',
        'payment/create.blade.php',
        'receivables.blade.php',
        'follow-ups/create.blade.php',
    ];

    foreach ($files as $file) {
        $view = file_get_contents(resource_path('views/rme/cashier/'.$file));
        expect($view)->not->toMatch('/->(?:ktp_number|ktp|nik|identity_number)\b/');
        expect($view)->not->toContain('variant="gold"');
        expect($view)->not->toMatch('/\b(?:bg|text|border|ring)-teal-\d/');
    }
});

// ---------------------------------------------------------------------------
// Governance command still GO with the added UIX-12 rules
// ---------------------------------------------------------------------------

it('passes the UI governance check with GO including UIX-12 rules', function () {
    $exit = Artisan::call('architecture:ui-governance-check', ['--json' => true, '--strict' => true]);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('"decision": "GO"');
});
