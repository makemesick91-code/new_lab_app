<?php

/*
| FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — the HTTP surfaces of post-payment
| correction: the request form (mandatory owner approval reference), the checker's
| view of the ledger outcome, receipts reprinted from their immutable snapshots
| with the "DIREVISI" stamp (PP-5), and the read-only obligation list (PP-9).
*/

use App\Models\User;
use App\Modules\ClinicOversight\Services\OversightAuditService;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
use App\Modules\RmeInvoice\Models\RmeOverpaymentObligation;
use App\Modules\RmeInvoice\Models\RmeReceiptSnapshot;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/postpayment-helpers.php';

beforeEach(function () {
    ricSetup();
    rppFlag(true);
});

function rppOversightUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

// ─── Request form ───────────────────────────────────────────────────────────

it('shows the post-payment form with the reference marked required and the money already received', function () {
    $invoice = rppPaidInvoice(200000);
    ricActingAs(test()->maker);

    $this->get(route('rme.invoice-corrections.create', [$invoice->clinic_visit_id, $invoice->id]))
        ->assertOk()
        ->assertSee('Referensi persetujuan Owner (wajib)')
        ->assertSee('data-postpayment-outcome-notice', false)
        ->assertSee('data-correction-paid-total', false)
        ->assertSee('Rp 200.000', false);
});

it('refuses a post-payment submission without the reference and files it with one', function () {
    $invoice = rppPaidInvoice(200000);
    ricActingAs(test()->maker);
    $url = route('rme.invoice-corrections.store', [$invoice->clinic_visit_id, $invoice->id]);
    $payload = [
        'reason' => 'Harga tambal salah input oleh kasir.',
        'lines' => ricLinesReducing($invoice->fresh()),
    ];

    $this->post($url, $payload)->assertSessionHasErrors('owner_approval_reference');
    expect(RmeInvoiceCorrection::count())->toBe(0);

    $this->post($url, $payload + ['owner_approval_reference' => rppReference(), 'correction_type' => RmeInvoiceCorrectionType::PRE_PAYMENT])
        ->assertRedirect();

    $correction = RmeInvoiceCorrection::sole();
    expect($correction->correction_type)->toBe(RmeInvoiceCorrectionType::POST_PAYMENT)
        ->and($correction->owner_approval_reference)->toBe(rppReference());
});

it('shows the checker the projected outcome before approval and the recorded one after', function () {
    $invoice = rppPaidInvoice(500000);
    $correction = rppSubmit($invoice);
    ricActingAs(test()->checker);

    $this->get(route('rme.invoice-corrections.show', $correction->id))
        ->assertOk()
        ->assertSee('data-postpayment-ledger', false)
        ->assertSee('Rp 150.000', false)
        ->assertSee(rppReference());

    ricApprove($correction);
    ricActingAs(test()->checker);

    $this->get(route('rme.invoice-corrections.show', $correction->id))
        ->assertOk()
        ->assertSee('data-postpayment-obligation', false)
        ->assertSee(RmeOverpaymentObligation::sole()->obligation_number);
});

// ─── PP-5: receipts ─────────────────────────────────────────────────────────

it('renders the receipt exactly as before while the invoice was never corrected after payment', function () {
    $invoice = rppPaidInvoice(500000);
    ricActingAs(test()->cashier);

    $this->get(route('rme.cashier.receipt.show', [$invoice->clinic_visit_id, $invoice->id]))
        ->assertOk()
        ->assertDontSee('data-receipt-revised-stamp', false)
        ->assertDontSee('data-receipt-snapshot-version', false)
        ->assertSee('Rp 500.000', false);
});

it('reprints the issued receipt from its snapshot, stamped DIREVISI, after a post-payment correction', function () {
    $invoice = rppPaidInvoice(500000);
    ricApprove(rppSubmit($invoice)); // live total is now 350.000
    ricActingAs(test()->cashier);

    $page = $this->get(route('rme.cashier.receipt.show', [$invoice->clinic_visit_id, $invoice->id]))->assertOk();
    $page->assertSee('data-receipt-revised-stamp', false)
        ->assertSee('DIREVISI — LIHAT VERSI KE-1')
        ->assertSee('data-receipt-snapshot-version', false)
        ->assertSee('Rp 500.000', false)
        // The corrected total is not printed as if it were the receipt the patient got.
        ->assertDontSee('Rp 350.000', false);

    $receipt = RmeReceiptSnapshot::sole();
    $this->get(route('rme.cashier.receipt-snapshots.show', [$invoice->clinic_visit_id, $invoice->id, $receipt->id]))
        ->assertOk()
        ->assertSee('DIREVISI — LIHAT VERSI KE-1');

    $this->get(route('rme.cashier.receipt.pdf', [$invoice->clinic_visit_id, $invoice->id]))->assertOk();
});

it('reprints the payment method and cashier names that were printed, not today\'s renamed ones', function () {
    $invoice = ricInvoice();
    $method = PaymentMethod::factory()->create(['name' => 'Transfer Bank Lama', 'is_active' => true]);
    test()->actingAs(test()->cashier);
    app(RmePaymentService::class)->pay($invoice->fresh(), test()->cashier, rmeFreshPaymentForm($invoice->fresh(), [
        'amount' => 500000,
        'paid_at' => now()->format('Y-m-d H:i:s'),
        'payment_method_id' => $method->id,
    ]));
    $cashierName = test()->cashier->name;
    ricApprove(rppSubmit($invoice->fresh()));

    $method->forceFill(['name' => 'Transfer Bank Baru'])->save();
    test()->cashier->forceFill(['name' => 'Nama Kasir Diganti'])->save();
    ricActingAs(test()->cashier);

    $this->get(route('rme.cashier.receipt.show', [$invoice->clinic_visit_id, $invoice->id]))
        ->assertOk()
        ->assertSee('Transfer Bank Lama')
        ->assertDontSee('Transfer Bank Baru')
        // The receipt's signature line (the layout itself shows the logged-in
        // user's CURRENT name, which is correct and not the receipt).
        ->assertSee('<div class="line">'.e($cashierName).'</div>', false)
        ->assertDontSee('<div class="line">Nama Kasir Diganti</div>', false);
});

it('labels a reconstructed receipt as a reconstruction, never an identical reprint', function () {
    $invoice = rppPaidInvoice(500000);
    DB::table('trx_rme_receipt_snapshots')->delete(); // a payment from before receipt snapshots existed
    ricApprove(rppSubmit($invoice));
    ricActingAs(test()->cashier);

    $this->get(route('rme.cashier.receipt.show', [$invoice->clinic_visit_id, $invoice->id]))
        ->assertOk()
        ->assertSee('data-receipt-reconstructed', false);
});

it('keeps an issued receipt reprintable after an upward correction made the invoice partial again', function () {
    $invoice = rppPaidInvoice(500000);
    ricApprove(rppSubmit($invoice, rppLines($invoice, '400000', '200000'))); // PARTIAL now
    ricActingAs(test()->cashier);

    $this->get(route('rme.cashier.receipt.show', [$invoice->clinic_visit_id, $invoice->id]))->assertRedirect();

    $receipt = RmeReceiptSnapshot::sole();
    $this->get(route('rme.cashier.receipt-snapshots.show', [$invoice->clinic_visit_id, $invoice->id, $receipt->id]))
        ->assertOk()
        ->assertSee('DIREVISI — LIHAT VERSI KE-1');
});

it('refuses to print a receipt snapshot whose integrity no longer holds', function () {
    $invoice = rppPaidInvoice(500000);
    ricApprove(rppSubmit($invoice));
    $receipt = RmeReceiptSnapshot::sole();
    DB::table('trx_rme_receipt_snapshots')->where('id', $receipt->id)->update(['snapshot_sha256' => str_repeat('0', 64)]);
    ricActingAs(test()->cashier);

    $this->get(route('rme.cashier.receipt-snapshots.show', [$invoice->clinic_visit_id, $invoice->id, $receipt->id]))->assertStatus(409);
});

it('answers 404 for a receipt snapshot of another invoice', function () {
    $first = rppPaidInvoice(500000);
    $second = rppPaidInvoice(500000);
    $foreign = RmeReceiptSnapshot::where('rme_invoice_id', $second->id)->firstOrFail();
    ricActingAs(test()->cashier);

    $this->get(route('rme.cashier.receipt-snapshots.show', [$first->clinic_visit_id, $first->id, $foreign->id]))->assertNotFound();
});

it('shows the cashier the obligation status and the issued receipts on the invoice page', function () {
    $invoice = rppPaidInvoice(500000);
    ricApprove(rppSubmit($invoice));
    ricActingAs(test()->cashier);

    $this->get(route('rme.cashier.show', [$invoice->clinic_visit_id, $invoice->id]))
        ->assertOk()
        ->assertSee('data-invoice-obligation', false)
        ->assertSee(RmeOverpaymentObligation::sole()->obligation_number)
        ->assertSee('data-invoice-receipt-snapshots', false);
});

// ─── PP-9: obligation oversight, read-only ──────────────────────────────────

it('lets Finance Klinik read the obligation list and document, audited, without any write action', function () {
    $invoice = rppPaidInvoice(500000);
    ricApprove(rppSubmit($invoice));
    $obligation = RmeOverpaymentObligation::sole();

    $user = rppOversightUser('Finance Klinik');
    ricActingAs($user);

    $this->get(route('oversight.billing-corrections.obligations.index'))
        ->assertOk()
        ->assertSee($obligation->obligation_number)
        ->assertSee('Rp 150.000', false)
        ->assertDontSee('7371010101900001');

    $show = $this->get(route('oversight.billing-corrections.obligations.show', $obligation->id))
        ->assertOk()
        ->assertSee('KELEBIHAN BAYAR — BELUM DIKEMBALIKAN')
        ->assertDontSee('7371010101900001');
    // No form on the page posts to an obligation (the layout's own logout form aside).
    expect(preg_match('/<form[^>]+action="[^"]*overpayment-obligation/i', (string) $show->getContent()))->toBe(0);

    expect(AuditLog::where('action', OversightAuditService::OVERPAYMENT_OBLIGATION_VIEWED)->count())->toBe(1);
});

it('refuses the obligation list to an account without the oversight permission', function () {
    ricActingAs(test()->cashier);

    $this->get(route('oversight.billing-corrections.obligations.index'))->assertForbidden();
});

it('answers 404 for an obligation outside the oversight branch scope', function () {
    $invoice = rppPaidInvoice(500000);
    ricApprove(rppSubmit($invoice));
    $obligation = RmeOverpaymentObligation::sole();
    test()->branch->forceFill(['is_rme_enabled' => false])->save();

    ricActingAs(rppOversightUser('Finance Klinik'));
    $this->get(route('oversight.billing-corrections.obligations.show', $obligation->id))->assertNotFound();
    $this->get(route('oversight.billing-corrections.obligations.index'))->assertOk()->assertDontSee($obligation->obligation_number);
});

it('exposes no route that writes an obligation or a receipt snapshot', function () {
    $writes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [])
        ->filter(fn ($route) => str_contains($route->uri(), 'overpayment-obligation') || str_contains($route->uri(), 'billing/{rmeInvoice}/receipts'))
        ->map(fn ($route) => $route->uri())
        ->values()
        ->all();

    expect($writes)->toBe([]);
});
