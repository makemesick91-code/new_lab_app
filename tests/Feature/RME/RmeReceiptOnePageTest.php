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

/**
 * A patient name long enough to overflow the receipt's fixed-width "Nama
 * Pasien" cell, so the wrap is exercised on EVERY run instead of whenever
 * faker happens to produce a long one.
 *
 * Deliberately carries no apostrophe. The historical failure was blamed on
 * `Miss Marcella O'Conner DVM` looking like this repository's documented
 * faker-vs-Blade-escaping flake, and a plain name that fails identically is
 * the cheapest permanent refutation of that reading.
 */
function receiptWrappingPatientName(): string
{
    return 'Alexandria Catherine Santoso';
}

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
    $visit->refresh();

    /*
     * Pin the name. Faker generated one that overflowed the cell on roughly 6%
     * of runs, which is exactly how often the old contiguity assertion failed
     * against a perfectly correct receipt. Pinning it removes the coin flip in
     * both directions: the wrap is always covered, and never random.
     */
    $visit->patient->forceFill(['name' => receiptWrappingPatientName()])->save();

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

    // Every item, with its own money, must be readable. The description is read
    // out of the TINDAKAN column: a long one wraps, and the layout splits it
    // AROUND its own qty/price line, so it is not one contiguous substring.
    $treatments = pdfLayoutColumnText($text, 'TINDAKAN', 'QTY');

    foreach ($invoice->items as $item) {
        expect($treatments)->toContain(pdfNormalizeText($item->description));
        expect($text)->toContain(number_format($item->subtotal, 0, ',', '.'));
    }

    // Totals and payment.
    expect($text)->toContain(number_format($invoice->grand_total, 0, ',', '.'));
    expect($text)->toContain('Total Tagihan');
    expect($text)->toContain('Jumlah Dibayar');
    expect($text)->toContain('LUNAS');

    /*
     * Identity of the transaction. The patient name is read out of its own
     * layout cell and compared for EQUALITY, which is strictly stronger than
     * the substring search it replaces: the neighbouring "No. Rekam Medis"
     * column is outside the band, so it can no longer satisfy the assertion,
     * and a partially rendered name no longer passes either.
     */
    expect(pdfLayoutFieldValue($text, 'Nama Pasien'))->toBe($visit->patient->name);
    expect(pdfLayoutFieldValue($text, 'No. Rekam Medis'))->toBe($visit->patient->medical_record_number);
    expect($text)->toContain($visit->visit_number);
    /*
     * The clinic name is CSS-uppercased in the header, so compare
     * case-insensitively — and read it out of the full-width header lines,
     * because a long clinic name wraps there exactly as the patient name wraps
     * in its cell.
     */
    expect(strtoupper(pdfLayoutFullWidthText($text)))
        ->toContain(strtoupper(pdfNormalizeText($this->branch->name)));
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
    $treatments = pdfLayoutColumnText($text, 'TINDAKAN', 'QTY');

    foreach ($invoice->items as $item) {
        expect($treatments)->toContain(pdfNormalizeText($item->description));
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

/*
|--------------------------------------------------------------------------
| D — the PDF text contract itself
|--------------------------------------------------------------------------
|
| FIX-RECEIPT-PDF-TEXT-CONTIGUITY-1.
|
| The one authorised consolidated Full Suite (run 32700184849) reddened here on
| `expect($text)->toContain($visit->patient->name)`. The receipt was correct:
| `pdftotext -layout` had wrapped a 26-character name inside its fixed-width
| cell and interleaved the neighbouring column, so the name was present but not
| contiguous. The assertion was testing a layout property while claiming to test
| a content property.
|
| These cases pin the corrected contract from both ends: the value is read out
| of its own column and must match exactly, and a wrong, partial or misplaced
| value must still fail.
*/

/**
 * Render a receipt for one specific patient name and return its extracted text.
 *
 * @return array{0: ClinicVisit, 1: string}
 */
function receiptTextForPatientName(string $name): array
{
    [$visit, $invoice] = receiptPaidInvoice([
        ['description' => 'Konsultasi Dokter Gigi', 'qty' => 1, 'unit_price' => 75000],
    ]);

    $visit->patient->forceFill(['name' => $name])->save();
    $visit->refresh();

    return [$visit, pdfExtractText(receiptPdfBytes($visit, $invoice))];
}

it('reads every patient-name shape out of its own layout cell', function (string $name, bool $wraps) {
    if (! pdfToTextAvailable()) {
        test()->markTestSkipped('poppler-utils (pdftotext) is not installed.');
    }

    [$visit, $text] = receiptTextForPatientName($name);

    // The contract: the field holds exactly this name, wrapped or not.
    expect(pdfLayoutFieldValue($text, 'Nama Pasien'))->toBe($name);

    /*
     * And the coverage claim itself is pinned. If a long fixture stops wrapping
     * — a wider cell, a smaller font — this reddens rather than silently
     * ceasing to exercise the class that took the Full Suite down. A failure
     * here means "pick a longer name", not "the receipt is broken".
     */
    expect(str_contains($text, $name))->toBe(
        ! $wraps,
        $wraps
            ? "'{$name}' no longer wraps in the receipt cell, so this case no longer covers the contiguity defect — lengthen the fixture."
            : "'{$name}' unexpectedly wraps; it was chosen as the short control."
    );
})->with([
    'short plain' => ['Budi Santoso', false],
    // Short AND apostrophe-bearing: the historical failure was misread as an
    // escaping bug, and this is the standing refutation of that reading.
    'short apostrophe' => ["O'Brien", false],
    // Long AND apostrophe-free: fails identically to the faker name that
    // reddened the Full Suite, which is what proves length is the cause.
    'long plain' => ['Alexandria Catherine Santoso', true],
    'long apostrophe' => ["Miss Marcella O'Conner DVM", true],
]);

it('rejects a wrong or partially rendered patient name', function () {
    if (! pdfToTextAvailable()) {
        test()->markTestSkipped('poppler-utils (pdftotext) is not installed.');
    }

    $name = receiptWrappingPatientName();
    [, $text] = receiptTextForPatientName($name);

    $field = pdfLayoutFieldValue($text, 'Nama Pasien');

    // A different patient must not satisfy it.
    expect($field)->not->toBe('Bambang Wijaya');

    // Neither must a name that lost its wrapped tail — the exact failure mode a
    // merely "wrap-tolerant" assertion would let through.
    expect($field)->not->toBe('Alexandria Catherine');

    // Nor a token soup: the words are all present in the document, and that is
    // deliberately not enough.
    expect($field)->not->toBe('Catherine Alexandria Santoso');
});

it('keeps the patient name out of the neighbouring medical-record column', function () {
    if (! pdfToTextAvailable()) {
        test()->markTestSkipped('poppler-utils (pdftotext) is not installed.');
    }

    /*
     * The wrapped name and the medical-record number share the same physical
     * lines. Reading either field must not drag the other in — which is what a
     * whole-page whitespace-stripping comparison would have done.
     */
    [$visit, $text] = receiptTextForPatientName(receiptWrappingPatientName());

    $mrn = $visit->patient->medical_record_number;

    expect(pdfLayoutFieldValue($text, 'No. Rekam Medis'))->toBe($mrn);
    expect(pdfLayoutFieldValue($text, 'Nama Pasien'))->not->toContain($mrn);
    expect(pdfLayoutFieldValue($text, 'No. Rekam Medis'))->not->toBe('MRN-NOTTHISONE');
});

it('reads a treatment description that the layout split around its own money row', function () {
    if (! pdfToTextAvailable()) {
        test()->markTestSkipped('poppler-utils (pdftotext) is not installed.');
    }

    /*
     * The item table wraps differently from the identity grid: the tail of a
     * long description lands BELOW its own qty/price line, so "join the next
     * line" cannot rebuild it. The TINDAKAN column band can.
     */
    $long = 'Perawatan Saluran Akar Gigi Molar Pertama Rahang Bawah Kanan Kunjungan Kedua';

    [$visit, $invoice] = receiptPaidInvoice([
        ['description' => $long, 'qty' => 1, 'unit_price' => 1250000],
        ['description' => 'Konsultasi', 'qty' => 1, 'unit_price' => 75000],
    ]);

    $text = pdfExtractText(receiptPdfBytes($visit, $invoice));
    $treatments = pdfLayoutColumnText($text, 'TINDAKAN', 'QTY');

    // Not contiguous in the raw extraction...
    expect(str_contains($text, $long))->toBeFalse(
        'The long description no longer wraps, so this case no longer covers the split-cell defect — lengthen it.'
    );

    // ...but complete in its own column, and the money columns stayed out of it.
    expect($treatments)->toContain(pdfNormalizeText($long));
    expect($treatments)->toContain('Konsultasi');
    expect($treatments)->not->toContain('1.250.000');
    expect($treatments)->not->toContain('Tindakan Yang Tidak Pernah Ada');
});
