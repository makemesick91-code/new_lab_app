<?php

namespace App\Modules\MedicalRecord\Services;

use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.1 — PR C2 (rule 243 §3): an RM page 1
 * as a Doctor may see it.
 *
 * Since Hotfix 60.5 (2026-06-26) the workspace canvas (900 × 1273) bakes the
 * patient's biodata grid into page 1, so a page saved before rule 243 §3 holds
 * the address, the phone/WhatsApp number and the email in its pixels. This
 * blanks, in a COPY of the bytes, the grid cells that a new page 1 leaves empty
 * (PatientIdentityPresenter::rmPageOneBiodata()): marital status and address
 * (left, rows 4–5) and religion, phone/WhatsApp and email (right, rows 2–4),
 * label and value, each cell filled white inside its grid lines. An old page
 * then reads like a new one; on a new page the cells are already white.
 *
 * The stored object is never written. An image of another shape predates the
 * 900 × 1273 template (Sprint 60 drew 900 × 1100 without a biodata grid) and is
 * returned unchanged. Bounded like a handwriting payload before GD decodes it.
 */
final class RmPageOneBiodataRedactor
{
    public const STATUS_OK = 'ok';

    public const STATUS_MISSING = 'missing';

    /** The bytes are not a decodable, bounded PNG: nothing is served to a doctor. */
    public const STATUS_UNREDACTABLE = 'unredactable';

    /** GD is not loaded, or the storage read failed: a server fault to retry (fail closed). */
    public const STATUS_UNAVAILABLE = 'unavailable';

    public const TEMPLATE_WIDTH = 900;

    public const TEMPLATE_HEIGHT = 1273;

    /** The biodata band of the Hotfix 60.5 template: header 96 px, five rows of 28 px. */
    public const BAND_TOP = 96;

    public const ROW_HEIGHT = 28;

    /** label | value | label | value */
    public const COLUMNS = [0, 150, 470, 620, 900];

    /** [row, column] of every blanked cell (rows and columns from 0). */
    public const BLANKED_CELLS = [
        [3, 0], [3, 1], [4, 0], [4, 1],
        [1, 2], [1, 3], [2, 2], [2, 3], [3, 2], [3, 3],
    ];

    /** Pixels kept inside each cell, so the grid lines survive. */
    public const INSET = 2;

    /**
     * The stored page 1 (an object key or a legacy inline data URI), redacted.
     *
     * @return array{status: string, bytes: ?string}
     */
    public function redactStored(?string $path): array
    {
        try {
            $bytes = $this->read($path);
        } catch (Throwable $e) {
            // The clinical disk throws on a backend fault: retried (503), never
            // reported as a missing page.
            Log::warning('An RM page 1 could not be read to redact it for a doctor.', ['type' => $e::class]);

            return ['status' => self::STATUS_UNAVAILABLE, 'bytes' => null];
        }

        if ($bytes === null) {
            return ['status' => self::STATUS_MISSING, 'bytes' => null];
        }

        if (! extension_loaded('gd')) {
            Log::warning('An RM page 1 was not served to a doctor: GD is not available to redact its biodata band.');

            return ['status' => self::STATUS_UNAVAILABLE, 'bytes' => null];
        }

        $redacted = $this->redact($bytes);

        if ($redacted === null) {
            Log::warning('An RM page 1 was not served to a doctor: its image could not be redacted.');

            return ['status' => self::STATUS_UNREDACTABLE, 'bytes' => null];
        }

        return ['status' => self::STATUS_OK, 'bytes' => $redacted];
    }

    /** The PNG with the blanked cells filled white, the bytes unchanged off-template, or null. */
    public function redact(string $png): ?string
    {
        $size = @getimagesizefromstring($png);

        if ($size === false || ($size[2] ?? null) !== IMAGETYPE_PNG) {
            return null;
        }

        [$width, $height] = [(int) $size[0], (int) $size[1]];

        if ($width < 1 || $height < 1
            || $width > HandwritingPayloadGuard::MAX_HANDWRITING_DIMENSION
            || $height > HandwritingPayloadGuard::MAX_HANDWRITING_DIMENSION
            || $width * $height > HandwritingPayloadGuard::MAX_HANDWRITING_PIXELS) {
            return null;
        }

        if (! self::isTemplateShaped($width, $height)) {
            return $png;
        }

        $image = @imagecreatefromstring($png);

        if ($image === false) {
            return null;
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);
        $white = imagecolorallocatealpha($image, 255, 255, 255, 0);

        foreach (self::rectanglesFor($width, $height) as [$x1, $y1, $x2, $y2]) {
            imagefilledrectangle($image, $x1, $y1, $x2, $y2, $white);
        }

        ob_start();

        try {
            $written = imagepng($image);
        } catch (Throwable) {
            $written = false;
        } finally {
            $out = ob_get_clean();
        }

        return $written && is_string($out) && $out !== '' ? $out : null;
    }

    /** The 900 × 1273 template, or the same page scaled (height within a pixel). */
    public static function isTemplateShaped(int $width, int $height): bool
    {
        return abs($height - (int) round($width * self::TEMPLATE_HEIGHT / self::TEMPLATE_WIDTH)) <= 1;
    }

    /**
     * The blanked cells as inclusive [x1, y1, x2, y2] rectangles on an image
     * $width wide (the template scaled by $width / 900), clipped to the image.
     *
     * @return list<array{0: int, 1: int, 2: int, 3: int}>
     */
    public static function rectanglesFor(int $width, int $height): array
    {
        $scale = $width / self::TEMPLATE_WIDTH;
        $rectangles = [];

        foreach (self::BLANKED_CELLS as [$row, $column]) {
            $top = self::BAND_TOP + $row * self::ROW_HEIGHT;
            $x1 = (int) round((self::COLUMNS[$column] + self::INSET) * $scale);
            $x2 = (int) round((self::COLUMNS[$column + 1] - self::INSET) * $scale);
            $y1 = (int) round(($top + self::INSET) * $scale);
            $y2 = (int) round(($top + self::ROW_HEIGHT - self::INSET) * $scale);

            if ($y1 < $height) {
                $rectangles[] = [$x1, $y1, min($x2, $width - 1), min($y2, $height - 1)];
            }
        }

        return $rectangles;
    }

    private function read(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (ClinicalEvidenceStorage::isInlineDataUri($path)) {
            $comma = strpos($path, ',');
            $binary = $comma === false ? '' : (string) base64_decode(substr($path, $comma + 1), true);

            return $binary === '' ? null : $binary;
        }

        $disk = ClinicalEvidenceStorage::disk();

        if (! $disk->exists($path)) {
            return null;
        }

        $bytes = $disk->get($path);

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }
}
