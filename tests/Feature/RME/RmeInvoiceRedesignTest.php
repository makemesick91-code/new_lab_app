<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\RmeInvoice\Controllers\RmePaymentController;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeInvoice\Support\RmeInvoiceDocument;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| REVISION-RME-VISIT-DETAIL-INVOICE-REGISTRATION-UX-1 — Revision B
|--------------------------------------------------------------------------
|
| The RME payment invoice follows the clinic's printed "INVOICE" form
| (owner-supplied reference). These tests pin that it is DYNAMIC (nothing from
| the sample is hard-coded), FINANCIALLY FAITHFUL (every amount is a stored
| canonical value and the document reconciles itself against them), TRUTHFUL
| about partial payment, and still one landscape page for a real-world invoice
| — proven against real dompdf output read back with poppler.
*/

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake('local');

    $this->branch = Branch::factory()->create([
        'code' => 'INV1',
        'name' => 'Cabang Uji Invoice',
        'address' => 'Jl. Sintetis Uji No. 7, Kota Uji',
        'phone' => '0800-1234-5678',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);

    $this->cashier = userWith(['view_clinic_visits', 'manage_rme_billing']);
    $this->cashier->forceFill(['name' => 'Kasir Sintetis Uji'])->save();
    $this->method = PaymentMethod::factory()->create(['name' => 'Debit Sintetis', 'is_active' => true]);
});

function invdVisit(?Branch $branch = null): ClinicVisit
{
    $branch ??= test()->branch;
    $visit = ClinicVisit::factory()->cashierPending()->create(['branch_id' => $branch->id]);

    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);
    rmeSignedConsentFor($visit);

    $visit->patient->forceFill([
        'name' => 'Pasien Uji Invoice',
        'phone' => '0812-0000-1111',
    ])->save();
    $visit->doctor?->forceFill(['name' => 'drg. Dokter Uji Invoice'])->save();

    return $visit->refresh();
}

/**
 * @param  list<array{description: string, qty: int, unit_price: int|float, discount?: int|float}>  $items
 * @return array{0: ClinicVisit, 1: RmeInvoice}
 */
function invdInvoice(array $items, ?float $pay = null, ?Branch $branch = null, ?string $paidAt = null): array
{
    $visit = invdVisit($branch);

    $payload = array_map(fn (array $item) => [
        'treatment_id' => Treatment::factory()->create(['is_active' => true])->id,
    ] + $item, $items);

    $invoice = app(RmeInvoiceService::class)->create($visit, test()->cashier, ['items' => $payload]);

    app(RmePaymentService::class)->pay($invoice, test()->cashier, rmeFreshPaymentForm($invoice, [
        'amount' => $pay ?? (float) $invoice->grand_total,
        'paid_at' => $paidAt ?? now()->toDateTimeString(),
        'payment_method_id' => test()->method->id,
    ]));

    return [$visit->refresh(), $invoice->refresh()];
}

/** The exact data both receipt routes assemble (one private source). */
function invdData(ClinicVisit $visit, RmeInvoice $invoice): array
{
    $method = new ReflectionMethod(RmePaymentController::class, 'resolveReceiptData');

    return $method->invoke(app(RmePaymentController::class), $visit, $invoice);
}

function invdPdfBytes(ClinicVisit $visit, RmeInvoice $invoice): string
{
    $response = test()->actingAs(test()->cashier)->get(route('rme.cashier.receipt.pdf', [$visit, $invoice]));
    $response->assertOk();

    return $response->getContent();
}

/** Render the document for ANY invoice state (the routes only serve PAID). */
function invdRenderedHtml(ClinicVisit $visit, RmeInvoice $invoice): string
{
    return view('rme.cashier.receipt.pdf', invdData($visit, $invoice))->render();
}

function invdItems(int $count): array
{
    $items = [];
    for ($i = 1; $i <= $count; $i++) {
        $items[] = ['description' => 'Tindakan Uji Nomor '.$i, 'qty' => 1, 'unit_price' => 50000 * $i];
    }

    return $items;
}

/*
|--------------------------------------------------------------------------
| Branding and dynamic identity
|--------------------------------------------------------------------------
*/

it('carries the Daengtisia branding and the reference layout headings', function () {
    [$visit, $invoice] = invdInvoice([['description' => 'Konsultasi Dokter Gigi', 'qty' => 1, 'unit_price' => 75000]]);

    $html = $this->actingAs($this->cashier)
        ->get(route('rme.cashier.receipt.show', [$visit, $invoice]))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('daengtisia-logo-wordmark.jpg')
        ->toContain('>INVOICE<')
        ->toContain('Ditujukan Kepada')
        ->toContain('Jenis Perawatan')
        ->toContain('Jenis Pembayaran')
        ->toContain('Total Biaya')
        ->toContain('Sisa Pembayaran')
        ->toContain('Dokter Pemeriksa')
        // Print still uses the one shared document, on a landscape page.
        ->toContain('size: A4 landscape');
});

it('embeds the logo in the PDF without any network fetch', function () {
    [$visit, $invoice] = invdInvoice([['description' => 'Konsultasi', 'qty' => 1, 'unit_price' => 75000]]);

    $html = invdRenderedHtml($visit, $invoice);

    expect($html)->toContain('src="data:image/jpeg;base64,')
        ->not->toContain('http://')
        ->not->toContain('https://');
});

it('prints the real invoice number and never the sample form number', function () {
    [$visit, $invoice] = invdInvoice([['description' => 'Konsultasi', 'qty' => 1, 'unit_price' => 75000]]);

    $html = invdRenderedHtml($visit, $invoice);

    expect($html)->toContain(e($invoice->invoice_number))
        ->not->toContain('07997');
});

it('prints the INVOICE branch identity, never the reference branch', function () {
    [$visit, $invoice] = invdInvoice([['description' => 'Konsultasi', 'qty' => 1, 'unit_price' => 75000]]);

    $html = invdRenderedHtml($visit, $invoice);

    expect($html)->toContain('Cabang Uji Invoice')
        ->toContain('Jl. Sintetis Uji No. 7, Kota Uji')
        ->toContain('Telp/WA: 0800-1234-5678')
        ->not->toContain('Landak')
        ->not->toContain('Andi Djemma')
        ->not->toContain('0853-4188-1832');

    // The template itself hard-codes no branch identity.
    $source = file_get_contents(resource_path('views/rme/cashier/receipt/partials/invoice-document.blade.php'));
    expect($source)->not->toContain('Landak')->not->toContain('Andi Djemma')->not->toContain('0853');
});

it('uses the transaction branch even when another branch prints it later', function () {
    $other = Branch::factory()->create(['code' => 'INV2', 'name' => 'Cabang Lain Uji', 'address' => 'Alamat Lain', 'phone' => '0800-9999-9999', 'is_active' => true, 'is_rme_enabled' => true]);
    [$visit, $invoice] = invdInvoice([['description' => 'Konsultasi', 'qty' => 1, 'unit_price' => 75000]], null, $other);

    $html = invdRenderedHtml($visit, $invoice);

    expect($html)->toContain('Cabang Lain Uji')
        ->toContain('0800-9999-9999')
        ->not->toContain('Cabang Uji Invoice');
});

it('prints no address or phone line when the branch has none, instead of a guess', function () {
    $bare = Branch::factory()->create(['code' => 'INV3', 'name' => 'Cabang Tanpa Alamat', 'address' => null, 'phone' => null, 'is_active' => true, 'is_rme_enabled' => true]);
    [$visit, $invoice] = invdInvoice([['description' => 'Konsultasi', 'qty' => 1, 'unit_price' => 75000]], null, $bare);

    $html = invdRenderedHtml($visit, $invoice);

    expect($html)->toContain('Cabang Tanpa Alamat')
        ->not->toContain('Telp/WA:');
});

it('prints the patient, doctor, payment date, method and cashier', function () {
    [$visit, $invoice] = invdInvoice(
        [['description' => 'Konsultasi', 'qty' => 1, 'unit_price' => 75000]],
        null,
        null,
        '2026-03-04 10:15:00',
    );

    $html = invdRenderedHtml($visit, $invoice);

    expect($html)->toContain(e($visit->patient->medical_record_number))
        ->toContain('Pasien Uji Invoice')
        ->toContain('0812-0000-1111')
        ->toContain('drg. Dokter Uji Invoice')
        ->toContain('04/03/2026')
        ->toContain('Debit Sintetis')
        ->toContain('Kasir Sintetis Uji')
        ->toContain(e($visit->visit_number));
});

it('escapes patient-controlled text instead of rendering it as markup', function () {
    [$visit, $invoice] = invdInvoice([['description' => '<b>Tindakan</b><script>alert(1)</script>', 'qty' => 1, 'unit_price' => 75000]]);
    $visit->patient->forceFill(['name' => '<img src=x onerror=alert(2)>'])->save();

    $html = $this->actingAs($this->cashier)
        ->get(route('rme.cashier.receipt.show', [$visit->refresh(), $invoice]))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->not->toContain('<img src=x onerror=alert(2)>')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
});

/*
|--------------------------------------------------------------------------
| Financial integrity
|--------------------------------------------------------------------------
*/

it('reads every amount from the stored canonical fields', function () {
    [$visit, $invoice] = invdInvoice([
        ['description' => 'Scaling', 'qty' => 1, 'unit_price' => 350000, 'discount' => 35000],
        ['description' => 'Tambalan Komposit', 'qty' => 2, 'unit_price' => 400000, 'discount' => 80000],
        ['description' => 'Konsultasi', 'qty' => 1, 'unit_price' => 75000],
    ]);

    $doc = RmeInvoiceDocument::fromInvoice($invoice, collect());

    expect($doc->reconciles())->toBeTrue()
        ->and($doc->grossCents)->toBe(RmeInvoiceDocument::toCents($invoice->subtotal))
        ->and($doc->discountCents)->toBe(RmeInvoiceDocument::toCents($invoice->discount_total))
        ->and($doc->totalCents)->toBe(RmeInvoiceDocument::toCents($invoice->grand_total))
        ->and($doc->paidCents)->toBe(RmeInvoiceDocument::toCents($invoice->paidAmount()))
        ->and($doc->remainingCents)->toBe(0)
        // 350.000 + 800.000 + 75.000 gross; 115.000 discount; 1.110.000 due.
        ->and($doc->grossCents)->toBe(122500000)
        ->and($doc->discountCents)->toBe(11500000)
        ->and($doc->totalCents)->toBe(111000000);

    // Line by line: Biaya is the gross line and Diskon the line discount.
    expect(array_column($doc->lines, 'gross_cents'))->toBe([35000000, 80000000, 7500000])
        ->and(array_column($doc->lines, 'discount_cents'))->toBe([3500000, 8000000, 0]);
});

it('matches the totals the cashier screen shows for the same invoice', function () {
    [$visit, $invoice] = invdInvoice([
        ['description' => 'Scaling', 'qty' => 1, 'unit_price' => 350000, 'discount' => 35000],
        ['description' => 'Konsultasi', 'qty' => 1, 'unit_price' => 75000],
    ]);

    $total = 'Rp '.number_format((float) $invoice->grand_total, 0, ',', '.');

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.show', [$visit, $invoice]))
        ->assertOk()
        ->assertSee($total);

    $html = invdRenderedHtml($visit, $invoice);

    expect($html)->toContain($total)
        ->toContain('Rp 425.000')   // Jumlah (gross)
        ->toContain('- Rp 35.000')  // Diskon
        ->toContain('Rp 390.000');  // Total Biaya == Pembayaran
});

it('shows a partial payment truthfully: remaining balance, never LUNAS', function () {
    [$visit, $invoice] = invdInvoice([
        ['description' => 'Pemasangan Kawat Gigi', 'qty' => 1, 'unit_price' => 3500000],
        ['description' => 'Konsultasi Ortodonti', 'qty' => 1, 'unit_price' => 100000],
    ], 1000000);

    expect($invoice->status)->toBe(RmeInvoice::STATUS_PARTIAL);

    $doc = RmeInvoiceDocument::fromInvoice($invoice, collect());
    expect($doc->paidCents)->toBe(100000000)
        ->and($doc->remainingCents)->toBe(260000000)
        ->and($doc->isSettled())->toBeFalse();

    $html = invdRenderedHtml($visit, $invoice);

    expect($html)->toContain('Rp 2.600.000')
        ->toContain('DIBAYAR SEBAGIAN')
        ->not->toContain('>LUNAS<');
});

it('still refuses to issue the receipt for an unsettled invoice (rule unchanged)', function () {
    [$visit, $invoice] = invdInvoice([['description' => 'Kawat Gigi', 'qty' => 1, 'unit_price' => 3500000]], 1000000);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.receipt.show', [$visit, $invoice]))
        ->assertRedirect(route('rme.cashier.show', [$visit, $invoice]));

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.receipt.pdf', [$visit, $invoice]))
        ->assertRedirect(route('rme.cashier.show', [$visit, $invoice]));
});

it('sums every payment on the invoice, dated by the settling payment', function () {
    $visit = invdVisit();
    $invoice = app(RmeInvoiceService::class)->create($visit, $this->cashier, ['items' => [[
        'treatment_id' => Treatment::factory()->create(['is_active' => true])->id,
        'description' => 'Perawatan Bertahap', 'qty' => 1, 'unit_price' => 900000,
    ]]]);
    $pay = app(RmePaymentService::class);
    $pay->pay($invoice, $this->cashier, rmeFreshPaymentForm($invoice, ['amount' => 400000, 'paid_at' => '2026-02-01 09:00:00', 'payment_method_id' => $this->method->id]));
    $pay->pay($invoice->refresh(), $this->cashier, rmeFreshPaymentForm($invoice->refresh(), ['amount' => 500000, 'paid_at' => '2026-02-20 11:00:00', 'payment_method_id' => $this->method->id]));
    $invoice->refresh();

    expect($invoice->isPaid())->toBeTrue();

    $html = invdRenderedHtml($visit->refresh(), $invoice);
    $doc = invdData($visit, $invoice)['document'];

    expect($doc->paidCents)->toBe(90000000)
        ->and($doc->remainingCents)->toBe(0)
        ->and($html)->toContain('20/02/2026')
        ->toContain('Rp 900.000')
        ->toContain('>LUNAS<');
});

it('refuses to look settled when the stored totals do not reconcile', function () {
    [$visit, $invoice] = invdInvoice([['description' => 'Konsultasi', 'qty' => 1, 'unit_price' => 75000]]);

    // Simulate a corrupted stored total without going through any service.
    DB::table('trx_rme_invoices')->where('id', $invoice->id)->update(['subtotal' => 99000]);
    $invoice->refresh();

    $doc = RmeInvoiceDocument::fromInvoice($invoice, collect());
    expect($doc->reconciles())->toBeFalse()
        ->and($doc->discrepancies)->toContain('subtotal_mismatch')
        ->and($doc->isSettled())->toBeFalse();

    $html = invdRenderedHtml($visit, $invoice);
    expect($html)->toContain('rincian tindakan tidak sesuai dengan total invoice')
        ->not->toContain('>LUNAS<');

    // The document never "fixes" the books — nothing was written back.
    expect((string) $invoice->fresh()->subtotal)->toBe('99000.00');
});

it('converts decimal strings to cents exactly', function () {
    expect(RmeInvoiceDocument::toCents('150000.00'))->toBe(15000000)
        ->and(RmeInvoiceDocument::toCents('0.10'))->toBe(10)
        ->and(RmeInvoiceDocument::toCents('12.5'))->toBe(1250)
        ->and(RmeInvoiceDocument::toCents('9999999999999.99'))->toBe(999999999999999)
        ->and(RmeInvoiceDocument::toCents(null))->toBe(0)
        ->and(RmeInvoiceDocument::toCents(75000))->toBe(7500000)
        ->and(RmeInvoiceDocument::rupiah(122500000))->toBe('Rp 1.225.000')
        ->and(RmeInvoiceDocument::rupiah(0))->toBe('Rp 0');
});

/*
|--------------------------------------------------------------------------
| Print: one landscape page for a real-world invoice, nothing clipped
|--------------------------------------------------------------------------
*/

it('renders 1, 2, 5 and 10 treatments on exactly one A4 landscape page', function (int $count) {
    if (! pdfInfoAvailable()) {
        test()->markTestSkipped('poppler-utils (pdfinfo) is not installed.');
    }

    [$visit, $invoice] = invdInvoice(invdItems($count));
    $bytes = invdPdfBytes($visit, $invoice);

    expect(pdfPageCount($bytes))->toBe(1);

    // Landscape A4: the page box is wider than tall (841.89 x 595.28 pt).
    expect(preg_match('/\/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)\s+([\d.]+)\s*\]/', $bytes, $box))->toBe(1)
        ->and((float) $box[1])->toBeGreaterThan((float) $box[2])
        ->and(round((float) $box[1]))->toBe(842.0)
        ->and(round((float) $box[2]))->toBe(595.0);
})->with([1, 2, 5, 10]);

it('keeps every treatment and every amount readable in the PDF text', function () {
    if (! pdfToTextAvailable()) {
        test()->markTestSkipped('poppler-utils (pdftotext) is not installed.');
    }

    [$visit, $invoice] = invdInvoice([
        ['description' => 'Perawatan Saluran Akar Gigi Molar Pertama Rahang Bawah Kanan Kunjungan Kedua', 'qty' => 1, 'unit_price' => 1250000, 'discount' => 50000],
        ['description' => 'Pemasangan Mahkota Porselen pada Gigi Premolar', 'qty' => 2, 'unit_price' => 2500000],
        ['description' => 'Konsultasi', 'qty' => 1, 'unit_price' => 150000],
    ]);

    $text = pdfExtractText(invdPdfBytes($visit, $invoice));
    $treatments = pdfLayoutColumnText($text, 'Jenis Perawatan', 'Qty');

    foreach ($invoice->items as $item) {
        expect($treatments)->toContain(pdfNormalizeText($item->description));
    }

    expect($text)->toContain('1.250.000')       // gross line 1
        ->toContain('50.000')                    // its discount
        ->toContain('5.000.000')                 // 2 x 2.500.000
        ->toContain('6.400.000')                 // Jumlah
        ->toContain('6.350.000')                 // Total Biaya == Pembayaran
        ->toContain('Sisa Pembayaran')
        ->toContain('LUNAS')
        ->and(pdfLayoutFieldValue($text, 'Nama'))->toBe('Pasien Uji Invoice')
        ->and(pdfLayoutFieldValue($text, 'No. RM'))->toBe($visit->patient->medical_record_number);
});

it('never clips: the document uses no overflow hiding and no display:none on values', function () {
    $source = file_get_contents(resource_path('views/rme/cashier/receipt/partials/invoice-document.blade.php'));

    expect($source)->not->toContain('overflow: hidden')
        ->not->toContain('overflow:hidden')
        ->not->toContain('display: none')
        ->not->toContain('display:none')
        ->toContain('display: table-header-group');
});

/*
|--------------------------------------------------------------------------
| Owner-approved printed terms
|--------------------------------------------------------------------------
*/

it('prints the owner-approved refund terms and bank account from config', function () {
    [$visit, $invoice] = invdInvoice([['description' => 'Konsultasi', 'qty' => 1, 'unit_price' => 75000]]);

    $html = invdRenderedHtml($visit, $invoice);

    foreach (config('rme_invoice.refund_terms.items') as $item) {
        expect($html)->toContain(e($item['text']));
    }

    expect($html)->toContain('Ketentuan Refund')
        ->toContain('0664-01-000849-56-7')
        ->toContain('Bank BRI')
        ->toContain('Yayasan Daengtisia Peduli Indonesia')
        ->toContain('Terima Kasih Sudah Berkunjung,');
});

it('prints no refund or bank block when none is configured', function () {
    config()->set('rme_invoice.refund_terms.items', []);
    config()->set('rme_invoice.bank_transfer.account_number', null);

    [$visit, $invoice] = invdInvoice([['description' => 'Konsultasi', 'qty' => 1, 'unit_price' => 75000]]);

    $html = invdRenderedHtml($visit, $invoice);

    expect($html)->not->toContain('Ketentuan Refund')
        ->not->toContain('Nomor Rekening')
        ->not->toContain('0664-01-000849-56-7');
});

it('keeps the printed terms source-controlled, never read from the environment', function () {
    $config = file_get_contents(config_path('rme_invoice.php'));

    expect($config)->not->toMatch('/\benv\s*\(/');
});

/*
|--------------------------------------------------------------------------
| Authorization is unchanged
|--------------------------------------------------------------------------
*/

it('denies the invoice to a user who may not view receipts', function () {
    [$visit, $invoice] = invdInvoice([['description' => 'Konsultasi', 'qty' => 1, 'unit_price' => 75000]]);

    $this->actingAs(userWith(['view_clinic_visits']))
        ->get(route('rme.cashier.receipt.show', [$visit, $invoice]))
        ->assertForbidden();

    $this->actingAs(userWith(['view_clinic_visits']))
        ->get(route('rme.cashier.receipt.pdf', [$visit, $invoice]))
        ->assertForbidden();
});

it('sends a guest to login instead of serving the invoice', function () {
    [$visit, $invoice] = invdInvoice([['description' => 'Konsultasi', 'qty' => 1, 'unit_price' => 75000]]);
    auth()->logout();

    $this->get(route('rme.cashier.receipt.show', [$visit, $invoice]))->assertRedirect(route('login'));
    $this->get(route('rme.cashier.receipt.pdf', [$visit, $invoice]))->assertRedirect(route('login'));
});

it('refuses a Kasir working at another branch', function () {
    [$visit, $invoice] = invdInvoice([['description' => 'Konsultasi', 'qty' => 1, 'unit_price' => 75000]]);
    $elsewhere = Branch::factory()->create(['code' => 'INV9', 'is_active' => true, 'is_rme_enabled' => true]);

    $kasir = userInRole('Kasir');
    rmeMakeKasirActive($kasir, $elsewhere);

    $status = $this->actingAs($kasir)
        ->get(route('rme.cashier.receipt.show', [$visit, $invoice]))
        ->getStatusCode();

    expect($status)->toBeIn([403, 404]);
});

/*
|--------------------------------------------------------------------------
| Security review fixes (pre-merge)
|--------------------------------------------------------------------------
|
| The cashier routes bind {clinicVisit} and {rmeInvoice} independently and the
| policy authorizes the INVOICE only. The redesigned invoice prints the URL
| visit's patient name, RM and phone, so an unpaired URL would have printed one
| patient's identity beside another patient's money. Every route that takes
| both must refuse it.
*/

it('refuses every cashier route when the invoice was not raised for the visit in the URL', function () {
    [$visitA, $paidInvoiceA] = invdInvoice([['description' => 'Konsultasi', 'qty' => 1, 'unit_price' => 75000]]);

    $visitB = invdVisit();
    $visitB->patient->forceFill(['name' => 'Pasien Lain Rahasia', 'phone' => '0899-7777-6666'])->save();
    $unpaidInvoiceB = app(RmeInvoiceService::class)->create($visitB, $this->cashier, ['items' => [[
        'treatment_id' => Treatment::factory()->create(['is_active' => true])->id,
        'description' => 'Tambal Gigi',
        'qty' => 1,
        'unit_price' => 120000,
    ]]]);

    $this->actingAs($this->cashier);

    // A paid invoice viewed through somebody else's visit.
    foreach (['rme.cashier.show', 'rme.cashier.receipt.show', 'rme.cashier.receipt.pdf'] as $name) {
        $response = $this->get(route($name, [$visitB, $paidInvoiceA]));
        $response->assertNotFound();
        expect($response->getContent())->not->toContain('Pasien Lain Rahasia')
            ->and($response->getContent())->not->toContain('0899-7777-6666');
    }

    // An unpaid invoice paid through somebody else's visit.
    $this->get(route('rme.cashier.payment.create', [$visitA, $unpaidInvoiceB]))->assertNotFound();

    $paymentsBefore = DB::table('trx_rme_payments')->count();
    $this->post(route('rme.cashier.payment.store', [$visitA, $unpaidInvoiceB]), rmeFreshPaymentForm($unpaidInvoiceB, [
        'amount' => 120000,
        'payment_method_id' => $this->method->id,
        'paid_at' => now()->toDateTimeString(),
    ]))->assertNotFound();

    expect(DB::table('trx_rme_payments')->count())->toBe($paymentsBefore)
        ->and($unpaidInvoiceB->refresh()->status)->not->toBe(RmeInvoice::STATUS_PAID);
});

it('still serves every cashier route for the visit the invoice belongs to', function () {
    [$visitA, $paidInvoiceA] = invdInvoice([['description' => 'Konsultasi', 'qty' => 1, 'unit_price' => 75000]]);

    $visitB = invdVisit();
    $unpaidInvoiceB = app(RmeInvoiceService::class)->create($visitB, $this->cashier, ['items' => [[
        'treatment_id' => Treatment::factory()->create(['is_active' => true])->id,
        'description' => 'Tambal Gigi',
        'qty' => 1,
        'unit_price' => 120000,
    ]]]);

    $this->actingAs($this->cashier);

    $this->get(route('rme.cashier.show', [$visitA, $paidInvoiceA]))->assertOk();
    $this->get(route('rme.cashier.receipt.show', [$visitA, $paidInvoiceA]))->assertOk();
    $this->get(route('rme.cashier.receipt.pdf', [$visitA, $paidInvoiceA]))->assertOk();
    $this->get(route('rme.cashier.payment.create', [$visitB, $unpaidInvoiceB]))->assertOk();
});

it('prints the payment date and the print time on the clinic calendar, not UTC', function () {
    // 17:30 UTC is 01:30 WITA the NEXT day. A UTC date would print 11/10/2026.
    Carbon::setTestNow('2026-10-11 17:30:00');

    try {
        [$visit, $invoice] = invdInvoice(
            [['description' => 'Konsultasi', 'qty' => 1, 'unit_price' => 75000]],
            paidAt: '2026-10-11 17:30:00',
        );

        $html = invdRenderedHtml($visit, $invoice);

        expect($html)->toContain('12/10/2026')
            ->and($html)->not->toContain('11/10/2026')
            ->and($html)->toContain('Dicetak pada 12/10/2026 01:30 WITA');
    } finally {
        Carbon::setTestNow();
    }
});
