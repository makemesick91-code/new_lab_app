<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| REVISION-RME-MANUAL-PRICING-AUTHORITY-1 — invoice CORRECTION under the revised
| owner decision D21 (manual pricing).
|--------------------------------------------------------------------------
|
| A correction may move a price to any amount the server validates — it no
| longer has to equal (or even find) a branch tariff, and an optional tariff
| that changes after submission no longer makes the correction STALE. What
| stays: maker ≠ checker (Super Admin included), branch scope, pre-payment only,
| server-recomputed totals, the Rp0 refusal, D10 payment blocking, invoice
| version/fingerprint staleness for GENUINE changes, atomic idempotent apply,
| and immutable v0/v1 document versions.
*/

use App\Models\User;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceDocumentVersion;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionDenied;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
use App\Modules\RmeInvoice\Support\RmeInvoicePriceSource;
use App\Modules\Tariff\Models\Tariff;
use App\Modules\Tariff\Support\TariffPricingMode;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../RmeInvoiceCorrection/helpers.php';

beforeEach(fn () => ricSetup());

function rmpcExpectRefused(callable $work, string $code): void
{
    try {
        $work();
    } catch (ValidationException $e) {
        expect(json_encode($e->errors()))->toContain($code);

        return;
    }

    test()->fail('Expected a refusal with '.$code.'.');
}

/** An invoice in a branch whose tariff master has been emptied. */
function rmpcInvoiceWithoutTariffs(): RmeInvoice
{
    $invoice = ricInvoice();
    Tariff::query()->delete();

    return $invoice;
}

// ─── The tariff no longer decides a correction ─────────────────────────────

it('submits, approves and applies Rp500.000 → Rp350.000 in a branch with no tariff at all', function () {
    $invoice = rmpcInvoiceWithoutTariffs(); // Scaling 300.000 + Tambal 200.000 = 500.000

    $correction = ricApply(ricApprove(ricSubmit($invoice, lines: ricLinesReducing($invoice, '50000'))));

    $items = RmeInvoiceItem::query()->where('rme_invoice_id', $invoice->id)->orderBy('id')->get();
    $tambal = $items[1];
    expect($correction->status)->toBe(RmeInvoiceCorrectionStatus::APPLIED)
        ->and((float) $correction->original_grand_total)->toEqual(500000.0)
        ->and((float) $invoice->fresh()->grand_total)->toEqual(350000.0)
        ->and((float) $tambal->unit_price)->toEqual(50000.0)
        ->and($tambal->price_source)->toBe(RmeInvoicePriceSource::CORRECTION_MANUAL_ENTRY)
        ->and($tambal->pricing_mode)->toBe(TariffPricingMode::MANUAL)
        ->and($tambal->tariff_id)->toBeNull()
        ->and($tambal->tariff_unit_price)->toBeNull()
        // The KEPT line keeps the provenance of its original manual entry.
        ->and($items[0]->price_source)->toBe(RmeInvoicePriceSource::MANUAL_CASHIER_ENTRY);
});

it('accepts a corrected price that no tariff carries, while the branch does have tariffs', function () {
    $invoice = ricInvoice(); // branch tariffs: Scaling 300.000, Tambal 50.000

    $correction = ricSubmit($invoice, lines: ricLinesReducing($invoice, '175000'));

    expect($correction->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED)
        ->and((float) $correction->proposed_grand_total)->toEqual(475000.0)
        ->and((float) ricApply(ricApprove($correction))->fresh()->applied_invoice_version)->toEqual(1.0)
        ->and((float) $invoice->fresh()->grand_total)->toEqual(475000.0);
});

it('accepts a discount on a correction line within the line amount (no tariff gate)', function () {
    $invoice = rmpcInvoiceWithoutTariffs();
    $lines = ricLinesReducing($invoice, '200000');
    $lines[1]['discount'] = '25000';

    $correction = ricSubmit($invoice, lines: $lines);

    expect((float) $correction->proposed_grand_total)->toEqual(475000.0);
});

it('can correct an invoice carrying a free-text line', function () {
    $visit = ricVisit();
    $invoice = app(RmeInvoiceService::class)->create($visit, test()->cashier, ['items' => [
        ['description' => 'Scaling 99K (manual)', 'qty' => 1, 'unit_price' => 99000, 'discount' => 0, 'treatment_id' => null],
    ]])->refresh();
    $item = $invoice->items->sole();

    $correction = ricSubmit($invoice, lines: [[
        'original_item_id' => $item->id,
        'treatment_id' => null,
        'description' => 'Scaling 99K (manual)',
        'qty' => 1,
        'unit_price' => '89000',
        'discount' => '0',
    ]]);

    expect((float) ricApply(ricApprove($correction))->fresh()->proposed_grand_total)->toEqual(89000.0)
        ->and((float) $invoice->fresh()->grand_total)->toEqual(89000.0);
});

// REVISED by rule 195 (D1a): approval and application are one act now, so the
// tariff moves between submission and that act; the property is unchanged.
it('does not go STALE when the optional tariff changes between submission, approval and application', function () {
    $invoice = ricInvoice();
    $correction = ricSubmit($invoice); // Tambal 200.000 → 50.000

    Tariff::query()->update(['price' => '777000.00']);
    Tariff::query()->delete();
    $applied = ricApprove($correction);

    expect($applied->status)->toBe(RmeInvoiceCorrectionStatus::APPLIED)
        ->and($applied->stale_reason_code)->toBeNull()
        ->and((float) $invoice->fresh()->grand_total)->toEqual(350000.0);
});

it('recomputes the proposal on the server and ignores submitted totals', function () {
    $invoice = rmpcInvoiceWithoutTariffs();
    $lines = ricLinesReducing($invoice, '120000');
    $lines[1]['subtotal'] = '1';
    $lines[1]['grand_total'] = '1';

    $correction = ricSubmit($invoice, lines: $lines);

    expect((float) $correction->proposed_grand_total)->toEqual(420000.0)
        ->and((float) $correction->difference_amount)->toEqual(-80000.0);
});

// ─── What manual pricing does NOT relax ────────────────────────────────────

it('still refuses an Rp0 line or total in a correction, consultation included (no new Rp0 privilege)', function () {
    $consultation = Treatment::factory()->create(['is_active' => true, 'requires_lab' => false, 'name' => 'Konsultasi Dokter', 'code' => 'TRT-RMPCC']);
    $invoice = rmpcInvoiceWithoutTariffs();

    $lines = ricLinesReducing($invoice);
    $lines[1]['unit_price'] = '0';
    rmpcExpectRefused(fn () => ricSubmit($invoice, lines: $lines), 'CORRECTION_RP0_NOT_PERMITTED');

    $toConsultation = ricLinesReducing($invoice);
    $toConsultation[1]['treatment_id'] = $consultation->id;
    $toConsultation[1]['description'] = 'Konsultasi Dokter';
    $toConsultation[1]['unit_price'] = '0';
    rmpcExpectRefused(fn () => ricSubmit($invoice, lines: $toConsultation), 'CORRECTION_RP0_NOT_PERMITTED');
});

it('still refuses malformed money, a discount above the line and an inactive treatment', function () {
    $invoice = rmpcInvoiceWithoutTariffs();

    $malformed = ricLinesReducing($invoice, '1e5');
    rmpcExpectRefused(fn () => ricSubmit($invoice, lines: $malformed), 'CORRECTION_LINE_INVALID');

    $negative = ricLinesReducing($invoice);
    $negative[1]['discount'] = '60000';
    rmpcExpectRefused(fn () => ricSubmit($invoice, lines: $negative), 'CORRECTION_LINE_NEGATIVE');

    $inactive = Treatment::factory()->create(['is_active' => false, 'requires_lab' => false]);
    $added = ricLinesReducing($invoice);
    $added[] = ['treatment_id' => $inactive->id, 'description' => 'Tambahan', 'qty' => 1, 'unit_price' => '10000', 'discount' => '0'];
    rmpcExpectRefused(fn () => ricSubmit($invoice, lines: $added), 'CORRECTION_TREATMENT_INVALID');
});

it('still refuses a paid or partially paid invoice (pre-payment only)', function () {
    $invoice = rmpcInvoiceWithoutTariffs();
    ricPay($invoice, 100000);

    rmpcExpectRefused(fn () => ricSubmit($invoice->fresh()), 'CORRECTION_TYPE_NOT_ENABLED');
});

it('still refuses self-approval for every account, Super Admin included', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    // REVISED by rule 197 (owner D1e): designated as the maker so it may file at
    // all; self-approval is refused before identity is asked.
    ricDesignateMaker($superAdmin);
    $invoice = rmpcInvoiceWithoutTariffs();
    $correction = ricSubmit($invoice, maker: $superAdmin);

    expect(fn () => ricApprove($correction, $superAdmin))->toThrow(RmeInvoiceCorrectionDenied::class);
    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);
});

it('still blocks payment while a correction is active (D10)', function () {
    $invoice = rmpcInvoiceWithoutTariffs();
    ricSubmit($invoice);

    rmpcExpectRefused(fn () => ricPay($invoice, 100000), 'INVOICE_CORRECTION_ACTIVE');
    expect($invoice->fresh()->payments()->count())->toBe(0);
});

it('still goes STALE on a genuine change of the invoice (content), never on a tariff', function () {
    $invoice = rmpcInvoiceWithoutTariffs();
    $correction = ricSubmit($invoice);
    RmeInvoiceItem::query()->where('rme_invoice_id', $invoice->id)->orderBy('id')->first()->update(['description' => 'Diubah di luar alur']);

    // REVISED by rule 195 (D1a): the approval (which applies) is where staleness is caught.
    rmpcExpectRefused(fn () => ricApprove($correction), 'CORRECTION_STALE:INVOICE_CONTENT_CHANGED');
    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::STALE);
});

it('applies idempotently and freezes the v0 original and the v1 revision', function () {
    $invoice = rmpcInvoiceWithoutTariffs();
    $applied = ricApply(ricApprove(ricSubmit($invoice, lines: ricLinesReducing($invoice, '120000'))));
    $again = ricApply($applied->fresh());

    $versions = RmeInvoiceDocumentVersion::query()->where('rme_invoice_id', $invoice->id)->orderBy('version_number')->get();
    expect($again->status)->toBe(RmeInvoiceCorrectionStatus::APPLIED)
        ->and($versions->pluck('version_number')->all())->toBe([0, 1])
        ->and((int) $versions[0]->snapshot['totals']['grand_total_cents'])->toBe(50000000)
        ->and((int) $versions[1]->snapshot['totals']['grand_total_cents'])->toBe(42000000)
        ->and((int) $invoice->fresh()->correction_version)->toBe(1);
});

it('keeps the correction service free of any tariff dependency', function () {
    $source = file_get_contents(app_path('Modules/RmeInvoice/Services/RmeInvoiceCorrectionService.php'));

    expect($source)->not->toContain('BranchTariffAuthorityService')
        ->and($source)->not->toContain('TARIFF_AUTHORITY_CHANGED')
        ->and($source)->not->toContain('CORRECTION_TARIFF_');
});
