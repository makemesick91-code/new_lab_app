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

it('renders the polished payment page with a visible consent gate', function () {
    [$visit, $invoice] = uix5UnpaidInvoice($this->branch, $this->cashier);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.payment.create', [$visit, $invoice]))
        ->assertOk()
        ->assertSee('Pembayaran Tagihan RME')
        ->assertSee('Verifikasi Surat Persetujuan Tindakan (fisik)')
        // Consent field names must remain unchanged (payment logic preserved).
        ->assertSee('consent_signed_by_patient', false)
        ->assertSee('consent_signed_by_doctor', false);
});

it('still processes a full payment and renders the receipt after the polish', function () {
    [$visit, $invoice] = uix5UnpaidInvoice($this->branch, $this->cashier);

    // Payment logic path unchanged — settle the invoice via the service.
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

it('renders the payment consent gate through the x-ui.alert pattern with status badges', function () {
    $view = file_get_contents(resource_path('views/rme/cashier/payment/create.blade.php'));

    expect($view)->toContain('x-ui.page-header');
    expect($view)->toContain('x-ui.card');
    expect($view)->toContain('x-ui.badge');
    expect($view)->toContain('x-ui.button');
    // Consent gate uses the design-system alert component.
    expect($view)->toContain('x-ui.alert');
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
