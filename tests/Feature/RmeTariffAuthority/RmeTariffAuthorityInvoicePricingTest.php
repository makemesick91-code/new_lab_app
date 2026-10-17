<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — server-side price of every RME invoice
| line (owner decision D21).
|--------------------------------------------------------------------------
|
| TRANSITIONAL (default): the legacy cashier price is still accepted, and every
| line records its provenance against the branch tariff, so it is never
| presented as master pricing. STRICT (explicit opt-in): the tariff is the price
| and everything else is refused with a stable code, audited after the rollback.
| ALL MODES: integer cents, discount never above the line, overflow refused,
| totals computed on the server.
*/

use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use App\Modules\RmeInvoice\Services\RmeInvoicePricingService;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmeInvoicePriceSource;
use App\Modules\RmeInvoice\Support\RmeInvoicePricingRefusal;
use App\Modules\RmeInvoice\Support\RmeTariffExceptionPermission;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Modules\Tariff\Support\TariffPricingMode;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => rtaSetup());

/** Expect a refusal on `items` carrying the stable code. */
function rtaExpectRefused(callable $work, string $code): void
{
    try {
        $work();
    } catch (ValidationException $e) {
        expect(implode(' ', $e->errors()['items'] ?? []))->toContain($code);

        return;
    }

    test()->fail('The invoice was accepted; it should have been refused with '.$code.'.');
}

// ─── TRANSITIONAL: legacy workflow kept, provenance recorded ───────────────

it('records TARIFF_MATCHED for a line billed exactly at the branch tariff', function () {
    $invoice = rtaCreate(rtaVisit(), [rtaLine(test()->scaling, 250000)]);
    $item = $invoice->items->sole();

    expect($item->pricing_mode)->toBe(TariffPricingMode::TRANSITIONAL)
        ->and($item->price_source)->toBe(RmeInvoicePriceSource::TARIFF_MATCHED)
        ->and((int) $item->tariff_id)->toBe((int) test()->scalingTariff->id)
        ->and((float) $item->tariff_unit_price)->toEqual(250000.0);
});

it('keeps a transitional cashier price that deviates from the tariff, recording the deviation and the tariff', function () {
    $invoice = rtaCreate(rtaVisit(), [rtaLine(test()->scaling, 200000)]);
    $item = $invoice->items->sole();

    expect((float) $item->unit_price)->toEqual(200000.0)
        ->and($item->price_source)->toBe(RmeInvoicePriceSource::TRANSITIONAL_TARIFF_DEVIATION)
        ->and((float) $item->tariff_unit_price)->toEqual(250000.0)
        ->and((float) $invoice->grand_total)->toEqual(200000.0);
});

it('records a discount on a matched price as a transitional deviation', function () {
    $invoice = rtaCreate(rtaVisit(), [rtaLine(test()->scaling, 250000, 10000)]);

    expect($invoice->items->sole()->price_source)->toBe(RmeInvoicePriceSource::TRANSITIONAL_TARIFF_DEVIATION)
        ->and((float) $invoice->grand_total)->toEqual(240000.0);
});

it('records NO_TARIFF in a branch without tariffs and FREE_TEXT for a manual line', function () {
    $invoice = rtaCreate(rtaVisit(test()->otherBranch), [
        rtaLine(test()->scaling, 175000),
        rtaLine(null, 50000),
    ]);
    $items = $invoice->items->sortBy('id')->values();

    expect($items[0]->price_source)->toBe(RmeInvoicePriceSource::TRANSITIONAL_NO_TARIFF)
        ->and($items[0]->tariff_id)->toBeNull()
        ->and($items[1]->price_source)->toBe(RmeInvoicePriceSource::TRANSITIONAL_FREE_TEXT)
        ->and((float) $invoice->grand_total)->toEqual(225000.0);
});

it('keeps the transitional Rp0 behaviour of the legacy workflow (not a new exception), and audits it as a zero line', function () {
    $invoice = rtaCreate(rtaVisit(test()->otherBranch), [rtaLine(test()->scaling, 0)]);

    $audit = AuditLog::query()->where('action', RmeFinancialAuditEvent::ACTION_INVOICE_CREATED)->sole();
    expect((float) $invoice->grand_total)->toEqual(0.0)
        ->and($audit->new_values['pricing_mode'])->toBe(TariffPricingMode::TRANSITIONAL)
        ->and($audit->new_values['zero_value_lines'])->toBe(1);
});

it('never rewrites what was compared: a later tariff edit leaves the line evidence unchanged', function () {
    $invoice = rtaCreate(rtaVisit(), [rtaLine(test()->scaling, 250000)]);
    test()->scalingTariff->update(['price' => '400000.00']);

    $item = RmeInvoiceItem::query()->find($invoice->items->sole()->id);
    expect((float) $item->tariff_unit_price)->toEqual(250000.0)
        ->and($item->price_source)->toBe(RmeInvoicePriceSource::TARIFF_MATCHED);
});

// ─── ALL MODES: server arithmetic ───────────────────────────────────────────

it('refuses a discount above the line (a negative line was accepted before)', function () {
    rtaExpectRefused(fn () => rtaCreate(rtaVisit(), [rtaLine(test()->scaling, 100000, 150000)]), 'INVOICE_LINE_NEGATIVE');
    expect(RmeInvoice::count())->toBe(0);
});

it('refuses an amount that would overflow the money columns', function () {
    rtaExpectRefused(fn () => rtaCreate(rtaVisit(), [rtaLine(test()->scaling, '9999999999999', 0, 1000)]), 'INVOICE_TOTAL_OVERFLOW');
});

it('computes totals in cents on the server and never reads a submitted total', function () {
    $invoice = rtaCreate(rtaVisit(), [
        rtaLine(test()->scaling, '100000.10', 0, 3, ['subtotal' => 1, 'grand_total' => 1]),
        rtaLine(test()->tambal, '0.20', '0.05'),
    ]);

    expect((string) $invoice->subtotal)->toBe('300000.50')
        ->and((string) $invoice->discount_total)->toBe('0.05')
        ->and((string) $invoice->grand_total)->toBe('300000.45');
});

it('bills the visit\'s branch, never a branch_id in the payload', function () {
    $invoice = rtaCreate(rtaVisit(), [rtaLine(test()->scaling, 250000, 0, 1, ['branch_id' => test()->otherBranch->id])]);

    expect((int) $invoice->branch_id)->toBe((int) test()->branch->id)
        ->and($invoice->items->sole()->price_source)->toBe(RmeInvoicePriceSource::TARIFF_MATCHED);
});

// ─── STRICT: the tariff is the price ────────────────────────────────────────

it('accepts a strict line at exactly the tariff and records TARIFF_MATCHED in STRICT mode', function () {
    rtaStrict(['RT01']);
    $invoice = rtaCreate(rtaVisit(), [rtaLine(test()->scaling, 250000), rtaLine(test()->tambal, '300000.00')]);

    expect($invoice->items->pluck('pricing_mode')->unique()->all())->toBe([TariffPricingMode::STRICT_ENFORCED])
        ->and($invoice->items->pluck('price_source')->unique()->all())->toBe([RmeInvoicePriceSource::TARIFF_MATCHED])
        ->and((float) $invoice->grand_total)->toEqual(550000.0);
});

it('refuses every price a strict branch tariff does not authorize', function (Closure $line, string $code) {
    rtaStrict(['RT01']);
    rtaExpectRefused(fn () => rtaCreate(rtaVisit(), [$line()]), $code);
    expect(RmeInvoice::count())->toBe(0);
})->with([
    'client price tampering' => [fn () => rtaLine(test()->scaling, 1000), 'TARIFF_PRICE_MISMATCH'],
    'a higher price' => [fn () => rtaLine(test()->scaling, 999999), 'TARIFF_PRICE_MISMATCH'],
    'an unauthorized discount' => [fn () => rtaLine(test()->scaling, 250000, 1), 'TARIFF_DISCOUNT_NOT_AUTHORIZED'],
    'a free-text line' => [fn () => rtaLine(null, 250000), 'TARIFF_FREE_TEXT_NOT_PERMITTED'],
    'a treatment without a tariff' => [fn () => rtaLine(Treatment::factory()->create(['is_active' => true]), 100000), 'TARIFF_MISSING'],
    'a zero line at a positive tariff (Rp0 is refused first)' => [fn () => rtaLine(test()->scaling, 0), 'TARIFF_RP0_NOT_PERMITTED'],
    'a fully discounted line' => [fn () => rtaLine(test()->scaling, 250000, 250000), 'TARIFF_RP0_NOT_PERMITTED'],
]);

it('refuses an inactive treatment and a zero tariff in strict mode', function () {
    rtaStrict(['RT01']);
    test()->tambal->forceFill(['is_active' => false])->save();
    rtaExpectRefused(fn () => rtaCreate(rtaVisit(), [rtaLine(test()->tambal, 300000)]), 'TARIFF_TREATMENT_INACTIVE');

    $free = Treatment::factory()->create(['is_active' => true]);
    rtaTariff(test()->branch, $free, '0.00');
    rtaExpectRefused(fn () => rtaCreate(rtaVisit(), [rtaLine($free, 0)]), 'TARIFF_ZERO_PRICE');
});

it('audits a strict refusal after the rollback and consumes no invoice number', function () {
    rtaStrict(['RT01']);
    $visit = rtaVisit();

    rtaExpectRefused(fn () => rtaCreate($visit, [rtaLine(test()->scaling, 1000)]), 'TARIFF_PRICE_MISMATCH');

    $audit = AuditLog::query()->where('action', RmeFinancialAuditEvent::ACTION_INVOICE_PRICING_REFUSED)->sole();
    expect((int) $audit->entity_id)->toBe((int) $visit->id)
        ->and($audit->new_values)->toMatchArray(['reason' => 'TARIFF_PRICE_MISMATCH', 'line_no' => 1, 'treatment_id' => (int) test()->scaling->id])
        ->and(json_encode($audit->new_values))->not->toContain('1000')
        ->and(RmeInvoice::withTrashed()->count())->toBe(0);

    // The next accepted invoice still takes the first number.
    $invoice = rtaCreate($visit, [rtaLine(test()->scaling, 250000)]);
    expect($invoice->invoice_number)->toEndWith('000001');
});

it('keeps exceptions closed for everyone, Super Admin included, while the switch is off', function () {
    rtaStrict(['RT01']);
    $superAdmin = userInRole('Super Admin');

    expect($superAdmin->can(RmeTariffExceptionPermission::OVERRIDE_PRICE))->toBeTrue();
    rtaExpectRefused(fn () => rtaCreate(rtaVisit(), [rtaLine(test()->scaling, 200000, 0, 1, ['price_exception_reason' => 'Harga promosi disetujui owner.'])], $superAdmin), 'TARIFF_PRICE_MISMATCH');
    rtaExpectRefused(fn () => rtaCreate(rtaVisit(), [rtaLine(test()->scaling, 250000, 5000, 1, ['price_exception_reason' => 'Diskon keluarga karyawan klinik.'])], $superAdmin), 'TARIFF_DISCOUNT_NOT_AUTHORIZED');
});

it('accepts an exception only with the switch on, the specific permission AND a reason — and records who and why', function () {
    rtaStrict(['RT01']);
    rtaExceptions(true);
    $authorizer = userWith(['manage_rme_billing', 'view_clinic_visits', RmeTariffExceptionPermission::OVERRIDE_PRICE, RmeTariffExceptionPermission::AUTHORIZE_DISCOUNT]);

    // without the permission
    rtaExpectRefused(fn () => rtaCreate(rtaVisit(), [rtaLine(test()->scaling, 200000, 0, 1, ['price_exception_reason' => 'Harga promosi disetujui owner.'])]), 'TARIFF_PRICE_MISMATCH');
    // with the permission but no reason
    rtaExpectRefused(fn () => rtaCreate(rtaVisit(), [rtaLine(test()->scaling, 200000)], $authorizer), 'TARIFF_EXCEPTION_REASON_REQUIRED');

    $invoice = rtaCreate(rtaVisit(), [rtaLine(test()->scaling, 200000, 10000, 1, ['price_exception_reason' => 'Harga promosi disetujui owner.'])], $authorizer);
    $item = $invoice->items->sole();
    $audit = AuditLog::query()->where('action', RmeFinancialAuditEvent::ACTION_INVOICE_CREATED)->sole();

    expect($item->price_source)->toBe(RmeInvoicePriceSource::TARIFF_EXCEPTION_AUTHORIZED)
        ->and((int) $item->price_exception_by)->toBe((int) $authorizer->id)
        ->and($item->price_exception_reason)->toBe('Harga promosi disetujui owner.')
        ->and($audit->new_values['price_exceptions'][0])->toMatchArray(['line_no' => 1, 'unit_price_cents' => 20_000_000, 'tariff_unit_price_cents' => 25_000_000, 'reason_length' => 30])
        ->and(json_encode($audit->new_values))->not->toContain('promosi');
});

it('never lets an authorized exception produce an Rp0 line', function () {
    rtaStrict(['RT01']);
    rtaExceptions(true);
    $authorizer = userWith(['manage_rme_billing', RmeTariffExceptionPermission::OVERRIDE_PRICE]);

    rtaExpectRefused(fn () => rtaCreate(rtaVisit(), [rtaLine(test()->scaling, 0, 0, 1, ['price_exception_reason' => 'Gratis sebagai kompensasi.'])], $authorizer), 'TARIFF_RP0_NOT_PERMITTED');
});

// ─── HTTP / UI ──────────────────────────────────────────────────────────────

it('shows the branch mode and the tariff of each treatment on the billing form, without misrepresenting transitional pricing', function () {
    $visit = rtaVisit();
    $this->actingAs(userWith(['manage_rme_billing', 'view_clinic_visits']))->withoutMiddleware(EnsureRmeOnlineContext::class);

    $html = $this->get(route('rme.cashier.create', $visit))->assertOk()->getContent();

    expect($html)->toContain('data-pricing-mode="TRANSITIONAL"')
        ->and($html)->toContain('data-tariff="250000"')
        ->and($html)->toContain('data-tariff="300000"')
        ->and($html)->toContain('tidak dianggap terverifikasi tarif master');

    rtaStrict(['RT01']);
    $this->get(route('rme.cashier.create', $visit))->assertOk()->assertSee('data-pricing-mode="STRICT_ENFORCED"', false);
});

it('labels the provenance of every invoice line and never shows a legacy line as master tariff', function () {
    $invoice = rtaCreate(rtaVisit(), [rtaLine(test()->scaling, 200000), rtaLine(test()->tambal, 300000)]);
    $legacy = RmeInvoiceItem::query()->create([
        'rme_invoice_id' => $invoice->id, 'description' => 'Baris lama', 'qty' => 1,
        'unit_price' => 1000, 'discount' => 0, 'subtotal' => 1000,
    ]);
    $this->actingAs(userWith(['manage_rme_billing', 'view_clinic_visits']))->withoutMiddleware(EnsureRmeOnlineContext::class);

    $html = $this->get(route('rme.cashier.show', [$invoice->clinic_visit_id, $invoice]))->assertOk()->getContent();

    expect($html)->toContain('data-price-source="TRANSITIONAL_TARIFF_DEVIATION"')
        ->and($html)->toContain('data-price-source="TARIFF_MATCHED"')
        ->and($html)->toContain('data-price-source="NOT_RECORDED"')
        ->and($html)->toContain('Belum tercatat (sebelum otoritas tarif)')
        ->and($html)->toContain('Tarif master: Rp 250.000');
    expect($legacy->fresh()->hasPriceProvenance())->toBeFalse();
});

it('returns the strict refusal as a form error over HTTP, not a 500', function () {
    rtaStrict(['RT01']);
    $visit = rtaVisit();
    $this->actingAs(userWith(['manage_rme_billing', 'view_clinic_visits']))->withoutMiddleware(EnsureRmeOnlineContext::class);

    $this->post(route('rme.cashier.store', $visit), ['items' => [rtaLine(test()->scaling, 1)]])
        ->assertSessionHasErrors('items');

    expect(RmeInvoice::count())->toBe(0);
});

// ─── Security review findings (this sprint) ────────────────────────────────

it('refuses an amount that a cents conversion would wrap — exponent notation, 3 decimals, out of range — in every mode', function (mixed $amount, string $field) {
    $line = $field === 'unit_price'
        ? rtaLine(test()->scaling, $amount)
        : rtaLine(test()->scaling, 250000, $amount);

    rtaExpectRefused(fn () => rtaCreate(rtaVisit(), [$line]), 'INVOICE_AMOUNT_INVALID');
    expect(RmeInvoice::count())->toBe(0);
})->with([
    'exponent price' => ['1.8446744073709552e17', 'unit_price'],
    'exponent discount' => ['1e3', 'discount'],
    'three decimals' => ['250000.005', 'unit_price'],
    'past the column' => ['99999999999999', 'unit_price'],
    'float past the column' => [1.0e14, 'unit_price'],
    'non-finite float' => [INF, 'unit_price'],
]);

it('refuses the same malformed amounts at the form boundary, never as a 500', function () {
    $visit = rtaVisit();
    $this->actingAs(userWith(['manage_rme_billing', 'view_clinic_visits']))->withoutMiddleware(EnsureRmeOnlineContext::class);

    $this->post(route('rme.cashier.store', $visit), ['items' => [rtaLine(test()->scaling, '1.8446744073709552e17')]])
        ->assertSessionHasErrors('items.0.unit_price');
    $this->post(route('rme.cashier.store', $visit), ['items' => [rtaLine(test()->scaling, '250000', '1.234')]])
        ->assertSessionHasErrors('items.0.discount');

    expect(RmeInvoice::count())->toBe(0);
});

it('still accepts an amount with cents, so a tariff with cents can be billed exactly', function () {
    $tariff = rtaTariff(test()->otherBranch, test()->scaling, '150000.50');
    rtaStrict(['RT02']);

    $invoice = rtaCreate(rtaVisit(test()->otherBranch), [rtaLine(test()->scaling, '150000.50')]);

    expect((string) $invoice->grand_total)->toBe('150000.50')
        ->and((int) $invoice->items->first()->tariff_id)->toBe((int) $tariff->id);

    $this->actingAs(userWith(['manage_rme_billing', 'view_clinic_visits']))->withoutMiddleware(EnsureRmeOnlineContext::class);
    expect($this->get(route('rme.cashier.create', rtaVisit()))->getContent())->toContain('step="0.01"')
        ->and($this->get(route('rme.cashier.create', rtaVisit()))->getContent())->not->toContain('step="1"');
});

it('renders treatment options from an HTML template, never inside the JavaScript template literal', function () {
    test()->scaling->forceFill(['name' => 'Scaling `${document.cookie}`'])->save();
    $this->actingAs(userWith(['manage_rme_billing', 'view_clinic_visits']))->withoutMiddleware(EnsureRmeOnlineContext::class);

    $html = $this->get(route('rme.cashier.create', rtaVisit()))->assertOk()->getContent();
    preg_match_all('#<script\b[^>]*>(.*?)</script>#s', $html, $scripts);

    expect($html)->toContain('<template id="treatment-options-template">')
        ->and(implode("\n", $scripts[1]))->not->toContain('document.cookie')
        ->and(implode("\n", $scripts[1]))->not->toContain('data-tariff=');
});

it('refuses an empty line list in strict mode at the pricing service itself, not only at the invoice service', function () {
    // Every strict line must be > Rp0, so a non-empty strict document can never
    // total Rp0. The total guard is defence in depth for a direct caller of the
    // pricing service with no lines; the invoice service refuses an empty list
    // earlier with its own message (mutation testing, this sprint).
    rtaStrict(['RT01']);

    expect(fn () => app(RmeInvoicePricingService::class)->price((int) test()->branch->id, [], test()->cashier, clinicalToday()->toDateString()))
        ->toThrow(RmeInvoicePricingRefusal::class, 'Rp0');

    rtaExpectRefused(fn () => rtaCreate(rtaVisit(), []), 'Minimal satu item');
    expect(RmeInvoice::count())->toBe(0);
});
