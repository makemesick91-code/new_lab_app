<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabWorkflowEvidence;
use App\Modules\LabOrder\Services\LabEvidenceImageOptimizer;
use App\Modules\LabOrder\Services\LabWorkflowEvidenceService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedAccessControl();
    Branch::factory()->main()->create();
});

/**
 * FIX-LAB-...-UPLOAD-COMPRESSION — every Lab Workflow evidence upload flows
 * through the canonical adaptive-compression pipeline (validation, EXIF
 * strip, resize, bounded re-encode, private storage, size-audit metadata).
 *
 * GD-dependent cases are skipped honestly on runtimes without the extension
 * (some local CLIs); CI and the VPS pilot run them with GD enabled.
 */
const CMP_NO_GD = 'GD extension not available on this runtime';

function cmpOrder(): LabOrder
{
    return LabOrder::factory()->create(['workflow_version' => LabOrder::WORKFLOW_V2]);
}

function cmpActor(): User
{
    return userWith(['manage_lab_orders']);
}

function cmpService(): LabWorkflowEvidenceService
{
    return app(LabWorkflowEvidenceService::class);
}

/** GD-generated JPEG with noisy content (resists compression → exercises the loop). */
function cmpGdJpeg(int $width, int $height, int $quality = 96, bool $noise = true): string
{
    $img = imagecreatetruecolor($width, $height);
    imagefill($img, 0, 0, imagecolorallocate($img, 220, 220, 220));

    if ($noise) {
        mt_srand(42);
        for ($i = 0; $i < 20000; $i++) {
            $color = imagecolorallocate($img, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
            $x = mt_rand(0, $width - 4);
            $y = mt_rand(0, $height - 4);
            imagefilledrectangle($img, $x, $y, $x + 3, $y + 3, $color);
        }
    }

    ob_start();
    imagejpeg($img, null, $quality);
    imagedestroy($img);

    return (string) ob_get_clean();
}

function cmpGdPng(int $width, int $height, bool $transparent = false): string
{
    $img = imagecreatetruecolor($width, $height);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    $bg = $transparent
        ? imagecolorallocatealpha($img, 0, 0, 0, 127)
        : imagecolorallocate($img, 40, 60, 200);
    imagefill($img, 0, 0, $bg);
    imagesetpixel($img, 0, 0, imagecolorallocate($img, 10, 10, 10)); // a "stroke"

    ob_start();
    imagepng($img, null, 6);
    imagedestroy($img);

    return (string) ob_get_clean();
}

/** Minimal, valid EXIF APP1 (Orientation tag) spliced after the JPEG SOI marker. */
function cmpSpliceExifOrientation(string $jpeg, int $orientation): string
{
    $tiff = "II*\x00".pack('V', 8)
        .pack('v', 1)
        .pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', $orientation).pack('v', 0)
        .pack('V', 0);
    $payload = "Exif\x00\x00".$tiff;
    $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

    return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
}

/** PNG signature + IHDR only — enough for header-based dimension checks. */
function cmpFakePngWithDims(int $width, int $height): string
{
    $ihdr = pack('N', $width).pack('N', $height)."\x08\x02\x00\x00\x00";

    return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));
}

function cmpUpload(string $binary, string $name = 'evidence.jpg'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $binary);
}

// ---------------------------------------------------------------------------
// Security validation (runs on every runtime — no GD needed)
// ---------------------------------------------------------------------------

it('rejects corrupted images that carry a valid magic header', function () {
    $garbage = "\x89PNG\r\n\x1a\n".random_bytes(512);

    expect(fn () => cmpService()->storePhoto(cmpOrder(), LabWorkflowEvidence::TYPE_SPK_PHOTO, cmpUpload($garbage, 'x.png'), cmpActor()))
        ->toThrow(ValidationException::class);
});

it('rejects MIME-spoofed non-image payloads regardless of the filename', function () {
    expect(fn () => cmpService()->storePhoto(cmpOrder(), LabWorkflowEvidence::TYPE_SPK_PHOTO, cmpUpload('<?php echo 1;', 'spoof.jpg'), cmpActor()))
        ->toThrow(ValidationException::class);
});

it('rejects decompression bombs from header dimensions before any decode', function () {
    $bomb = cmpFakePngWithDims(60000, 60000);

    expect(fn () => cmpService()->storePhoto(cmpOrder(), LabWorkflowEvidence::TYPE_MODEL_PHOTO_BRANCH, cmpUpload($bomb, 'bomb.png'), cmpActor()))
        ->toThrow(ValidationException::class);
});

it('rejects photo input above the hard input cap', function () {
    $huge = cmpFakePngWithDims(100, 100).str_repeat('A', 11 * 1024 * 1024);

    expect(fn () => cmpService()->storePhoto(cmpOrder(), LabWorkflowEvidence::TYPE_PICKUP_PHOTO, cmpUpload($huge, 'big.png'), cmpActor()))
        ->toThrow(ValidationException::class);
});

it('rejects signature payloads that are not decodable PNG', function () {
    expect(fn () => cmpService()->storePng(cmpOrder(), LabWorkflowEvidence::TYPE_COURIER_SIGNATURE, "\x89PNG\r\n\x1a\n".random_bytes(64), cmpActor()))
        ->toThrow(ValidationException::class);
});

it('maps every evidence type to a compression profile — no upload can bypass the pipeline', function () {
    $map = config('lab_workflow_uploads.type_profiles');
    $profiles = config('lab_workflow_uploads.profiles');

    foreach (LabWorkflowEvidence::TYPES as $type) {
        expect($map)->toHaveKey($type)
            ->and($profiles)->toHaveKey($map[$type]);
    }
});

it('persists the size-before/size-after audit metadata on every stored photo', function () {
    Storage::fake('local');
    $binary = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

    $evidence = cmpService()->storePhoto(cmpOrder(), LabWorkflowEvidence::TYPE_SPK_PHOTO, cmpUpload($binary, 'spk.png'), cmpActor());

    expect($evidence->original_file_size)->toBe(strlen($binary))
        ->and($evidence->compression_method)->not->toBeNull()
        ->and($evidence->checksum)->toBe(hash('sha256', Storage::disk('local')->get($evidence->file_path)))
        ->and($evidence->file_size)->toBe(strlen(Storage::disk('local')->get($evidence->file_path)));

    Storage::disk('local')->assertExists($evidence->file_path);
});

// ---------------------------------------------------------------------------
// Compression pipeline (GD runtimes — CI + VPS; skipped honestly elsewhere)
// ---------------------------------------------------------------------------

it('compresses a large photo below the hard cap and the profile long edge', function () {
    Storage::fake('local');
    $binary = cmpGdJpeg(2600, 2000);

    $evidence = cmpService()->storePhoto(cmpOrder(), LabWorkflowEvidence::TYPE_MODEL_PHOTO_BRANCH, cmpUpload($binary), cmpActor());

    $stored = Storage::disk('local')->get($evidence->file_path);
    $info = getimagesizefromstring($stored);

    expect($evidence->compression_method)->toBe(LabEvidenceImageOptimizer::METHOD_GD_ADAPTIVE)
        ->and($evidence->mime_type)->toBe('image/jpeg')
        ->and($evidence->file_size)->toBeLessThan(strlen($binary))
        ->and($evidence->file_size)->toBeLessThanOrEqual(1024 * 1024)
        ->and($info)->not->toBeFalse()
        ->and(max($info[0], $info[1]))->toBeLessThanOrEqual(1800);
})->skip(fn () => ! extension_loaded('gd'), CMP_NO_GD);

it('re-encodes PNG photos to the canonical JPEG output', function () {
    Storage::fake('local');
    $binary = cmpGdPng(2200, 1400);

    $evidence = cmpService()->storePhoto(cmpOrder(), LabWorkflowEvidence::TYPE_SPK_PHOTO, cmpUpload($binary, 'spk.png'), cmpActor());

    expect($evidence->mime_type)->toBe('image/jpeg')
        ->and(str_ends_with($evidence->file_path, '.jpg'))->toBeTrue()
        ->and(getimagesizefromstring(Storage::disk('local')->get($evidence->file_path)))->not->toBeFalse();
})->skip(fn () => ! extension_loaded('gd'), CMP_NO_GD);

it('never enlarges an already-small clean JPEG', function () {
    Storage::fake('local');
    $binary = cmpGdJpeg(320, 240, 60, false);

    $evidence = cmpService()->storePhoto(cmpOrder(), LabWorkflowEvidence::TYPE_PICKUP_PHOTO, cmpUpload($binary), cmpActor());

    expect($evidence->file_size)->toBeLessThanOrEqual(strlen($binary));
})->skip(fn () => ! extension_loaded('gd'), CMP_NO_GD);

it('applies EXIF orientation and strips all EXIF metadata from the output', function () {
    Storage::fake('local');
    $binary = cmpSpliceExifOrientation(cmpGdJpeg(400, 200, 90, false), 6);

    expect((int) (exif_read_data('data://image/jpeg;base64,'.base64_encode($binary))['Orientation'] ?? 0))->toBe(6);

    $evidence = cmpService()->storePhoto(cmpOrder(), LabWorkflowEvidence::TYPE_DELIVERY_LOCATION_PHOTO, cmpUpload($binary), cmpActor());

    $stored = Storage::disk('local')->get($evidence->file_path);
    $info = getimagesizefromstring($stored);
    $outExif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($stored));

    // Orientation 6 = 90° rotation → dimensions swap; EXIF must be gone.
    expect([$info[0], $info[1]])->toBe([200, 400])
        ->and($outExif['Orientation'] ?? null)->toBeNull();
})->skip(fn () => ! (extension_loaded('gd') && function_exists('exif_read_data')), CMP_NO_GD);

it('neutralizes polyglot payloads by re-encoding', function () {
    Storage::fake('local');
    $binary = cmpGdPng(600, 400).'<?php system($_GET[0]); ?>';

    $evidence = cmpService()->storePhoto(cmpOrder(), LabWorkflowEvidence::TYPE_MODEL_PHOTO_BRANCH, cmpUpload($binary, 'poly.png'), cmpActor());

    expect(str_contains(Storage::disk('local')->get($evidence->file_path), '<?php'))->toBeFalse();
})->skip(fn () => ! extension_loaded('gd'), CMP_NO_GD);

it('keeps signatures as sharp transparent PNG within the canvas cap', function () {
    Storage::fake('local');
    $binary = cmpGdPng(2400, 1200, transparent: true);

    $evidence = cmpService()->storePng(cmpOrder(), LabWorkflowEvidence::TYPE_RECIPIENT_SIGNATURE, $binary, cmpActor());

    $stored = Storage::disk('local')->get($evidence->file_path);
    $info = getimagesizefromstring($stored);
    $img = imagecreatefromstring($stored);
    $alpha = (imagecolorat($img, 5, 5) & 0x7F000000) >> 24;

    expect($evidence->mime_type)->toBe('image/png')
        ->and($info[0])->toBeLessThanOrEqual(1200)
        ->and($info[1])->toBeLessThanOrEqual(600)
        ->and($evidence->file_size)->toBeLessThanOrEqual(300 * 1024)
        ->and($alpha)->toBe(127); // transparency preserved — no black background
})->skip(fn () => ! extension_loaded('gd'), CMP_NO_GD);

it('keeps a small signature untouched instead of enlarging it', function () {
    Storage::fake('local');
    $binary = cmpGdPng(300, 150, transparent: true);

    $evidence = cmpService()->storePng(cmpOrder(), LabWorkflowEvidence::TYPE_COURIER_SIGNATURE, $binary, cmpActor());

    expect($evidence->file_size)->toBeLessThanOrEqual(strlen($binary));
})->skip(fn () => ! extension_loaded('gd'), CMP_NO_GD);

it('compresses every photo evidence type through the same pipeline', function () {
    Storage::fake('local');
    $photoTypes = [
        LabWorkflowEvidence::TYPE_SPK_PHOTO,
        LabWorkflowEvidence::TYPE_MODEL_PHOTO_BRANCH,
        LabWorkflowEvidence::TYPE_PICKUP_PHOTO,
        LabWorkflowEvidence::TYPE_PRE_DELIVERY_HANDOVER_PHOTO,
        LabWorkflowEvidence::TYPE_DELIVERY_LOCATION_PHOTO,
    ];
    $binary = cmpGdJpeg(2400, 1800);
    $order = cmpOrder();

    foreach ($photoTypes as $type) {
        $evidence = cmpService()->storePhoto($order, $type, cmpUpload($binary), cmpActor());

        expect($evidence->compression_method)->toBe(LabEvidenceImageOptimizer::METHOD_GD_ADAPTIVE)
            ->and($evidence->file_size)->toBeLessThan($evidence->original_file_size)
            ->and($evidence->file_size)->toBeLessThanOrEqual(1024 * 1024);
    }
})->skip(fn () => ! extension_loaded('gd'), CMP_NO_GD);
