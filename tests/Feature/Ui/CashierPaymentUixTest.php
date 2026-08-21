<?php

/**
 * UIX-5 — Kasir / Payment polish. Presentation-only; the cashier + payment
 * pages stay on the DaengtisiaMS design system and become the reference
 * implementation for every financial workflow page. No payment / consent /
 * receivable / invoice-status / transition logic changes, and no
 * controller/service/query/permission/BranchContext/route/schema change.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Artisan;

uses()->group('Ui', 'UiFoundation', 'RME', 'Cashier');

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->cashier = userWith(['manage_rme_billing']);
});

function uix5UnpaidInvoice(Branch $branch, User $cashier): array
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

    return [$visit->refresh(), $invoice->refresh()];
}

// ---------------------------------------------------------------------------
// Pages still render / authorize (no logic regression)
// ---------------------------------------------------------------------------

it('renders the polished cashier list for an authorized user', function () {
    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.index'))
        ->assertOk()
        ->assertSee('Kasir RME')
        ->assertSee('Daftar Kunjungan Siap Ditagih');
});

it('shows the design-system empty state when there are no billable visits', function () {
    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.index'))
        ->assertOk()
        ->assertSee('Belum ada kunjungan menunggu billing.');
});

it('redirects guests to login for the cashier list', function () {
    $this->get(route('rme.cashier.index'))->assertRedirect(route('login'));
});

it('forbids users without the cashier billing permission', function () {
    $this->actingAs(userWith(['view_clinic_visits']))
        ->get(route('rme.cashier.index'))
        ->assertForbidden();
});

it('renders the polished invoice detail page', function () {
    [$visit, $invoice] = uix5UnpaidInvoice($this->branch, $this->cashier);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.show', [$visit, $invoice]))
        ->assertOk()
        ->assertSee('Detail Tagihan RME')
        ->assertSee($invoice->invoice_number)
        ->assertSee($visit->patient->name);
});

it('never renders a consent gate on the payment page', function () {
    /*
     * SUPERSEDED by FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-03.
     *
     * The payment page used to report the consent state and carry two
     * acknowledgement checkboxes, because consent was the payment gate. Consent
     * is now taken at the start of the doctor's examination and gates RME
     * authoring, so the cashier has nothing here to verify, acknowledge or wait
     * for — and consent must never be shown as a reason a payment cannot proceed.
     *
     * Inverted rather than deleted: a reintroduced consent gate fails here.
     */
    [$visit, $invoice] = uix5UnpaidInvoice($this->branch, $this->cashier);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.payment.create', [$visit, $invoice]))
        ->assertOk()
        ->assertSee('Pembayaran Tagihan RME')
        ->assertDontSee('Verifikasi Surat Persetujuan Tindakan')
        ->assertDontSee('Consent belum ditandatangani')
        ->assertDontSee('consent_signed_by_patient', false)
        ->assertDontSee('consent_signed_by_doctor', false);
});

it('still renders the payment page normally once a consent exists elsewhere in the visit', function () {
    // A signed consent changes nothing on this page: it is not a payment input.
    [$visit, $invoice] = uix5UnpaidInvoice($this->branch, $this->cashier);
    rmeSignedConsentFor($visit);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.payment.create', [$visit, $invoice]))
        ->assertOk()
        ->assertSee('Pembayaran Tagihan RME')
        ->assertDontSee('Consent sudah ditandatangani');
});

it('still processes a full payment and renders the receipt after the polish', function () {
    [$visit, $invoice] = uix5UnpaidInvoice($this->branch, $this->cashier);

    // Payment logic path unchanged — settle the invoice via the service. Since
    // FIX-01 the visit needs a signed consent before it can be paid.
    rmeSignedConsentFor($visit);

    app(RmePaymentService::class)->pay($invoice, $this->cashier, [
        'amount' => $invoice->grand_total,
        'paid_at' => now()->format('Y-m-d H:i:s'),
    ]);

    expect($invoice->refresh()->status)->toBe('PAID');

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.receipt.show', [$visit, $invoice]))
        ->assertOk()
        ->assertSee('Kwitansi Pembayaran RME')
        ->assertSee('LUNAS');
});

it('renders the receivables page for an authorized cashier', function () {
    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.receivables'))
        ->assertOk()
        ->assertSee('Piutang RME');
});

// ---------------------------------------------------------------------------
// Design-system markers present; legacy brand color / gold-CTA / KTP-NIK gone
// ---------------------------------------------------------------------------

it('uses x-ui foundation components in the cashier list view', function () {
    $view = file_get_contents(resource_path('views/rme/cashier/index.blade.php'));

    expect($view)->toContain('x-ui.page-header');
    expect($view)->toContain('x-ui.filter-bar');
    expect($view)->toContain('x-ui.table');
    expect($view)->toContain('x-ui.badge');
    expect($view)->toContain('x-ui.button');
    expect($view)->toContain('x-ui.empty-state');
    expect($view)->not->toMatch('/\b(?:bg|text|border|ring)-teal-\d/');
});

it('keeps the payment page on the design-system foundation components', function () {
    $view = file_get_contents(resource_path('views/rme/cashier/payment/create.blade.php'));

    expect($view)->toContain('x-ui.page-header');
    expect($view)->toContain('x-ui.card');
    expect($view)->toContain('x-ui.badge');
    expect($view)->toContain('x-ui.button');
    // FIX-03 — x-ui.alert is no longer required here: the consent alert it was
    // asserting is removed, and a component-adoption rule must describe what the
    // page actually needs to show rather than force a banner whose subject moved.
    // No gold CTA, no legacy teal.
    expect($view)->not->toContain('variant="gold"');
    expect($view)->not->toMatch('/\b(?:bg|text|border|ring)-teal-\d/');
});

it('never renders KTP/NIK identity fields on any polished cashier surface', function () {
    $files = [
        'index.blade.php',
        'show.blade.php',
        'create.blade.php',
        'payment/create.blade.php',
        'receipt/show.blade.php',
        'receivables.blade.php',
        'handoff.blade.php',
        'follow-ups/create.blade.php',
    ];

    foreach ($files as $file) {
        $view = file_get_contents(resource_path('views/rme/cashier/'.$file));
        expect($view)->not->toMatch('/->(?:ktp_number|ktp|nik|identity_number)\b/');
        expect($view)->not->toContain('variant="gold"');
    }
});

// ---------------------------------------------------------------------------
// Governance command still GO with the added UIX-5 rules
// ---------------------------------------------------------------------------

it('passes the UI governance check with GO including UIX-5 rules', function () {
    $exit = Artisan::call('architecture:ui-governance-check', ['--json' => true, '--strict' => true]);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('"decision": "GO"');
});
