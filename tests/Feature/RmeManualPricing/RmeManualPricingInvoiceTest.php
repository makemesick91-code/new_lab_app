<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| REVISION-RME-MANUAL-PRICING-AUTHORITY-1 — the ORIGINAL invoice under the
| revised owner decision D21 (manual cashier pricing).
|--------------------------------------------------------------------------
|
| The cashier types the price of every line. There is no mandatory tariff: a
| branch with no tariff bills normally, a tariff that differs from the typed
| price changes nothing, and a later tariff edit rewrites nothing. The SERVER
| still owns the arithmetic: integer cents, a discount never above its line,
| overflow refused, malformed money refused (never silently zero), totals
| recomputed, and a named treatment must exist and be active. Every new line
| records that its price is a MANUAL cashier entry — never a tariff label.
*/

use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use App\Modules\RmeInvoice\Services\RmeInvoicePricingService;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmeInvoicePriceSource;
use App\Modules\RmeInvoice\Support\RmeInvoicePricingRefusal;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Modules\Tariff\Models\Tariff;
use App\Modules\Tariff\Services\BranchTariffAuthorityService;
use App\Modules\Tariff\Support\TariffPricingMode;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../RmeTariffAuthority/helpers.php';

beforeEach(fn () => rtaSetup());

/** Expect a refusal on `items` carrying the stable code, and that nothing was written. */
function rmpExpectRefused(callable $work, string $code): void
{
    $before = RmeInvoice::count();

    try {
        $work();
    } catch (ValidationException $e) {
        expect(implode(' ', $e->errors()['items'] ?? []))->toContain($code)
            ->and(RmeInvoice::count())->toBe($before);

        return;
    }

    test()->fail('The invoice was accepted; it should have been refused with '.$code.'.');
}

// ─── Manual price is the authority ─────────────────────────────────────────

it('bills a manual price in a branch that has no tariff at all, recording MANUAL_CASHIER_ENTRY', function () {
    $invoice = rtaCreate(rtaVisit(test()->otherBranch), [rtaLine(test()->scaling, 175000)]);
    $item = $invoice->items->sole();

    expect((float) $item->unit_price)->toEqual(175000.0)
        ->and($item->pricing_mode)->toBe(TariffPricingMode::MANUAL)
        ->and($item->price_source)->toBe(RmeInvoicePriceSource::MANUAL_CASHIER_ENTRY)
        ->and($item->tariff_id)->toBeNull()
        ->and($item->tariff_unit_price)->toBeNull()
        ->and((float) $invoice->grand_total)->toEqual(175000.0);
});

it('keeps a manual price that differs from the branch tariff, without labelling it as tariff', function () {
    // RT01 carries a Scaling tariff of 250.000; the cashier charges 175.000.
    $invoice = rtaCreate(rtaVisit(), [rtaLine(test()->scaling, 175000)]);
    $item = $invoice->items->sole();

    expect((float) $item->unit_price)->toEqual(175000.0)
        ->and($item->price_source)->toBe(RmeInvoicePriceSource::MANUAL_CASHIER_ENTRY)
        ->and($item->price_source)->not->toBeIn(RmeInvoicePriceSource::TARIFF_VERIFIED)
        ->and($item->tariff_id)->toBeNull()
        ->and($item->tariff_unit_price)->toBeNull();
});

it('records a manual price equal to the tariff as a MANUAL entry too — coincidence is not tariff authority', function () {
    $invoice = rtaCreate(rtaVisit(), [rtaLine(test()->scaling, 250000)]);

    expect($invoice->items->sole()->price_source)->toBe(RmeInvoicePriceSource::MANUAL_CASHIER_ENTRY);
});

it('accepts different prices for the same treatment across patients in one branch', function () {
    $first = rtaCreate(rtaVisit(), [rtaLine(test()->scaling, 150000)]);
    test()->patient = Patient::factory()->create(['branch_id' => test()->branch->id]);
    $second = rtaCreate(rtaVisit(), [rtaLine(test()->scaling, 325000)]);

    expect((float) $first->grand_total)->toEqual(150000.0)
        ->and((float) $second->grand_total)->toEqual(325000.0)
        ->and($first->items->sole()->treatment_id)->toBe($second->items->sole()->treatment_id);
});

it('accepts different prices for the same treatment across branches', function () {
    $tlk = rtaCreate(rtaVisit(test()->branch), [rtaLine(test()->scaling, 200000)]);
    $other = rtaCreate(rtaVisit(test()->otherBranch), [rtaLine(test()->scaling, 120000)]);

    expect((int) $tlk->branch_id)->toBe((int) test()->branch->id)
        ->and((int) $other->branch_id)->toBe((int) test()->otherBranch->id)
        ->and((float) $tlk->grand_total)->toEqual(200000.0)
        ->and((float) $other->grand_total)->toEqual(120000.0);
});

it('records a free-text line (a live production workflow) as MANUAL_FREE_TEXT', function () {
    $invoice = rtaCreate(rtaVisit(), [rtaLine(null, 99000)]);
    $item = $invoice->items->sole();

    expect($item->treatment_id)->toBeNull()
        ->and($item->price_source)->toBe(RmeInvoicePriceSource::MANUAL_FREE_TEXT)
        ->and($item->pricing_mode)->toBe(TariffPricingMode::MANUAL);
});

it('never rewrites an issued invoice when a tariff is later edited, deactivated or deleted', function () {
    $invoice = rtaCreate(rtaVisit(), [rtaLine(test()->scaling, 180000)]);
    $snapshot = RmeInvoiceItem::query()->whereKey($invoice->items->sole()->id)->first()->getAttributes();

    test()->scalingTariff->update(['price' => '999000.00']);
    test()->scalingTariff->update(['is_active' => false]);
    test()->scalingTariff->delete();

    $after = RmeInvoiceItem::query()->whereKey($invoice->items->sole()->id)->first()->getAttributes();
    expect($after)->toEqual($snapshot)
        ->and((float) $invoice->fresh()->grand_total)->toEqual(180000.0);
});

it('leaves historical lines and their NULL provenance untouched when new invoices are created', function () {
    $legacy = rtaCreate(rtaVisit(test()->otherBranch), [rtaLine(test()->tambal, 100000)]);
    RmeInvoiceItem::query()->whereKey($legacy->items->sole()->id)->update(['pricing_mode' => null, 'price_source' => null]);
    $legacyRow = RmeInvoiceItem::query()->find($legacy->items->sole()->id)->getAttributes();

    test()->patient = Patient::factory()->create(['branch_id' => test()->branch->id]);
    rtaCreate(rtaVisit(), [rtaLine(test()->scaling, 175000)]);

    expect(RmeInvoiceItem::query()->find($legacy->items->sole()->id)->getAttributes())->toEqual($legacyRow)
        ->and(RmeInvoicePriceSource::label(null))->toContain('Belum tercatat');
});

// ─── Server arithmetic ──────────────────────────────────────────────────────

it('computes totals on the server and ignores submitted totals', function () {
    $invoice = rtaCreate(rtaVisit(), [
        rtaLine(test()->scaling, '150000', '10000', 2, ['subtotal' => '1', 'grand_total' => '1']),
        rtaLine(test()->tambal, '100000.10', '0', 3),
    ]);

    // 2 × 150.000 − 10.000 + 3 × 100.000,10 = 590.000,30 exactly (integer cents).
    expect((string) $invoice->subtotal)->toBe('600000.30')
        ->and((string) $invoice->discount_total)->toBe('10000.00')
        ->and((string) $invoice->grand_total)->toBe('590000.30');
});

it('refuses malformed money with a stable code and never converts it to zero', function (mixed $price) {
    rmpExpectRefused(fn () => rtaCreate(rtaVisit(), [rtaLine(test()->scaling, $price)]), 'INVOICE_AMOUNT_INVALID');
})->with([
    'empty string' => [''],
    'null' => [null],
    'whitespace only' => ['   '],
    'exponent notation' => ['1e5'],
    'thousands comma' => ['50,000'],
    'thousands dot' => ['1.500.000'],
    'currency symbol' => ['Rp 50000'],
    'three decimals' => ['12.345'],
    'letters' => ['abc'],
    'not finite' => [INF],
]);

it('refuses a negative price and a negative discount', function () {
    rmpExpectRefused(fn () => rtaCreate(rtaVisit(), [rtaLine(test()->scaling, '-1')]), 'tidak boleh negatif');
    rmpExpectRefused(fn () => rtaCreate(rtaVisit(), [rtaLine(test()->scaling, '100000', '-1')]), 'tidak boleh negatif');
});

it('refuses a discount above its line, so no line and no invoice total can be negative', function () {
    rmpExpectRefused(fn () => rtaCreate(rtaVisit(), [rtaLine(test()->scaling, 100000, 100001)]), 'INVOICE_LINE_NEGATIVE');
});

it('refuses an amount that would overflow the money columns', function () {
    rmpExpectRefused(fn () => rtaCreate(rtaVisit(), [rtaLine(test()->scaling, '9999999999999.99', 0, 2)]), 'INVOICE_TOTAL_OVERFLOW');
});

it('refuses an oversized line BEFORE multiplying, so the product never becomes a float', function () {
    // qty × price here is ~2.1e24 cents — far past PHP_INT_MAX, where integer
    // multiplication silently turns into a float. The refusal must come from the
    // per-line guard (it names the item), not from the running total afterwards.
    rmpExpectRefused(
        fn () => rtaCreate(rtaVisit(), [rtaLine(test()->scaling, '9999999999999.99', 0, RmeInvoicePricingService::MAX_QTY)]),
        '[INVOICE_TOTAL_OVERFLOW] Nilai item "Scaling"',
    );
});

// ─── Treatment validity (server-side, never the form) ─────────────────────

it('refuses an inactive treatment and audits the refusal with ids and code only', function () {
    $inactive = Treatment::factory()->create(['is_active' => false, 'requires_lab' => false, 'name' => 'Nonaktif']);

    rmpExpectRefused(fn () => rtaCreate(rtaVisit(), [rtaLine($inactive, 100000)]), RmeInvoicePricingRefusal::TREATMENT_INVALID);

    $audit = AuditLog::query()->where('action', RmeFinancialAuditEvent::ACTION_INVOICE_PRICING_REFUSED)->sole();
    expect($audit->new_values['reason'])->toBe(RmeInvoicePricingRefusal::TREATMENT_INVALID)
        ->and((int) $audit->new_values['treatment_id'])->toBe((int) $inactive->id)
        ->and(json_encode($audit->new_values))->not->toContain('100000');
});

it('refuses a forged treatment id and a soft-deleted treatment', function () {
    rmpExpectRefused(fn () => rtaCreate(rtaVisit(), [rtaLine(null, 100000, 0, 1, ['treatment_id' => 987654])]), RmeInvoicePricingRefusal::TREATMENT_INVALID);

    $deleted = Treatment::factory()->create(['is_active' => true, 'requires_lab' => false, 'name' => 'Dihapus']);
    $deleted->delete();
    rmpExpectRefused(fn () => rtaCreate(rtaVisit(), [rtaLine($deleted, 100000)]), RmeInvoicePricingRefusal::TREATMENT_INVALID);
});

// ─── Rp0: existing behaviour kept, no new privilege ────────────────────────

it('keeps the existing Rp0 behaviour of an original invoice and reports the zero line in the audit', function () {
    $consultation = Treatment::factory()->create(['is_active' => true, 'requires_lab' => false, 'name' => 'Konsultasi Dokter', 'code' => 'TRT-RMPC']);

    $invoice = rtaCreate(rtaVisit(test()->otherBranch), [rtaLine($consultation, 0)]);

    $audit = AuditLog::query()->where('action', RmeFinancialAuditEvent::ACTION_INVOICE_CREATED)->sole();
    expect((float) $invoice->grand_total)->toEqual(0.0)
        ->and($invoice->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and($audit->new_values['pricing_mode'])->toBe(TariffPricingMode::MANUAL)
        ->and($audit->new_values['zero_value_lines'])->toBe(1);
});

// ─── Audit ─────────────────────────────────────────────────────────────────

it('audits the created invoice with the manual pricing summary and no patient data', function () {
    test()->patient->forceFill(['name' => 'Pasien Harga Manual', 'medical_record_number' => 'DG-RT01-2026-9001'])->save();
    rtaCreate(rtaVisit(), [rtaLine(test()->scaling, 175000), rtaLine(null, 25000)]);

    $values = AuditLog::query()->where('action', RmeFinancialAuditEvent::ACTION_INVOICE_CREATED)->sole()->new_values;
    expect($values['pricing_mode'])->toBe(TariffPricingMode::MANUAL)
        ->and($values['lines_manual_cashier_entry'])->toBe(1)
        ->and($values['lines_manual_free_text'])->toBe(1)
        ->and($values['price_exceptions'])->toBe([])
        ->and(json_encode($values))->not->toContain((string) test()->patient->name)
        ->and(json_encode($values))->not->toContain((string) test()->patient->medical_record_number);
});

// ─── Strict tariff pricing is superseded and cannot be switched on by a host ──

it('reports every branch as MANUAL and cannot be put into strict tariff pricing from the environment', function () {
    $previous = getenv('RME_TARIFF_STRICT_BRANCH_CODES');
    putenv('RME_TARIFF_STRICT_BRANCH_CODES=RT01,RT02');
    $_ENV['RME_TARIFF_STRICT_BRANCH_CODES'] = $_SERVER['RME_TARIFF_STRICT_BRANCH_CODES'] = 'RT01,RT02';

    try {
        $config = require config_path('rme_tariff_authority.php');
    } finally {
        $previous === false ? putenv('RME_TARIFF_STRICT_BRANCH_CODES') : putenv('RME_TARIFF_STRICT_BRANCH_CODES='.$previous);
        unset($_ENV['RME_TARIFF_STRICT_BRANCH_CODES'], $_SERVER['RME_TARIFF_STRICT_BRANCH_CODES']);
    }

    expect($config['strict_branch_codes'])->toBe([])
        ->and($config['price_exceptions_enabled'])->toBeFalse()
        ->and(file_get_contents(config_path('rme_tariff_authority.php')))->not->toContain("env('RME_TARIFF_STRICT_BRANCH_CODES'");

    $authority = app(BranchTariffAuthorityService::class);
    expect($authority->modeFor((int) test()->branch->id))->toBe(TariffPricingMode::MANUAL)
        ->and($authority->modeFor((int) test()->otherBranch->id))->toBe(TariffPricingMode::MANUAL)
        ->and($authority->modeFor(987654))->toBe(TariffPricingMode::MANUAL);
});

it('prices without reading the tariff master at all (no tariff lookup in the authoritative path)', function () {
    $visit = rtaVisit();
    DB::enableQueryLog();
    DB::flushQueryLog();

    app(RmeInvoicePricingService::class)->price((int) $visit->branch_id, [rtaLine(test()->scaling, 175000), rtaLine(null, 1000)], test()->cashier, '2026-10-20');

    $tariffQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'mst_tariffs'));
    expect($tariffQueries)->toHaveCount(0);
});

// ─── HTTP: the cashier form ─────────────────────────────────────────────────

it('renders the cashier form without tariff autofill, tariff hints or a transitional banner', function () {
    $this->actingAs(test()->cashier)->withoutMiddleware(EnsureRmeOnlineContext::class);

    $html = $this->get(route('rme.cashier.create', rtaVisit()))->assertOk()->getContent();

    expect($html)->not->toContain('data-tariff=')
        ->and($html)->not->toContain('Tarif master cabang')
        ->and($html)->not->toContain('Mode tarif transisi')
        ->and($html)->not->toContain('priceInput.value = tariff')
        ->and($html)->toContain('data-pricing-authority="MANUAL_CASHIER_ENTRY"');
});

it('renders the form for a branch without any tariff and bills it end to end through HTTP', function () {
    Tariff::query()->delete();
    $visit = rtaVisit(test()->otherBranch);
    $this->actingAs(test()->cashier)->withoutMiddleware(EnsureRmeOnlineContext::class);

    $this->get(route('rme.cashier.create', $visit))->assertOk();
    $this->post(route('rme.cashier.store', $visit), ['items' => [rtaLine(test()->scaling, '210000')]])
        ->assertSessionHasNoErrors()->assertRedirect();

    $invoice = RmeInvoice::query()->sole();
    expect((float) $invoice->grand_total)->toEqual(210000.0)
        ->and($invoice->items->sole()->price_source)->toBe(RmeInvoicePriceSource::MANUAL_CASHIER_ENTRY);
});

it('keeps the cashier input on a validation error', function () {
    $visit = rtaVisit();
    $this->actingAs(test()->cashier)->withoutMiddleware(EnsureRmeOnlineContext::class);

    $this->from(route('rme.cashier.create', $visit))
        ->post(route('rme.cashier.store', $visit), ['items' => [rtaLine(test()->scaling, '100000', '150000')]])
        ->assertSessionHasErrors('items')->assertRedirect(route('rme.cashier.create', $visit));

    $html = $this->get(route('rme.cashier.create', $visit))->getContent();
    expect($html)->toContain('value="100000"')->and(RmeInvoice::count())->toBe(0);
});

// ─── An issued invoice cannot be edited outside the correction workflow ───────

it('exposes no route that edits an RME invoice or its items outside the correction workflow', function () {
    $mutating = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => array_diff($route->methods(), ['GET', 'HEAD']) !== [])
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'rme.cashier.') || str_starts_with((string) $route->getName(), 'rme.invoice-corrections.'))
        ->map(fn ($route) => $route->getName())
        ->sort()->values()->all();

    expect($mutating)->toBe([
        'rme.cashier.payment.store',
        'rme.cashier.receivables.follow-ups.store',
        'rme.cashier.store',
        // FEATURE-RME-RP0-CONTROL-CONSULTATION-1 (rule 217) — a STATUS transition of a
        // legitimate Rp0 invoice to NO_CHARGE, like payment.store; it never touches an
        // item or an amount (the item/total mutators test below still holds).
        'rme.cashier.zero-value-closure.store',
        'rme.invoice-corrections.apply',
        'rme.invoice-corrections.approve',
        'rme.invoice-corrections.cancel',
        'rme.invoice-corrections.reject',
        'rme.invoice-corrections.store',
    ]);
});

it('changes an existing invoice line or its totals only from the correction service', function () {
    $callers = collect(File::allFiles(app_path()))
        ->filter(fn ($file) => str_contains($file->getContents(), 'RmeInvoiceRepositoryInterface'))
        ->filter(fn ($file) => preg_match('/->(updateItem|deleteItem|createItem|applyCorrectionTotals)\(/', $file->getContents()) === 1)
        ->map(fn ($file) => str_replace(app_path().'/', '', $file->getPathname()))
        ->values()->all();

    expect($callers)->toBe(['Modules/RmeInvoice/Services/RmeInvoiceCorrectionService.php']);
});
