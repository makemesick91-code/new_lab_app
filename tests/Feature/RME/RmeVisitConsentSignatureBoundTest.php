<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Requests\StoreRmeVisitConsentRequest;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Prescription\Services\PrescriptionCanvasDecoder;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * DMS-RME-REVISION-PROGRAM-8D — consent signatures carry the signature payload
 * bound (rule 228 §10.3, closing the §15 OPEN LOW).
 *
 * Before 8D the two consent signature fields were bounded only by the decoder's
 * generic 3,500,000-character payload limit and its pixel caps. They now carry
 * PrescriptionCanvasDecoder::MAX_SIGNATURE_PAYLOAD_LENGTH (1,000,000 characters
 * of the submitted data URI — characters, not bytes and not MiB) twice: as a
 * FormRequest `max` at the edge, and as the decoder `maxLength` inside
 * RmeVisitConsentService::sign(), so a caller that never passes through the
 * request is bounded the same way (CG-06).
 *
 * The oversized fixture is a REAL, decodable consent canvas that every other
 * bound accepts, so these tests fail on the code before 8D for the reason they
 * name rather than on an unrelated decode error.
 */
beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake('local');

    $this->branch = Branch::factory()->create([
        'code' => 'CSB1',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);

    // Mirrors the consent operators of RmeVisitConsentHttpTest: the RME route
    // group needs view_clinic_visits, signing needs manage_rme_consents.
    $this->operator = userWith([
        'view_clinic_visits',
        'manage_rme_billing',
        'manage_rme_consents',
        'view_rme_consents',
    ]);
});

function csbVisit(?Branch $branch = null, string $status = ClinicVisit::STATUS_IN_PROGRESS): ClinicVisit
{
    $branch ??= test()->branch;

    $visit = ClinicVisit::factory()->create([
        'branch_id' => $branch->id,
        'status' => $status,
    ]);

    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    return $visit->refresh();
}

/**
 * A real RGBA canvas stored WITHOUT compression (zlib level 0), so its payload
 * length follows its size: transparent, with one opaque pixel in the middle row
 * unless $ink is false (then the decoder's blank check refuses it).
 */
function csbStoredCanvasPng(int $width, int $height, bool $ink = true): string
{
    $transparent = str_repeat("\xff\xff\xff\x00", $width);
    $raw = '';

    for ($y = 0; $y < $height; $y++) {
        $line = $ink && $y === intdiv($height, 2)
            ? substr_replace($transparent, "\x00\x00\x00\xff", intdiv($width, 2) * 4, 4)
            : $transparent;
        $raw .= "\0".$line;
    }

    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return 'data:image/png;base64,'.base64_encode(
        "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
        .$chunk('IDAT', gzcompress($raw, 0))
        .$chunk('IEND', '')
    );
}

/** The consent canvas size (700x220), uncompressed: about 822K characters, under the bound. */
function csbNearBoundSignature(): string
{
    return csbStoredCanvasPng(700, 220);
}

/** 700x300 uncompressed: about 1.12M characters, but only about 840 KB once decoded. */
function csbOversizedSignature(): string
{
    return csbStoredCanvasPng(700, 300);
}

function csbPayload(array $overrides = []): array
{
    return array_merge([
        'template_code' => 'PERSETUJUAN_TINDAKAN_MEDIS',
        'consenter_relationship' => 'self',
        'medical_action' => 'Pencabutan gigi 36',
        'treatment_summary' => 'Pencabutan gigi',
        'documentation_consent' => '0',
        'consenter_signature' => validPodSignatureData(),
    ], $overrides);
}

/** @return array<string, array<int, string>> the validation errors, or [] when the service signed */
function csbSignErrors(ClinicVisit $visit, User $operator, array $overrides): array
{
    try {
        app(RmeVisitConsentService::class)->sign($visit, $operator, csbPayload($overrides));
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

/**
 * An existing receivable on ANOTHER visit, so "finance unchanged" compares real
 * rows rather than two empty tables.
 */
function csbExistingReceivable(): void
{
    $visit = csbVisit(status: ClinicVisit::STATUS_CASHIER_PENDING);
    $treatment = Treatment::factory()->create(['is_active' => true]);

    app(RmeInvoiceService::class)->create($visit, test()->operator, [
        'items' => [[
            'treatment_id' => $treatment->id,
            'description' => 'Tindakan',
            'qty' => 1,
            'unit_price' => 150000,
        ]],
    ]);
}

/** @return array<string, mixed> */
function csbFinanceSnapshot(): array
{
    return [
        'invoices' => DB::table('trx_rme_invoices')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'payments' => DB::table('trx_rme_payments')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
    ];
}

/** Nothing was written: no consent row, no object on the private disk, the visit and finance untouched. */
function csbAssertNothingWritten(ClinicVisit $visit, array $visitBefore, array $financeBefore): void
{
    expect(DB::table('trx_rme_visit_consents')->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and($visit->fresh()->getAttributes())->toBe($visitBefore)
        ->and(csbFinanceSnapshot())->toBe($financeBefore);
}

/*
|--------------------------------------------------------------------------
| S — the fixtures sit on the side of each bound they claim
|--------------------------------------------------------------------------
*/

it('S-01 builds a near-bound and an oversized consent canvas that every other bound accepts', function () {
    $near = csbNearBoundSignature();
    $over = csbOversizedSignature();

    expect(PrescriptionCanvasDecoder::MAX_SIGNATURE_PAYLOAD_LENGTH)->toBe(1_000_000)
        ->and(PrescriptionCanvasDecoder::MAX_PAYLOAD_LENGTH)->toBe(3_500_000)
        ->and(strlen($near))->toBeGreaterThan(800_000)
        ->and(strlen($near))->toBeLessThanOrEqual(PrescriptionCanvasDecoder::MAX_SIGNATURE_PAYLOAD_LENGTH)
        ->and(strlen($over))->toBeGreaterThan(PrescriptionCanvasDecoder::MAX_SIGNATURE_PAYLOAD_LENGTH)
        ->and(strlen($over))->toBeLessThan(PrescriptionCanvasDecoder::MAX_PAYLOAD_LENGTH);

    // The bound counts characters of the data URI, not decoded bytes: the
    // oversized canvas decodes to fewer than 1,000,000 bytes (and under 1 MiB).
    $decodedOver = base64_decode(substr($over, strlen('data:image/png;base64,')), true);
    expect(strlen($decodedOver))->toBeLessThan(PrescriptionCanvasDecoder::MAX_SIGNATURE_PAYLOAD_LENGTH);

    // Both are valid, non-blank PNGs under the decoder's generic limit, so only
    // the signature bound can refuse the oversized one.
    $decoder = app(PrescriptionCanvasDecoder::class);
    expect($decoder->decode($near, 'probe'))->toBeString()
        ->and($decoder->decode($over, 'probe'))->toBeString();
});

/*
|--------------------------------------------------------------------------
| A — the request layer (StoreRmeVisitConsentRequest)
|--------------------------------------------------------------------------
*/

it('A-01 caps both consent signature fields at exactly the signature payload length', function () {
    $request = new StoreRmeVisitConsentRequest;
    $rules = $request->rules();
    $limit = PrescriptionCanvasDecoder::MAX_SIGNATURE_PAYLOAD_LENGTH;

    foreach (['consenter_signature', 'doctor_signature'] as $field) {
        expect($rules[$field])->toContain('max:'.$limit);

        $atLimit = Validator::make([$field => str_repeat('A', $limit)], [$field => $rules[$field]], $request->messages());
        expect($atLimit->errors()->has($field))->toBeFalse();

        $overLimit = Validator::make([$field => str_repeat('A', $limit + 1)], [$field => $rules[$field]], $request->messages());
        expect($overLimit->errors()->get($field))->toBe(['Ukuran gambar tanda tangan melebihi batas yang diizinkan.']);
    }
});

it('A-02 refuses an oversized consenter signature at the request, before anything is decoded or written', function () {
    csbExistingReceivable();
    $visit = csbVisit();
    $visitBefore = $visit->fresh()->getAttributes();
    $financeBefore = csbFinanceSnapshot();
    $decoder = $this->spy(PrescriptionCanvasDecoder::class);

    $this->actingAs($this->operator)
        ->from(route('rme.visits.show', $visit))
        ->post(route('rme.visits.consent.store', $visit), csbPayload([
            'consenter_signature' => csbOversizedSignature(),
        ]))
        ->assertRedirect(route('rme.visits.show', $visit))
        ->assertSessionHasErrors(['consenter_signature' => 'Ukuran gambar tanda tangan melebihi batas yang diizinkan.']);

    $decoder->shouldNotHaveReceived('decode');
    csbAssertNothingWritten($visit, $visitBefore, $financeBefore);
});

it('A-03 refuses an oversized doctor signature at the request even beside a valid consenter signature', function () {
    csbExistingReceivable();
    $visit = csbVisit();
    $visitBefore = $visit->fresh()->getAttributes();
    $financeBefore = csbFinanceSnapshot();
    $decoder = $this->spy(PrescriptionCanvasDecoder::class);

    $this->actingAs($this->operator)
        ->from(route('rme.visits.show', $visit))
        ->post(route('rme.visits.consent.store', $visit), csbPayload([
            'doctor_signature' => csbOversizedSignature(),
        ]))
        ->assertRedirect(route('rme.visits.show', $visit))
        ->assertSessionHasErrors(['doctor_signature' => 'Ukuran gambar tanda tangan melebihi batas yang diizinkan.']);

    $decoder->shouldNotHaveReceived('decode');
    csbAssertNothingWritten($visit, $visitBefore, $financeBefore);
});

it('A-04 still signs over HTTP with consent-canvas-sized consenter and doctor signatures just under the bound', function () {
    $visit = csbVisit();

    $this->actingAs($this->operator)
        ->from(route('rme.visits.show', $visit))
        ->post(route('rme.visits.consent.store', $visit), csbPayload([
            'consenter_signature' => csbNearBoundSignature(),
            'doctor_signature' => csbNearBoundSignature(),
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $consent = RmeVisitConsent::sole();

    expect($consent->clinic_visit_id)->toBe($visit->id)
        ->and($consent->consenter_signature_path)->not->toBeNull()
        ->and($consent->doctor_signature_path)->not->toBeNull()
        ->and(Storage::disk('local')->exists($consent->consenter_signature_path))->toBeTrue()
        ->and(Storage::disk('local')->exists($consent->doctor_signature_path))->toBeTrue()
        // Signing never moves the visit (AIR-RMC-06).
        ->and($visit->fresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

/*
|--------------------------------------------------------------------------
| B — the service layer (RmeVisitConsentService::sign(), no request)
|--------------------------------------------------------------------------
*/

it('B-01 refuses an oversized consenter signature in the service, writing nothing and consuming no number', function () {
    csbExistingReceivable();
    $visit = csbVisit();
    $visitBefore = $visit->fresh()->getAttributes();
    $financeBefore = csbFinanceSnapshot();

    expect(csbSignErrors($visit, $this->operator, ['consenter_signature' => csbOversizedSignature()]))
        ->toBe(['consenter_signature' => ['Ukuran gambar melebihi batas yang diizinkan.']]);

    csbAssertNothingWritten($visit, $visitBefore, $financeBefore);

    // The refused attempt consumed no consent number: the next valid consent
    // takes the first number of the clinic month.
    $consent = app(RmeVisitConsentService::class)->sign($visit, $this->operator, csbPayload());
    expect($consent->consent_number)->toEndWith('-000001');
});

it('B-02 refuses an oversized optional doctor signature in the service, writing nothing', function () {
    csbExistingReceivable();
    $visit = csbVisit();
    $visitBefore = $visit->fresh()->getAttributes();
    $financeBefore = csbFinanceSnapshot();

    expect(csbSignErrors($visit, $this->operator, ['doctor_signature' => csbOversizedSignature()]))
        ->toBe(['doctor_signature' => ['Ukuran gambar melebihi batas yang diizinkan.']]);

    csbAssertNothingWritten($visit, $visitBefore, $financeBefore);
});

it('B-03 applies the signature bound before decoding, so an over-long payload is refused for its size', function () {
    $visit = csbVisit();
    $prefix = 'data:image/png;base64,';
    $overLong = $prefix.str_repeat('A', PrescriptionCanvasDecoder::MAX_SIGNATURE_PAYLOAD_LENGTH + 1 - strlen($prefix));

    expect(strlen($overLong))->toBe(PrescriptionCanvasDecoder::MAX_SIGNATURE_PAYLOAD_LENGTH + 1);

    foreach (['consenter_signature', 'doctor_signature'] as $field) {
        expect(csbSignErrors($visit, $this->operator, [$field => $overLong]))
            ->toBe([$field => ['Ukuran gambar melebihi batas yang diizinkan.']]);
    }

    expect(DB::table('trx_rme_visit_consents')->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('B-04 still signs in the service with near-bound consenter and doctor signatures', function () {
    $visit = csbVisit();

    expect(csbSignErrors($visit, $this->operator, [
        'consenter_signature' => csbNearBoundSignature(),
        'doctor_signature' => csbNearBoundSignature(),
    ]))->toBe([]);

    $consent = RmeVisitConsent::sole();

    expect(Storage::disk('local')->exists($consent->consenter_signature_path))->toBeTrue()
        ->and(Storage::disk('local')->exists($consent->doctor_signature_path))->toBeTrue()
        ->and($consent->doctor_id)->toBe($visit->doctor_id);
});

it('B-05 keeps refusing malformed, blank and missing signatures under the bound', function () {
    $visit = csbVisit();
    $notAPng = 'data:image/png;base64,'.base64_encode(str_repeat('not a png ', 50));
    $blank = csbStoredCanvasPng(700, 220, ink: false);

    expect(csbSignErrors($visit, $this->operator, ['consenter_signature' => $notAPng]))
        ->toBe(['consenter_signature' => ['Data gambar tidak valid.']])
        ->and(csbSignErrors($visit, $this->operator, ['doctor_signature' => $notAPng]))
        ->toBe(['doctor_signature' => ['Data gambar tidak valid.']])
        ->and(csbSignErrors($visit, $this->operator, ['consenter_signature' => $blank]))
        ->toBe(['consenter_signature' => ['Area gambar kosong tidak dapat disimpan.']])
        ->and(csbSignErrors($visit, $this->operator, ['consenter_signature' => null]))
        ->toBe(['consenter_signature' => ['Tanda tangan pemberi persetujuan wajib diisi.']]);

    expect(DB::table('trx_rme_visit_consents')->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

/*
|--------------------------------------------------------------------------
| C — the authorization and branch guards are unchanged
|--------------------------------------------------------------------------
*/

it('C-01 still answers 403 to an account without the consent permission, whatever the payload size', function () {
    $visit = csbVisit();
    $outsider = userWith(['view_clinic_visits', 'manage_rme_billing']);

    foreach ([csbPayload(), csbPayload(['consenter_signature' => csbOversizedSignature()])] as $payload) {
        $this->actingAs($outsider)
            ->post(route('rme.visits.consent.store', $visit), $payload)
            ->assertForbidden();
    }

    expect(DB::table('trx_rme_visit_consents')->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('C-02 still refuses a valid consent from an operator pinned to another working branch', function () {
    $visit = csbVisit();
    $otherBranch = Branch::factory()->create([
        'code' => 'CSB9',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);

    $pinned = userWith(['view_clinic_visits', 'manage_rme_billing', 'manage_rme_consents', 'view_rme_consents']);
    $pinned->forceFill(['branch_id' => $otherBranch->id])->save();
    rmeMakeKasirActive($pinned, $otherBranch);

    $this->actingAs($pinned)
        ->post(route('rme.visits.consent.store', $visit), csbPayload())
        ->assertForbidden();

    expect(DB::table('trx_rme_visit_consents')->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('C-03 still refuses signing a visit that is no longer in progress, even with a valid signature', function () {
    $visit = csbVisit(status: ClinicVisit::STATUS_COMPLETED);

    expect(csbSignErrors($visit, $this->operator, []))->toHaveKey('clinic_visit_id');
    expect(DB::table('trx_rme_visit_consents')->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});
