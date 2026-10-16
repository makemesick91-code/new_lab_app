<?php

/*
| FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — the HTTP surfaces:
| route middleware, policy, branch scope, the default-OFF capability flag, the
| read-only Finance Klinik / Data Analyst oversight, sidebar visibility, and
| document-version rendering. Lifecycle rules are pinned in the service suite.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicOversight\Services\OversightAuditService;
use App\Modules\ClinicOversight\Support\ClinicOversightScope;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
use App\Modules\RmeInvoice\Models\RmeInvoiceDocumentVersion;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionDenied;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => ricSetup());

function ricOversightUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

// ─── Capability flag ────────────────────────────────────────────────────────

it('answers 404 on every correction MUTATION route while the flag is off, and keeps history readable', function () {
    $invoice = ricInvoice();
    $correction = ricSubmit($invoice);
    ricFlag(false);
    ricActingAs(test()->maker);

    $this->get(route('rme.invoice-corrections.create', [$invoice->clinic_visit_id, $invoice->id]))->assertNotFound();
    $this->post(route('rme.invoice-corrections.store', [$invoice->clinic_visit_id, $invoice->id]), [])->assertNotFound();
    $this->post(route('rme.invoice-corrections.cancel', $correction->id), ['note' => 'Batal karena salah tagihan.'])->assertNotFound();

    ricActingAs(test()->checker);
    $this->post(route('rme.invoice-corrections.approve', $correction->id))->assertNotFound();
    $this->post(route('rme.invoice-corrections.reject', $correction->id), ['note' => 'Ditolak karena sudah benar.'])->assertNotFound();

    ricActingAs(test()->applier);
    $this->post(route('rme.invoice-corrections.apply', $correction->id))->assertNotFound();

    ricActingAs(test()->checker);
    $this->get(route('rme.invoice-corrections.show', $correction->id))->assertOk()->assertSee($correction->correction_number);
    $this->get(route('rme.invoice-corrections.index'))->assertOk()->assertSee('belum diaktifkan', false);

    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);
});

it('leaves the cashier invoice page exactly as before while the flag is off and nothing was corrected', function () {
    $invoice = ricInvoice();
    ricFlag(false);
    ricActingAs(test()->maker);

    $this->get(route('rme.cashier.show', [$invoice->clinic_visit_id, $invoice->id]))
        ->assertOk()
        ->assertDontSee('data-correction-panel', false)
        ->assertDontSee('Ajukan Koreksi');
});

// ─── Full HTTP lifecycle ───────────────────────────────────────────────────

it('runs submit → approve → apply over HTTP with three different accounts', function () {
    $invoice = ricInvoice();
    $lines = ricLinesReducing($invoice);

    ricActingAs(test()->maker);
    $this->get(route('rme.invoice-corrections.create', [$invoice->clinic_visit_id, $invoice->id]))
        ->assertOk()->assertSee('data-correction-form', false);
    $this->post(route('rme.invoice-corrections.store', [$invoice->clinic_visit_id, $invoice->id]), [
        'reason' => 'Harga tambal salah input oleh kasir.',
        'idempotency_key' => '0f1e2d3c-4b5a-6978-8a9b-0c1d2e3f4a5b',
        'lines' => $lines,
        'grand_total' => '1',   // never trusted
    ])->assertRedirect();

    $correction = RmeInvoiceCorrection::sole();
    expect((float) $correction->proposed_grand_total)->toEqual(350000.0);

    // The maker sees no approve button on their own request.
    $this->get(route('rme.invoice-corrections.show', $correction->id))->assertOk()->assertDontSee('data-correction-approve', false);
    $this->post(route('rme.invoice-corrections.approve', $correction->id))->assertForbidden();

    ricActingAs(test()->checker);
    $this->post(route('rme.invoice-corrections.approve', $correction->id))->assertRedirect();
    $this->post(route('rme.invoice-corrections.apply', $correction->id))->assertForbidden();

    ricActingAs(test()->applier);
    $this->post(route('rme.invoice-corrections.apply', $correction->id))->assertRedirect();

    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::APPLIED)
        ->and((float) $invoice->fresh()->grand_total)->toEqual(350000.0);
});

it('refuses a correction route to an account without any correction permission', function () {
    $invoice = ricInvoice();
    $correction = ricSubmit($invoice);
    ricActingAs(test()->cashier);   // manage_rme_billing only

    $this->get(route('rme.invoice-corrections.index'))->assertForbidden();
    $this->get(route('rme.invoice-corrections.show', $correction->id))->assertForbidden();
    $this->get(route('rme.invoice-corrections.create', [$invoice->clinic_visit_id, $invoice->id]))->assertForbidden();
    $this->post(route('rme.invoice-corrections.approve', $correction->id))->assertForbidden();
    $this->post(route('rme.invoice-corrections.apply', $correction->id))->assertForbidden();
});

it('refuses an invoice paired with a visit it does not belong to', function () {
    $invoice = ricInvoice();
    $otherVisit = ricVisit();
    ricActingAs(test()->maker);

    $this->get(route('rme.invoice-corrections.create', [$otherVisit->id, $invoice->id]))->assertNotFound();
    $this->post(route('rme.invoice-corrections.store', [$otherVisit->id, $invoice->id]), [
        'reason' => 'Harga tambal salah input oleh kasir.', 'lines' => ricLinesReducing($invoice),
    ])->assertNotFound();
    expect(RmeInvoiceCorrection::count())->toBe(0);
});

it('never trusts a submitted branch_id and ignores every crafted total', function () {
    $invoice = ricInvoice();
    $other = Branch::factory()->create(['code' => 'IC02', 'is_rme_enabled' => true, 'is_active' => true]);
    ricActingAs(test()->maker);

    $this->post(route('rme.invoice-corrections.store', [$invoice->clinic_visit_id, $invoice->id]), [
        'reason' => 'Harga tambal salah input oleh kasir.',
        'lines' => ricLinesReducing($invoice),
        'branch_id' => $other->id,
        'proposed_grand_total' => '1',
        'difference_amount' => '0',
    ])->assertRedirect();

    $correction = RmeInvoiceCorrection::sole();
    expect((int) $correction->branch_id)->toBe((int) $invoice->branch_id)
        ->and((float) $correction->proposed_grand_total)->toEqual(350000.0)
        ->and((float) $correction->difference_amount)->toEqual(-150000.0);
});

// ─── Branch scope ───────────────────────────────────────────────────────────

it('pins a context-bound approver to their working branch: another branch is refused and invisible', function () {
    $correction = ricSubmit(ricInvoice());
    $elsewhere = Branch::factory()->create(['code' => 'IC09', 'is_rme_enabled' => true, 'is_active' => true]);

    $kasir = User::factory()->create();
    $kasir->givePermissionTo('approve_rme_invoice_correction');
    rmeMakeKasirActive($kasir, $elsewhere);
    ricActingAs($kasir->fresh());

    $this->get(route('rme.invoice-corrections.show', $correction->id))->assertNotFound();
    $this->post(route('rme.invoice-corrections.approve', $correction->id))->assertNotFound();
    $this->get(route('rme.invoice-corrections.index'))->assertOk()->assertDontSee($correction->correction_number);

    // The service refuses independently of the route.
    expect(fn () => ricApprove($correction, $kasir->fresh()))->toThrow(RmeInvoiceCorrectionDenied::class);
    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);
});

// ─── Frozen document versions ───────────────────────────────────────────────

it('renders the frozen original and revision from their snapshots, marking the superseded original', function () {
    $invoice = ricInvoice();
    ricApply(ricApprove(ricSubmit($invoice)));
    [$v0, $v1] = RmeInvoiceDocumentVersion::orderBy('version_number')->get()->all();

    // Mutating the live invoice afterwards must not alter the frozen v0 rendering.
    DB::table('trx_rme_invoice_items')->where('rme_invoice_id', $invoice->id)->update(['description' => 'Teks hidup yang berubah']);

    ricActingAs(test()->checker);
    $this->get(route('rme.invoice-corrections.document-versions.show', $v0->id))
        ->assertOk()
        ->assertSee('VERSI ASLI')
        ->assertSee('DIGANTIKAN OLEH')
        ->assertSee('Tambal komposit gigi 36')
        ->assertSee('Rp 500.000', false)
        ->assertDontSee('Teks hidup yang berubah');

    $this->get(route('rme.invoice-corrections.document-versions.show', $v1->id))
        ->assertOk()
        ->assertSee('REVISI KE-1')
        ->assertSee('Rp 350.000', false)
        ->assertDontSee('7371010101900001');
});

it('marks the printed receipt document of a revised invoice with its version', function () {
    $invoice = ricInvoice();
    ricApply(ricApprove(ricSubmit($invoice)));
    ricPay($invoice, 350000);

    ricActingAs(test()->cashier);
    $this->get(route('rme.cashier.receipt.show', [$invoice->clinic_visit_id, $invoice->id]))
        ->assertOk()
        ->assertSee('Revisi ke-1')
        ->assertSee('Rp 350.000', false);
});

// ─── Read-only oversight (Finance Klinik / Data Analyst) ───────────────────

it('lets Finance Klinik and Data Analyst read the correction history, but never mutate it', function () {
    $invoice = ricInvoice();
    $correction = ricApply(ricApprove(ricSubmit($invoice)));

    foreach (['Finance Klinik', 'Data Analyst'] as $role) {
        $user = ricOversightUser($role);
        ricActingAs($user);

        $this->get(route('oversight.billing-corrections.index'))->assertOk()->assertSee($correction->correction_number);
        $this->get(route('oversight.billing-corrections.patients'))->assertOk()->assertSee('Pasien Rahasia Koreksi');
        $this->get(route('oversight.billing-corrections.show', $correction->id))
            ->assertOk()
            ->assertSee('Rp 500.000', false)
            ->assertSee('Rp 350.000', false)
            ->assertSee('refund belum diimplementasikan', false)
            ->assertDontSee('7371010101900001')
            ->assertDontSee('data-correction-approve', false);

        // Every operational correction route is refused.
        $this->get(route('rme.invoice-corrections.index'))->assertForbidden();
        $this->get(route('rme.invoice-corrections.create', [$invoice->clinic_visit_id, $invoice->id]))->assertForbidden();
        $this->post(route('rme.invoice-corrections.approve', $correction->id))->assertForbidden();
        $this->post(route('rme.invoice-corrections.apply', $correction->id))->assertForbidden();
        $this->post(route('rme.invoice-corrections.cancel', $correction->id), ['note' => 'Coba batalkan dari oversight.'])->assertForbidden();

        expect($user->can('request_rme_invoice_correction'))->toBeFalse()
            ->and($user->can('approve_rme_invoice_correction'))->toBeFalse()
            ->and($user->can('apply_rme_invoice_correction'))->toBeFalse()
            ->and($user->can('manage_rme_billing'))->toBeFalse();
    }

    expect(AuditLog::where('action', OversightAuditService::BILLING_CORRECTION_VIEWED)->count())->toBe(2);
});

it('exports, prints and renders a PDF of corrections only with the export permission', function () {
    $correction = ricApply(ricApprove(ricSubmit(ricInvoice())));
    $user = ricOversightUser('Data Analyst');
    ricActingAs($user);

    $csv = $this->get(route('oversight.billing-corrections.export'))->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv');
    $body = $csv->streamedContent();
    expect($body)->toContain($correction->correction_number)
        ->and($body)->not->toContain('7371010101900001');

    $this->get(route('oversight.billing-corrections.print'))->assertOk()->assertSee($correction->correction_number);
    $this->get(route('oversight.billing-corrections.show.print', $correction->id))->assertOk();
    $pdf = $this->get(route('oversight.billing-corrections.show.pdf', $correction->id))->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('application/pdf')
        ->and((string) $pdf->headers->get('content-disposition'))->not->toContain('Pasien');

    Role::findByName('Data Analyst', 'web')->revokePermissionTo(ClinicOversightScope::PERMISSION_EXPORT);
    ricActingAs($user->fresh());

    $this->get(route('oversight.billing-corrections.export'))->assertForbidden();
    $this->get(route('oversight.billing-corrections.show.pdf', $correction->id))->assertForbidden();
    $this->get(route('oversight.billing-corrections.show', $correction->id))->assertOk()
        ->assertDontSee(route('oversight.billing-corrections.show.pdf', $correction->id), false);
});

it('answers 404 for a correction outside the oversight branch scope', function () {
    $correction = ricSubmit(ricInvoice());
    test()->branch->forceFill(['is_rme_enabled' => false])->save();

    ricActingAs(ricOversightUser('Finance Klinik'));
    $this->get(route('oversight.billing-corrections.show', $correction->id))->assertNotFound();
    $this->get(route('oversight.billing-corrections.index'))->assertOk()->assertDontSee($correction->correction_number);
});

it('filters the oversight history by status, branch and inclusive WITA date range', function () {
    $correction = ricSubmit(ricInvoice());
    ricActingAs(ricOversightUser('Finance Klinik'));

    $today = clinicalToday()->toDateString();
    $this->get(route('oversight.billing-corrections.index', ['start_date' => $today, 'end_date' => $today, 'status' => 'SUBMITTED', 'branch_id' => test()->branch->id]))
        ->assertOk()->assertSee($correction->correction_number);
    $this->get(route('oversight.billing-corrections.index', ['status' => 'APPLIED']))
        ->assertOk()->assertDontSee($correction->correction_number);
    $this->get(route('oversight.billing-corrections.index', ['start_date' => '2026-01-01', 'end_date' => '2026-01-02']))
        ->assertOk()->assertDontSee($correction->correction_number);
});

it('shows the Billing Correction sidebar group to the oversight roles only', function () {
    ricActingAs(ricOversightUser('Finance Klinik'));
    $this->get(route('oversight.billing-corrections.index'))
        ->assertOk()
        ->assertSee('Daftar Pasien Koreksi')
        ->assertSee('Riwayat &amp; Detail Koreksi', false);

    $lab = User::factory()->create();
    $lab->assignRole('Finance');
    ricActingAs($lab->fresh());
    $this->get(route('oversight.billing-corrections.index'))->assertForbidden();
});

// ─── Adversarial-review regressions ─────────────────────────────────────────

it('shows the approver the original and proposed treatment and doctor of every line, marking a treatment change', function () {
    $invoice = ricInvoice();
    $lines = ricLinesReducing($invoice);
    $lines[1]['treatment_id'] = test()->treatment->id;   // Tambal → Scaling
    $correction = ricSubmit($invoice, lines: $lines);

    ricActingAs(test()->checker);
    $this->get(route('rme.invoice-corrections.show', $correction->id))
        ->assertOk()
        ->assertSee('data-line-original-attribution', false)
        ->assertSee('data-line-proposed-attribution', false)
        ->assertSee('Tindakan: Tambal')
        ->assertSee('Tindakan: Scaling')
        ->assertSee('Tindakan berubah');

    ricActingAs(ricOversightUser('Finance Klinik'));
    $this->get(route('oversight.billing-corrections.show', $correction->id))
        ->assertOk()->assertSee('tindakan berubah');
});

it('offers an item\'s current treatment in the form even after it was deactivated', function () {
    $invoice = ricInvoice();
    test()->treatment2->forceFill(['is_active' => false])->save();
    ricActingAs(test()->maker);

    $this->get(route('rme.invoice-corrections.create', [$invoice->clinic_visit_id, $invoice->id]))
        ->assertOk()
        ->assertSee('Tambal (nonaktif)');
});

it('answers 404 for the correction form of an invoice outside the maker\'s working branch', function () {
    $invoice = ricInvoice();
    $elsewhere = Branch::factory()->create(['code' => 'IC08', 'is_rme_enabled' => true, 'is_active' => true]);
    $kasir = User::factory()->create();
    $kasir->givePermissionTo('request_rme_invoice_correction');
    rmeMakeKasirActive($kasir, $elsewhere);
    ricActingAs($kasir->fresh());

    $this->get(route('rme.invoice-corrections.create', [$invoice->clinic_visit_id, $invoice->id]))->assertNotFound();
    $this->post(route('rme.invoice-corrections.store', [$invoice->clinic_visit_id, $invoice->id]), [
        'reason' => 'Harga tambal salah input oleh kasir.', 'lines' => ricLinesReducing($invoice),
    ])->assertNotFound();
    expect(RmeInvoiceCorrection::count())->toBe(0);
});
