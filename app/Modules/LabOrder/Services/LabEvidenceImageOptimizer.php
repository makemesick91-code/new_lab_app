<?php

namespace App\Modules\LabOrder\Services;

use App\Modules\LabOrder\Support\OptimizedEvidenceImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * FIX-LAB-...-UPLOAD-COMPRESSION — canonical adaptive image compression for
 * ALL Lab Workflow evidence (photos + signatures). GD-based, no new vendor.
 *
 * Pipeline: decode (bomb-guarded) → EXIF auto-orient → resize to the profile's
 * long edge → encode (photos: JPEG; signatures: PNG, alpha preserved) →
 * bounded iterations lowering quality then dimensions until the hard cap
 * fits. Re-encoding strips ALL metadata (EXIF/GPS) and neutralizes polyglot
 * payloads. Output is decode-verified before it is accepted.
 *
 * Fail-closed rules:
 *  - undecodable/corrupt input          → ValidationException
 *  - output above hard cap after bounds → ValidationException (never blurred
 *    below min_quality, never an endless loop)
 *  - output that cannot be re-decoded   → ValidationException
 *
 * Documented fallback (NOT fake compression): when the GD extension itself is
 * absent (some local dev CLIs), the already-validated original is stored
 * unchanged and the evidence row records method `original_no_gd`. CI and the
 * VPS pilot run with GD, so production evidence is always compressed.
 */
class LabEvidenceImageOptimizer
{
    public const METHOD_GD_ADAPTIVE = 'gd_adaptive_v1';

    public const METHOD_ORIGINAL_KEPT = 'original_kept_smaller_v1';

    public const METHOD_NO_GD = 'original_no_gd';

    public const METHOD_UNSUPPORTED_DECODE = 'original_unsupported_decode';

    public function gdAvailable(): bool
    {
        return extension_loaded('gd');
    }

    /**
     * Decompression-bomb guard from header bytes — call BEFORE full decode.
     *
     * @param  array{0:int,1:int}  $imageInfo  getimagesizefromstring() result
     */
    public function assertSafeDimensions(array $imageInfo): void
    {
        $width = (int) ($imageInfo[0] ?? 0);
        $height = (int) ($imageInfo[1] ?? 0);
        $maxDimension = (int) config('lab_workflow_uploads.max_dimension');
        $maxPixels = (int) config('lab_workflow_uploads.max_pixels');

        if ($width < 1 || $height < 1 || $width > $maxDimension || $height > $maxDimension || ($width * $height) > $maxPixels) {
            throw ValidationException::withMessages([
                'file' => 'Dimensi gambar tidak wajar. Unggah foto dengan resolusi normal.',
            ]);
        }
    }

    /**
     * Compress an evidence PHOTO according to its type profile. Output is
     * always JPEG when GD processes it (maximum viewer compatibility).
     */
    public function optimizePhoto(string $binary, string $mime, string $evidenceType): OptimizedEvidenceImage
    {
        $originalSize = strlen($binary);
        $profile = $this->profileFor($evidenceType);

        if (! $this->gdAvailable()) {
            return $this->passthrough($binary, $mime, $originalSize, self::METHOD_NO_GD);
        }

        $image = $this->decode($binary, $mime);

        if ($image === null) {
            if ($mime === 'image/webp' && ! function_exists('imagecreatefromwebp')) {
                // GD without WebP support — store the validated original and
                // record why (never fake a compression that did not happen).
                return $this->passthrough($binary, $mime, $originalSize, self::METHOD_UNSUPPORTED_DECODE);
            }

            throw ValidationException::withMessages([
                'file' => 'File gambar rusak atau tidak dapat diproses.',
            ]);
        }

        [$image, $orientationApplied] = $this->applyExifOrientation($image, $binary, $mime);

        $width = imagesx($image);
        $height = imagesy($image);
        $longEdge = max($width, $height);
        $resized = false;

        if ($longEdge > (int) $profile['max_long_edge']) {
            $image = $this->scaleToLongEdge($image, (int) $profile['max_long_edge']);
            $resized = true;
        }

        // PNG/WebP photos are flattened onto white and re-encoded as JPEG.
        $formatChanged = $mime !== 'image/jpeg';
        $encoded = $this->encodeJpegAdaptive($image, $profile);
        imagedestroy($image);

        if ($encoded === null) {
            throw ValidationException::withMessages([
                'file' => 'Foto terlalu besar untuk dikompresi dengan aman. Unggah ulang dengan resolusi lebih kecil.',
            ]);
        }

        // Verify the output opens again before accepting it.
        $outInfo = @getimagesizefromstring($encoded);
        if ($outInfo === false || ($outInfo['mime'] ?? null) !== 'image/jpeg') {
            throw ValidationException::withMessages([
                'file' => 'Hasil kompresi tidak valid. Coba unggah ulang.',
            ]);
        }

        // Small files are never enlarged: keep the original when it is already
        // smaller AND nothing had to change (no resize/rotation/format change —
        // those must win because they carry privacy/orientation corrections).
        if (strlen($encoded) >= $originalSize && ! $resized && ! $orientationApplied && ! $formatChanged && ! $this->jpegHasExif($binary)) {
            $info = @getimagesizefromstring($binary);

            return new OptimizedEvidenceImage(
                $binary, 'image/jpeg', 'jpg',
                (int) ($info[0] ?? 0) ?: null, (int) ($info[1] ?? 0) ?: null,
                $originalSize, self::METHOD_ORIGINAL_KEPT,
            );
        }

        return new OptimizedEvidenceImage(
            $encoded, 'image/jpeg', 'jpg',
            (int) $outInfo[0], (int) $outInfo[1],
            $originalSize, self::METHOD_GD_ADAPTIVE,
        );
    }

    /**
     * Optimize a signature PNG: cap the canvas, keep transparency, lossless
     * re-encode (level 9). Never converted to JPEG.
     */
    public function optimizeSignaturePng(string $binary): OptimizedEvidenceImage
    {
        $originalSize = strlen($binary);
        $profile = (array) config('lab_workflow_uploads.profiles.signature');

        if (! $this->gdAvailable()) {
            return $this->passthrough($binary, 'image/png', $originalSize, self::METHOD_NO_GD);
        }

        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            throw ValidationException::withMessages([
                'file' => 'Berkas tanda tangan rusak atau tidak dapat diproses.',
            ]);
        }

        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $maxWidth = (int) $profile['max_width'];
        $maxHeight = (int) $profile['max_height'];
        $hardMax = (int) $profile['hard_max_bytes'];
        $maxIterations = (int) $profile['max_iterations'];
        $resized = false;

        if (imagesx($image) > $maxWidth || imagesy($image) > $maxHeight) {
            $ratio = min($maxWidth / imagesx($image), $maxHeight / imagesy($image));
            $image = $this->scalePreservingAlpha($image, (int) round(imagesx($image) * $ratio), (int) round(imagesy($image) * $ratio));
            $resized = true;
        }

        $encoded = $this->encodePng($image);

        // Bounded shrink passes if the signature is still above the hard cap.
        for ($i = 0; $i < $maxIterations && strlen($encoded) > $hardMax; $i++) {
            $image = $this->scalePreservingAlpha($image, (int) round(imagesx($image) * 0.8), (int) round(imagesy($image) * 0.8));
            $encoded = $this->encodePng($image);
            $resized = true;
        }
        imagedestroy($image);

        if (strlen($encoded) > $hardMax) {
            throw ValidationException::withMessages([
                'file' => 'Tanda tangan terlalu besar untuk diproses. Ulangi tanda tangan.',
            ]);
        }

        $outInfo = @getimagesizefromstring($encoded);
        if ($outInfo === false || ($outInfo['mime'] ?? null) !== 'image/png') {
            throw ValidationException::withMessages([
                'file' => 'Hasil pemrosesan tanda tangan tidak valid. Ulangi tanda tangan.',
            ]);
        }

        // PNG carries no EXIF/GPS — keep the smaller original when no resize
        // was needed and re-encoding did not help.
        if (! $resized && strlen($encoded) >= $originalSize) {
            $info = @getimagesizefromstring($binary);

            return new OptimizedEvidenceImage(
                $binary, 'image/png', 'png',
                (int) ($info[0] ?? 0) ?: null, (int) ($info[1] ?? 0) ?: null,
                $originalSize, self::METHOD_ORIGINAL_KEPT,
            );
        }

        return new OptimizedEvidenceImage(
            $encoded, 'image/png', 'png',
            (int) $outInfo[0], (int) $outInfo[1],
            $originalSize, self::METHOD_GD_ADAPTIVE,
        );
    }

    /** @return array<string, int|float> */
    private function profileFor(string $evidenceType): array
    {
        $key = (string) config("lab_workflow_uploads.type_profiles.{$evidenceType}", 'photo');

        return (array) config("lab_workflow_uploads.profiles.{$key}");
    }

    private function passthrough(string $binary, string $mime, int $originalSize, string $method): OptimizedEvidenceImage
    {
        if ($method === self::METHOD_NO_GD) {
            Log::warning('lab-workflow-uploads: GD unavailable — evidence stored without compression', ['mime' => $mime]);
        }

        $info = @getimagesizefromstring($binary);
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };

        return new OptimizedEvidenceImage(
            $binary, $mime, $ext,
            (int) ($info[0] ?? 0) ?: null, (int) ($info[1] ?? 0) ?: null,
            $originalSize, $method,
        );
    }

    private function decode(string $binary, string $mime): ?\GdImage
    {
        if ($mime === 'image/webp' && ! function_exists('imagecreatefromwebp')) {
            return null;
        }

        $image = @imagecreatefromstring($binary);

        return $image === false ? null : $image;
    }

    /**
     * @return array{0: \GdImage, 1: bool} image + whether a rotation/flip was applied
     */
    private function applyExifOrientation(\GdImage $image, string $binary, string $mime): array
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return [$image, false];
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($binary));
        $orientation = (int) ($exif['Orientation'] ?? 1);

        if ($orientation <= 1 || $orientation > 8) {
            return [$image, false];
        }

        $flip = static function (\GdImage $img, int $mode): \GdImage {
            imageflip($img, $mode);

            return $img;
        };
        $rotate = static function (\GdImage $img, float $angle): \GdImage {
            $rotated = imagerotate($img, $angle, 0);
            imagedestroy($img);

            return $rotated ?: $img;
        };

        $image = match ($orientation) {
            2 => $flip($image, IMG_FLIP_HORIZONTAL),
            3 => $rotate($image, 180),
            4 => $flip($image, IMG_FLIP_VERTICAL),
            5 => $flip($rotate($image, -90), IMG_FLIP_HORIZONTAL),
            6 => $rotate($image, -90),
            7 => $flip($rotate($image, 90), IMG_FLIP_HORIZONTAL),
            8 => $rotate($image, 90),
            default => $image,
        };

        return [$image, true];
    }

    private function jpegHasExif(string $binary): bool
    {
        if (! function_exists('exif_read_data')) {
            return false;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($binary));

        return is_array($exif) && (isset($exif['Orientation']) || isset($exif['GPSLatitude']) || isset($exif['Make']) || isset($exif['DateTimeOriginal']));
    }

    private function scaleToLongEdge(\GdImage $image, int $longEdge): \GdImage
    {
        $ratio = $longEdge / max(imagesx($image), imagesy($image));

        return $this->scaleFlattened($image, (int) round(imagesx($image) * $ratio), (int) round(imagesy($image) * $ratio));
    }

    /** Scale for JPEG output: flattens (no alpha) onto white. */
    private function scaleFlattened(\GdImage $image, int $width, int $height): \GdImage
    {
        $target = imagecreatetruecolor(max(1, $width), max(1, $height));
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $image, 0, 0, 0, 0, max(1, $width), max(1, $height), imagesx($image), imagesy($image));
        imagedestroy($image);

        return $target;
    }

    private function scalePreservingAlpha(\GdImage $image, int $width, int $height): \GdImage
    {
        $target = imagecreatetruecolor(max(1, $width), max(1, $height));
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $image, 0, 0, 0, 0, max(1, $width), max(1, $height), imagesx($image), imagesy($image));
        imagedestroy($image);

        return $target;
    }

    /**
     * Bounded adaptive JPEG encode: quality steps down first (never below
     * min_quality), then dimensions shrink — never an endless loop. Returns
     * null when the hard cap still cannot be met (caller rejects).
     */
    private function encodeJpegAdaptive(\GdImage $image, array $profile): ?string
    {
        // Photos with transparency were flattened during scaling; a
        // non-resized PNG may still carry alpha — flatten defensively.
        $image = $this->scaleFlattened($image, imagesx($image), imagesy($image));

        $quality = (int) $profile['quality'];
        $minQuality = (int) $profile['min_quality'];
        $qualityStep = (int) $profile['quality_step'];
        $target = (int) $profile['target_bytes'];
        $hardMax = (int) $profile['hard_max_bytes'];
        $maxIterations = (int) $profile['max_iterations'];
        $minLongEdge = (int) $profile['min_long_edge'];
        $dimensionStep = (float) $profile['dimension_step'];

        $encoded = $this->encodeJpeg($image, $quality);

        for ($i = 0; $i < $maxIterations && strlen($encoded) > $target; $i++) {
            if ($quality - $qualityStep >= $minQuality) {
                $quality -= $qualityStep;
            } elseif (strlen($encoded) > $hardMax && max(imagesx($image), imagesy($image)) > $minLongEdge) {
                // Quality floor reached and still above the HARD cap: shrink.
                $image = $this->scaleFlattened(
                    $image,
                    (int) round(imagesx($image) * $dimensionStep),
                    (int) round(imagesy($image) * $dimensionStep),
                );
            } else {
                break; // above target but within the hard cap at the quality floor — readability wins
            }

            $encoded = $this->encodeJpeg($image, $quality);
        }

        $result = strlen($encoded) > $hardMax ? null : $encoded;
        imagedestroy($image);

        return $result;
    }

    private function encodeJpeg(\GdImage $image, int $quality): string
    {
        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }

    private function encodePng(\GdImage $image): string
    {
        ob_start();
        imagepng($image, null, 9);

        return (string) ob_get_clean();
    }
}
