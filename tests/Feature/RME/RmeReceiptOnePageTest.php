<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-06 — the kwitansi is one page.
 *
 * Proven against REAL RENDERER OUTPUT, not against CSS: the receipt is rendered
 * through the project's dompdf pipeline and the page count is read back with
 * poppler's pdfinfo. Both are existing, CI-verified dependencies.
 *
 * The contract has two halves, and a test for only the first half would be
 * dangerous — a receipt can always be made to fit by dropping rows:
 *
 *   1. a representative receipt renders on exactly ONE page, and
 *   2. every financial value is still present in that page's text.
 */
beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake('local');

    $this->branch = Branch::factory()->create([
        'code' => 'RCP1',
        'name' => 'Klinik Gigi Daengtisia Telkomas',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);

    $this->cashier = userWith(['view_clinic_visits', 'manage_rme_billing']);
});

function receiptVisit(): ClinicVisit
{
    $visit = ClinicVisit::factory()->cashierPending()->create(['branch_id' => test()->branch->id]);

    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    rmeSignedConsentFor($visit);

    return $visit->refresh();
}

/**
 * @param  array<int, array{description: string, qty: int, unit_price: float}>  $items
 */
function receiptPaidInvoice(array $items, ?float $payAmount = null): array
{
    $visit = receiptVisit();

    $payload = array_map(function (array $item) {
        return [
            'treatment_id' => Treatment::factory()->create(['is_active' => true])->id,
            'description' => $item['description'],
            'qty' => $item['qty'],
            'unit_price' => $item['unit_price'],
        ];
    }, $items);

    $invoice = app(RmeInvoiceService::class)->create($visit, test()->cashier, ['items' => $payload]);

    $total = $invoice->grand_total;
    app(RmePaymentService::class)->pay($invoice, test()->cashier, [
        'amount' => $payAmount ?? $total,
        'paid_at' => now()->toDateTimeString(),
    ]);

    return [$visit->refresh(), $invoice->refresh()];
}

function receiptPdfBytes(ClinicVisit $visit, RmeInvoice $invoice): string
{
    $response = test()->actingAs(test()->cashier)
        ->get(route('rme.cashier.receipt.pdf', [$visit, $invoice]));

    $response->assertOk();

    return $response->getContent();
}

/**
 * A realistic single-visit receipt: a consultation plus a few treatments.
 *
 * @return array<int, array{description: string, qty: int, unit_price: float}>
 */
function representativeReceiptItems(): array
{
    return [
        ['description' => 'Konsultasi Dokter Gigi', 'qty' => 1, 'unit_price' => 75000],
        ['description' => 'Pembersihan Karang Gigi (Scaling) Rahang Atas', 'qty' => 1, 'unit_price' => 350000],
        ['description' => 'Pembersihan Karang Gigi (Scaling) Rahang Bawah', 'qty' => 1, 'unit_price' => 350000],
        ['description' => 'Tambalan Komposit Gigi 36', 'qty' => 1, 'unit_price' => 400000],
        ['description' => 'Pencabutan Gigi Susu', 'qty' => 2, 'unit_price' => 150000],
    ];
}

/*
|--------------------------------------------------------------------------
| A — the page-count contract
|--------------------------------------------------------------------------
*/

it('renders a representative receipt on exactly one page', function () {
    if (! pdfInfoAvailable()) {
        test()->markTestSkipped('poppler-utils (pdfinfo) is not installed.');
    }

    [$visit, $invoice] = receiptPaidInvoice(representativeReceiptItems());

    expect(pdfPageCount(receiptPdfBytes($visit, $invoice)))->toBe(1);
});

it('renders a minimal single-item receipt on one page', function () {
    if (! pdfInfoAvailable()) {
        test()->markTestSkipped('poppler-utils (pdfinfo) is not installed.');
    }

    [$visit, $invoice] = receiptPaidInvoice([
        ['description' => 'Konsultasi Dokter Gigi', 'qty' => 1, 'unit_price' => 75000],
    ]);

    expect(pdfPageCount(receiptPdfBytes($visit, $invoice)))->toBe(1);
});

it('does not issue a receipt at all for a partially paid invoice', function () {
    /*
     * Discovered while proving the one-page contract: the kwitansi is a
     * PELUNASAN document and both receipt routes redirect unless the invoice is
     * PAID. So a partial payment has no receipt to size, and this sprint does
     * not change that rule — it records it.
     *
     * The PDF template still renders an outstanding-balance box and a
     * "DIBAYAR SEBAGIAN" stamp defensively, so that if receipts are ever opened
     * up to partial settlements the document tells the truth instead of
     * stamping LUNAS over money that is still owed.
     */
    [$visit, $invoice] = receiptPaidInvoice(representativeReceiptItems(), 500000);

    expect($invoice->remainingAmount())->toBeGreaterThan(0);
    expect($invoice->status)->toBe(RmeInvoice::STATUS_PARTIAL);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.receipt.pdf', [$visit, $invoice]))
        ->assertRedirect(route('rme.cashier.show', [$visit, $invoice]));

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.receipt.show', [$visit, $invoice]))
        ->assertRedirect(route('rme.cashier.show', [$visit, $invoice]));
});

it('renders a receipt with long patient, branch and treatment text on one page', function () {
    if (! pdfInfoAvailable()) {
        test()->markTestSkipped('poppler-utils (pdfinfo) is not installed.');
    }

    $this->branch->update([
        'name' => 'Klinik Gigi Daengtisia Cabang Telkomas Makassar Sulawesi Selatan',
        'address' => 'Jalan Perintis Kemerdekaan Kilometer 12 Nomor 45, Kelurahan Tamalanrea Indah, Kecamatan Tamalanrea, Kota Makassar, Sulawesi Selatan 90245',
        'phone' => '+62 411 1234567',
    ]);

    [$visit, $invoice] = receiptPaidInvoice([
        ['description' => 'Perawatan Saluran Akar Gigi Molar Pertama Rahang Bawah Kanan Kunjungan Kedua', 'qty' => 1, 'unit_price' => 1250000],
        ['description' => 'Pemasangan Mahkota Porselen Fused to Metal pada Gigi Premolar', 'qty' => 2, 'unit_price' => 2500000],
        ['description' => 'Konsultasi dan Evaluasi Radiografi Periapikal', 'qty' => 1, 'unit_price' => 150000],
    ]);

    expect(pdfPageCount(receiptPdfBytes($visit, $invoice)))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| B — nothing was dropped to achieve it
|--------------------------------------------------------------------------
*/

it('keeps every financial value on the one-page receipt', function () {
    if (! pdfToTextAvailable()) {
        test()->markTestSkipped('poppler-utils (pdftotext) is not installed.');
    }

    [$visit, $invoice] = receiptPaidInvoice(representativeReceiptItems());

    $text = pdfExtractText(receiptPdfBytes($visit, $invoice));

    // Prove extraction works before asserting anything about content.
    expect($text)->toContain($invoice->invoice_number);

    // Every item, with its own money, must be readable.
    foreach ($invoice->items as $item) {
        expect($text)->toContain($item->description);
        expect($text)->toContain(number_format($item->subtotal, 0, ',', '.'));
    }

    // Totals and payment.
    expect($text)->toContain(number_format($invoice->grand_total, 0, ',', '.'));
    expect($text)->toContain('Total Tagihan');
    expect($text)->toContain('Jumlah Dibayar');
    expect($text)->toContain('LUNAS');

    // Identity of the transaction.
    expect($text)->toContain($visit->patient->name);
    expect($text)->toContain($visit->visit_number);
    // The clinic name is CSS-uppercased in the header, so compare case-insensitively.
    expect(strtoupper($text))->toContain(strtoupper($this->branch->name));
});

it('never truncates an unusually long receipt — it continues instead', function () {
    if (! pdfToTextAvailable() || ! pdfInfoAvailable()) {
        test()->markTestSkipped('poppler-utils is not installed.');
    }

    /*
     * Invoice items are not capped by the application (CreateRmeInvoiceRequest
     * has min:1 and no max), so an arbitrarily long receipt is possible in
     * principle. The contract is one page for the real-world envelope; beyond
     * it the document must GROW, never lose a row. This asserts the failure
     * mode is continuation, not silent truncation.
     */
    $items = [];
    for ($i = 1; $i <= 40; $i++) {
        $items[] = [
            'description' => 'Tindakan Uji Panjang Nomor '.$i,
            'qty' => 1,
            'unit_price' => 100000,
        ];
    }

    [$visit, $invoice] = receiptPaidInvoice($items);

    $bytes = receiptPdfBytes($visit, $invoice);
    $text = pdfExtractText($bytes);

    // It legitimately spills past one page...
    expect(pdfPageCount($bytes))->toBeGreaterThan(1);

    // ...and every single row survives, including the last one and the total.
    foreach ($invoice->items as $item) {
        expect($text)->toContain($item->description);
    }
    expect($text)->toContain('Tindakan Uji Panjang Nomor 40');
    expect($text)->toContain(number_format($invoice->grand_total, 0, ',', '.'));
});

/*
|--------------------------------------------------------------------------
| C — the PDF route inherits the receipt's own rules
|--------------------------------------------------------------------------
*/

it('refuses the receipt PDF for an invoice that is not paid', function () {
    $visit = receiptVisit();
    $invoice = app(RmeInvoiceService::class)->create($visit, $this->cashier, [
        'items' => [[
            'treatment_id' => Treatment::factory()->create(['is_active' => true])->id,
            'description' => 'Konsultasi',
            'qty' => 1,
            'unit_price' => 100000,
        ]],
    ]);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.receipt.pdf', [$visit, $invoice]))
        ->assertRedirect(route('rme.cashier.show', [$visit, $invoice]));
});

it('denies the receipt PDF to a user who may not view the receipt', function () {
    [$visit, $invoice] = receiptPaidInvoice(representativeReceiptItems());

    $outsider = userWith(['view_clinic_visits']);

    $this->actingAs($outsider)
        ->get(route('rme.cashier.receipt.pdf', [$visit, $invoice]))
        ->assertForbidden();
});

it('still renders the on-screen receipt with a declared page box', function () {
    [$visit, $invoice] = receiptPaidInvoice(representativeReceiptItems());

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.receipt.show', [$visit, $invoice]))
        ->assertOk()
        ->assertSee('Kwitansi Pembayaran RME')
        ->assertSee('LUNAS')
        // The missing @page rule was the root cause of the second sheet.
        ->assertSee('@page', false)
        ->assertSee('Unduh PDF');
});
