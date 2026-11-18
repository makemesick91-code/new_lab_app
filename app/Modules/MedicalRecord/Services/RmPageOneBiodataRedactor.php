<?php

namespace App\Modules\MedicalRecord\Services;

use App\Support\Storage\ClinicalEvidenceStorage;
use GdImage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.1 — PR C2 (rule 243 §3): an RM page 1
 * as a Doctor may see it.
 *
 * Since Hotfix 60.5 (97ffaaa8, 2026-06-26) the workspace canvas (900 × 1273)
 * bakes the patient's biodata grid into page 1, so a page saved with it before
 * rule 243 §3 holds the address, the phone/WhatsApp number and the email in its
 * pixels. This blanks, in a COPY of the bytes, the grid cells that a new page 1
 * leaves empty (PatientIdentityPresenter::rmPageOneBiodata()): marital status
 * and address (left, rows 4–5) and religion, phone/WhatsApp and email (right,
 * rows 2–4), label and value, each cell filled white inside its grid lines. An
 * old page then reads like a new one; on a new page the cells are already white.
 *
 * Only where the grid is really there (review MEDIUM-2): the six band lines and
 * the separators at x = 150 / 470 / 620, anti-aliased grey accepted. Sprint 60
 * (7502c84d) drew 900 × 1273 pages WITHOUT the grid (writing from y = 80) until
 * Hotfix 60.5, and an older 900 × 500 / 900 × 1100 page re-saved after it covers
 * the grid with its own image; those carry clinical ink where the cells would
 * be, and are returned unchanged, as is an image of another shape.
 *
 * The stored object is never written. Bounded like a handwriting payload before
 * GD decodes it.
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

    /**
     * The PNG with the blanked cells filled white; the bytes unchanged when the
     * page has no biodata grid (off-template shape, or the grid absent); null
     * when it is not a decodable, bounded PNG.
     */
    public function redact(string $png): ?string
    {
        $opened = $this->open($png);

        if ($opened === null) {
            return null;
        }

        [$image, $width, $height] = $opened;

        if ($image === null || ! $this->hasGrid($image, $width)) {
            return $png;
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

    /**
     * Rule 243 §3 (review MEDIUM-1) — does the stored page 1 still show, in the
     * hidden cells of a real biodata grid, anything a doctor is not served? A
     * doctor's editor only ever holds the redacted copy, so saving it over this
     * page would erase those fields for everyone: MedicalRecordHandwritingService
     * refuses that save, and the workspace mirrors it. A missing object, an
     * undecodable one or a page without the grid shows nothing hidden; GD absent
     * or a storage fault answers true (fail closed: the page stays as it is).
     */
    public function storedPageShowsHiddenCells(?string $path): bool
    {
        try {
            $bytes = $this->read($path);
        } catch (Throwable $e) {
            Log::warning('An RM page 1 could not be read to check its biodata band.', ['type' => $e::class]);

            return true;
        }

        if ($bytes === null) {
            return false;
        }

        if (! extension_loaded('gd')) {
            return true;
        }

        $opened = $this->open($bytes);

        if ($opened === null || $opened[0] === null || ! $this->hasGrid($opened[0], $opened[1])) {
            return false;
        }

        [$image, $width, $height] = $opened;

        foreach (self::rectanglesFor($width, $height) as [$x1, $y1, $x2, $y2]) {
            for ($y = $y1; $y <= $y2; $y++) {
                for ($x = $x1; $x <= $x2; $x++) {
                    if (self::isInk(imagecolorat($image, $x, $y))) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * The Hotfix 60.5 grid: each of the six horizontal band lines dark at five
     * of six sample columns, each separator at x = 150 / 470 / 620 dark at four
     * of the five row middles (scaled by width / 900; a pixel either side of the
     * line accepted, so a 1 px anti-aliased stroke counts).
     */
    public function hasGrid(GdImage $image, int $width): bool
    {
        $scale = $width / self::TEMPLATE_WIDTH;
        $height = imagesy($image);

        for ($row = 0; $row <= 5; $row++) {
            $y = (int) round((self::BAND_TOP + $row * self::ROW_HEIGHT) * $scale);
            $hits = 0;

            foreach ([20, 75, 300, 545, 760, 880] as $x) {
                $hits += self::darkAround($image, (int) round($x * $scale), $y, 0, 1, $width, $height) ? 1 : 0;
            }

            if ($hits < 5) {
                return false;
            }
        }

        foreach ([150, 470, 620] as $x) {
            $hits = 0;

            foreach ([110, 138, 166, 194, 222] as $y) {
                $hits += self::darkAround($image, (int) round($x * $scale), (int) round($y * $scale), 1, 0, $width, $height) ? 1 : 0;
            }

            if ($hits < 4) {
                return false;
            }
        }

        return true;
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

    /**
     * The decoded, bounded, truecolour image with its size; [null, w, h] for a
     * valid PNG of another shape; null when it is not a decodable, bounded PNG.
     *
     * @return array{0: ?GdImage, 1: int, 2: int}|null
     */
    private function open(string $png): ?array
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
            return [null, $width, $height];
        }

        $image = @imagecreatefromstring($png);

        if ($image === false) {
            return null;
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        return [$image, $width, $height];
    }

    /** A dark pixel at ($x, $y) or one step either side along ($dx, $dy). */
    private static function darkAround(GdImage $image, int $x, int $y, int $dx, int $dy, int $width, int $height): bool
    {
        foreach ([-1, 0, 1] as $step) {
            $px = $x + $step * $dx;
            $py = $y + $step * $dy;

            if ($px >= 0 && $py >= 0 && $px < $width && $py < $height && self::isInk(imagecolorat($image, $px, $py))) {
                return true;
            }
        }

        return false;
    }

    /** Mostly opaque and darker than light grey (grid strokes, text, handwriting). */
    private static function isInk(int $argb): bool
    {
        if ((($argb >> 24) & 0x7F) > 63) {
            return false;
        }

        $luminance = (299 * (($argb >> 16) & 0xFF) + 587 * (($argb >> 8) & 0xFF) + 114 * ($argb & 0xFF)) / 1000;

        return $luminance < 200;
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
