<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 — an RM handwriting page has bounded cost
|--------------------------------------------------------------------------
|
| Rule 228 §10 bounded the prescription and consent canvases (8C, 8D). The RM
| handwriting page — the largest canvas the application draws, 900 x 1273 RGBA —
| had no bound at all: no payload limit, an IHDR size taken on trust by the blank
| check (which allocates a scanline buffer from it) and an unbounded inflate.
|
| Now (HandwritingPayloadGuard, rule 237 §5): the request caps the payload
| (8,000,000 characters), the guard caps it again before decoding, refuses a declared size beyond 2048 px a side or
| 2,097,152 pixels, refuses a second IHDR, and refuses image data that inflates
| past what the declared size can hold. Every bound sits above the worst case
| of the real page canvas (HW-06), and a refusal leaves the saved page as it was.
|
| Selected by the existing `RmeClinicalWriteAuthority` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `rmehw` is unique because the critical gate loads every suite into one process.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Controllers\MedicalRecordHandwritingController;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Services\HandwritingPayloadGuard;
use App\Modules\Patient\Models\Patient;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Storage::fake('clinical_evidence');

    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->author = rmeClinicalAuthor(['manage_clinic_visits']);
    $patient = Patient::factory()->create();

    $this->visit = ClinicVisit::factory()->create([
        'branch_id' => $branch->id,
        'clinic_id' => Clinic::factory()->create()->id,
        'patient_id' => $patient->id,
        'doctor_id' => Doctor::factory()->create()->id,
        'created_by' => $this->author->id,
        'queue_number' => 1,
    ]);
    rmeActiveConsentedEncounter($this->visit);

    $this->record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => $this->visit->doctor_id,
    ]);
});

function rmehwChunk(string $type, string $data): string
{
    return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
}

function rmehwIhdr(int $width, int $height): string
{
    return rmehwChunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0));
}

/** A real RGBA PNG; with $noise every pixel differs, the least compressible page possible. */
function rmehwPng(int $width, int $height, bool $noise = false, int $level = 6): string
{
    $raw = '';

    for ($y = 0; $y < $height; $y++) {
        $raw .= "\0".($noise ? random_bytes($width * 4) : str_repeat("\xff\xff\xff\x00", $width));
    }

    if (! $noise) {
        // One dark pixel, so the blank check accepts it as real handwriting.
        $raw[1 + intdiv($height, 2) * (1 + $width * 4) + 4 * intdiv($width, 2) + 3] = "\xff";
    }

    return "\x89PNG\r\n\x1a\n".rmehwIhdr($width, $height).rmehwChunk('IDAT', gzcompress($raw, $level)).rmehwChunk('IEND', '');
}

function rmehwUri(string $png): string
{
    return 'data:image/png;base64,'.base64_encode($png);
}

function rmehwSave(object $t, string $payload)
{
    return $t->actingAs($t->author)->post(
        route('rme.visits.medical-record.handwriting.store', [$t->visit, $t->record]),
        ['handwriting_data' => $payload],
    );
}

function rmehwSavedPath(object $t): ?string
{
    return MedicalRecordHandwriting::query()->where('medical_record_id', $t->record->id)->value('handwriting_path');
}

it('HW-01 refuses an otherwise valid page over the payload bound at the request, writing nothing', function () {
    // 1100 x 1400 noise, stored uncompressed: inside the dimension and pixel caps,
    // so the ONLY reason to refuse it is the payload length.
    $payload = rmehwUri(rmehwPng(1100, 1400, noise: true, level: 0));

    expect(strlen($payload))->toBeGreaterThan(HandwritingPayloadGuard::MAX_HANDWRITING_PAYLOAD_LENGTH);

    rmehwSave($this, $payload)->assertSessionHasErrors('handwriting_data');

    expect(session('errors')->first('handwriting_data'))->toContain('8000000')
        ->and(rmehwSavedPath($this))->toBeNull();
});

it('HW-02 refuses a PNG that declares a side beyond the dimension cap, before inflating it', function () {
    $png = "\x89PNG\r\n\x1a\n".rmehwIhdr(4096, 10).rmehwChunk('IDAT', gzcompress('x')).rmehwChunk('IEND', '');

    rmehwSave($this, rmehwUri($png))
        ->assertSessionHasErrors(['handwriting_data' => HandwritingPayloadGuard::MESSAGE_TOO_LARGE]);

    expect(rmehwSavedPath($this))->toBeNull();
});

it('HW-03 refuses a PNG that declares more pixels than the cap, even with both sides under the dimension cap', function () {
    $png = "\x89PNG\r\n\x1a\n".rmehwIhdr(2048, 2048).rmehwChunk('IDAT', gzcompress('x')).rmehwChunk('IEND', '');

    rmehwSave($this, rmehwUri($png))
        ->assertSessionHasErrors(['handwriting_data' => HandwritingPayloadGuard::MESSAGE_TOO_LARGE]);
});

it('HW-04 refuses image data that inflates far past what its declared size can hold', function () {
    // A 100 x 100 page can hold at most 100 * (1 + 100 * 8) = 80,100 bytes;
    // this stream is a few KB and inflates to 10,000,000.
    $png = "\x89PNG\r\n\x1a\n".rmehwIhdr(100, 100).rmehwChunk('IDAT', gzcompress(str_repeat("\0", 10_000_000), 9)).rmehwChunk('IEND', '');

    expect(strlen($png))->toBeLessThan(20_000);

    rmehwSave($this, rmehwUri($png))
        ->assertSessionHasErrors(['handwriting_data' => HandwritingPayloadGuard::MESSAGE_TOO_LARGE]);

    expect(rmehwSavedPath($this))->toBeNull();
});

it('HW-05 refuses a PNG with a second IHDR that re-declares the size', function () {
    $png = "\x89PNG\r\n\x1a\n".rmehwIhdr(10, 10).rmehwIhdr(60_000, 60_000)
        .rmehwChunk('IDAT', gzcompress(str_repeat("\0".str_repeat("\x00\x00\x00\xff", 10), 10)))
        .rmehwChunk('IEND', '');

    rmehwSave($this, rmehwUri($png))
        ->assertSessionHasErrors(['handwriting_data' => 'Data tulisan tangan tidak valid atau kosong.']);

    expect(rmehwSavedPath($this))->toBeNull();
});

it('HW-06 still saves the real 900 x 1273 page canvas, even at its least compressible', function () {
    $worst = rmehwUri(rmehwPng(900, 1273, noise: true, level: 0));

    expect(strlen($worst))->toBeGreaterThan(6_000_000)
        ->and(strlen($worst))->toBeLessThanOrEqual(HandwritingPayloadGuard::MAX_HANDWRITING_PAYLOAD_LENGTH);

    rmehwSave($this, $worst)->assertSessionHasNoErrors();
    expect(rmehwSavedPath($this))->not->toBeNull();

    rmehwSave($this, rmehwUri(rmehwPng(900, 1273)))->assertSessionHasNoErrors();
});

it('HW-07 leaves the saved page untouched when a later save is refused for its size', function () {
    rmehwSave($this, rmehwUri(rmehwPng(900, 1273)))->assertSessionHasNoErrors();
    $saved = rmehwSavedPath($this);

    $bomb = "\x89PNG\r\n\x1a\n".rmehwIhdr(100, 100).rmehwChunk('IDAT', gzcompress(str_repeat("\0", 10_000_000), 9)).rmehwChunk('IEND', '');
    rmehwSave($this, rmehwUri($bomb))->assertSessionHasErrors('handwriting_data');

    expect(rmehwSavedPath($this))->toBe($saved)
        ->and(Storage::disk('clinical_evidence')->exists($saved))->toBeTrue();
});

it('HW-08 keeps the payload bound in the request rules and above the page canvas worst case', function () {
    // The 900 x 1273 RGBA canvas, stored uncompressed: 1273 * (1 + 3600) bytes of
    // image data plus PNG framing, base64-encoded with its data-URI prefix.
    $worstCase = (int) ceil((1273 * (1 + 900 * 4) + 1024) / 3) * 4 + 22;

    expect(HandwritingPayloadGuard::MAX_HANDWRITING_PAYLOAD_LENGTH)->toBeGreaterThan($worstCase)
        ->and(900 * 1273)->toBeLessThanOrEqual(HandwritingPayloadGuard::MAX_HANDWRITING_PIXELS)
        ->and(1273)->toBeLessThanOrEqual(HandwritingPayloadGuard::MAX_HANDWRITING_DIMENSION);

    $source = (string) file_get_contents((new ReflectionClass(MedicalRecordHandwritingController::class))->getFileName());
    expect($source)->toContain("'max:'.HandwritingPayloadGuard::MAX_HANDWRITING_PAYLOAD_LENGTH")
        ->and($source)->toContain('$this->payloadGuard->assertPayloadLength($raw)')
        ->and($source)->toContain('$this->payloadGuard->assertBoundedPng($decoded)');
});
