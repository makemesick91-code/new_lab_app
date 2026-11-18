<?php

namespace App\Modules\ClinicalPhoto\Support;

use GdImage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * DMS memo M4.4 / M1.x, PTM PR4 (rule 242 §4) — turns an uploaded clinical
 * photo into the only thing ever stored: a freshly encoded JPEG.
 *
 * Runs BEFORE any write (rule 224 §2). Fail closed at every step:
 *
 *  1. bounded in bytes (MAX_UPLOAD_BYTES);
 *  2. the type is SNIFFED from the bytes (JPEG or PNG magic), never taken from
 *     the file name or the client's MIME claim, and the header must agree;
 *  3. the dimensions are read from the HEADER and bounded (edge and pixel count)
 *     before anything is decoded — the decompression-bomb guard; a JPEG's
 *     markers are walked too, so a datastream of more than MAX_JPEG_SCANS
 *     scans (a progressive file of thousands of empty scans costs seconds to
 *     minutes of CPU while staying small) or MAX_JPEG_SEGMENTS segments, with
 *     a cut-off length or with no EOI is refused undecoded;
 *  4. decoded with GD (no GD: refused; the original is never stored instead,
 *     because it may carry GPS) — a JPEG whose decoder reports any recoverable
 *     libjpeg error (a truncated scan, which GD would otherwise fill with grey,
 *     or an inconsistent progression that would mask one) is refused —
 *     then drawn onto a fresh opaque canvas at most
 *     OUTPUT_LONG_EDGE on its long edge, the EXIF orientation applied, and
 *     encoded as JPEG. Only pixels cross that boundary, so EXIF, GPS, comments
 *     and any payload appended to a polyglot are gone;
 *  5. the output must decode again as a JPEG.
 *
 * Not final: a test decorates normalize() to act between the unlocked pre-check
 * and the locked decision.
 */
class ClinicalPhotoNormalizer
{
    public const MAX_UPLOAD_BYTES = 15 * 1024 * 1024;

    public const MIN_EDGE = 64;

    /**
     * Owner decision 2026-11-18 ("Raise to 50 MP"): edges up to 10000 px and at
     * most 50 megapixels, read from the header before anything is decoded; the
     * file stays capped at 15 MB and the output at a 2560 px long edge. A 50 MP
     * decode holds about 200 MB of pixels in GD (system libgd, outside PHP's
     * memory_limit): measured 251 MB peak resident and 0.8 s per upload here;
     * the range is about 200–380 MB per worker at the top of the bound.
     */
    public const MAX_EDGE = 10000;

    public const MAX_PIXELS = 50_000_000;

    public const OUTPUT_LONG_EDGE = 2560;

    public const OUTPUT_QUALITY = 85;

    public const OUTPUT_MIME = 'image/jpeg';

    /**
     * Review M-1 — a baseline JPEG has one scan per frame, a progressive one
     * about ten (libjpeg's default progression); 64 leaves room for any real
     * encoder and none for a scan bomb.
     */
    public const MAX_JPEG_SCANS = 64;

    /**
     * Re-review R2-2 — marker segments outside scan data (APPn, COM, DQT, DHT,
     * SOF, SOS…). A camera or editor writes a few dozen; exif_read_data() keeps
     * a copy of every one, so millions of tiny comment segments in a 15 MB file
     * exhaust the worker's memory before anything is decoded.
     */
    public const MAX_JPEG_SEGMENTS = 256;

    public const MESSAGE_REQUIRED = 'Foto klinis wajib diunggah.';

    public const MESSAGE_TOO_LARGE = 'Ukuran foto klinis melebihi batas 15 MB.';

    public const MESSAGE_TYPE = 'Foto klinis harus berupa gambar JPEG atau PNG.';

    public const MESSAGE_DIMENSIONS = 'Dimensi foto klinis tidak wajar: setiap sisi 64–10000 piksel dan paling banyak 50 megapiksel.';

    public const MESSAGE_UNREADABLE = 'Foto klinis rusak atau tidak dapat diproses.';

    public const MESSAGE_UNAVAILABLE = 'Foto klinis tidak dapat diproses di server ini.';

    public const MESSAGE_JPEG_SCANS = 'Struktur berkas JPEG foto klinis tidak wajar (terlalu banyak scan). Simpan ulang foto sebagai JPEG biasa, lalu unggah.';

    public const MESSAGE_INCOMPLETE = 'Foto klinis tidak lengkap (berkas terpotong). Unggah ulang foto aslinya.';

    public const MESSAGE_JPEG_SEGMENTS = 'Struktur berkas JPEG foto klinis tidak wajar (terlalu banyak segmen). Simpan ulang foto sebagai JPEG biasa, lalu unggah.';

    /** Re-review R2-4 — a non-standard but repairable file: the remedy is to save it again. */
    public const MESSAGE_RESAVE = 'Berkas JPEG foto klinis tidak standar. Simpan ulang atau ekspor ulang foto sebagai JPEG dari aplikasi kamera atau galeri, lalu unggah.';

    private const JPEG_MAGIC = "\xFF\xD8\xFF";

    private const PNG_MAGIC = "\x89PNG\r\n\x1a\n";

    /**
     * @return array{bytes: string, width: int, height: int, byte_size: int, sha256: string, original_sha256: string}
     */
    public function normalize(string $binary): array
    {
        $size = strlen($binary);

        if ($size === 0) {
            $this->refuse(self::MESSAGE_REQUIRED);
        }

        if ($size > self::MAX_UPLOAD_BYTES) {
            $this->refuse(self::MESSAGE_TOO_LARGE);
        }

        $type = match (true) {
            str_starts_with($binary, self::JPEG_MAGIC) => IMAGETYPE_JPEG,
            str_starts_with($binary, self::PNG_MAGIC) => IMAGETYPE_PNG,
            default => $this->refuse(self::MESSAGE_TYPE),
        };

        $header = @getimagesizefromstring($binary);

        if ($header === false || ($header[2] ?? null) !== $type) {
            $this->refuse(self::MESSAGE_UNREADABLE);
        }

        $width = (int) $header[0];
        $height = (int) $header[1];

        if ($width < self::MIN_EDGE || $height < self::MIN_EDGE
            || $width > self::MAX_EDGE || $height > self::MAX_EDGE
            || $width * $height > self::MAX_PIXELS) {
            $this->refuse(self::MESSAGE_DIMENSIONS);
        }

        if ($type === IMAGETYPE_JPEG) {
            $structure = $this->jpegStructureRefusal($binary);

            if ($structure !== null) {
                $this->refuse($structure);
            }
        }

        if (! function_exists('imagecreatefromstring') || ! function_exists('imagecreatefromjpeg') || ! function_exists('imagejpeg')) {
            $this->refuse(self::MESSAGE_UNAVAILABLE);
        }

        $orientation = $type === IMAGETYPE_JPEG ? $this->exifOrientation($binary) : 1;
        [$source, $warnings] = $this->decode($binary, $type);

        if (! $source instanceof GdImage) {
            $this->refuse(self::MESSAGE_UNREADABLE);
        }

        // Re-review R2-3 — libgd reports only libjpeg's FIRST recoverable error,
        // so an earlier one (an inconsistent progression) can mask a premature
        // end: any recoverable libjpeg error refuses the JPEG.
        foreach ($type === IMAGETYPE_JPEG ? $warnings : [] as $warning) {
            if (stripos($warning, 'libjpeg') !== false && stripos($warning, 'recoverable error') !== false) {
                imagedestroy($source);
                $this->refuse(stripos($warning, 'premature end') !== false ? self::MESSAGE_INCOMPLETE : self::MESSAGE_RESAVE);
            }
        }

        if (imagesx($source) !== $width || imagesy($source) !== $height) {
            imagedestroy($source);
            $this->refuse(self::MESSAGE_UNREADABLE);
        }

        $scale = min(1, self::OUTPUT_LONG_EDGE / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        // A fresh opaque canvas: transparency is flattened onto white and only
        // pixels are copied, never a metadata segment.
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($source);

        $canvas = $this->orient($canvas, $orientation);
        $bytes = $this->encodeJpeg($canvas);
        $outWidth = imagesx($canvas);
        $outHeight = imagesy($canvas);
        imagedestroy($canvas);

        $check = $bytes === '' ? false : @getimagesizefromstring($bytes);

        if ($check === false || ($check[2] ?? null) !== IMAGETYPE_JPEG || (int) $check[0] !== $outWidth || (int) $check[1] !== $outHeight) {
            $this->refuse(self::MESSAGE_UNREADABLE);
        }

        return [
            'bytes' => $bytes,
            'width' => $outWidth,
            'height' => $outHeight,
            'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'original_sha256' => hash('sha256', $binary),
        ];
    }

    /**
     * Review M-1, re-review R2-1/R2-2/R2-4 — walk a JPEG's markers without
     * decoding it; null when the datastream is acceptable, else the refusal.
     * Segment headers are skipped by their declared length; entropy-coded data
     * by searching for the next real marker (0xFF followed by anything but a
     * stuffed 0x00, a restart marker or a fill byte). Every offset is checked
     * against the length before it is used, so a crafted or cut-off length is
     * a refusal, never an error. Refused: anything but 0xFF where a marker must
     * start (MESSAGE_RESAVE: kept strict, the remedy is to save the photo
     * again); more than MAX_JPEG_SEGMENTS segments or MAX_JPEG_SCANS scans; a
     * walk that runs out of bytes before the EOI (MESSAGE_INCOMPLETE). Bytes
     * after the EOI are ignored here and never reach the stored file.
     */
    private function jpegStructureRefusal(string $binary): ?string
    {
        $length = strlen($binary);
        $offset = 2;
        $segments = 0;
        $scans = 0;

        while ($offset < $length) {
            if ($binary[$offset] !== "\xFF") {
                return self::MESSAGE_RESAVE;
            }

            while ($offset < $length && $binary[$offset] === "\xFF") {
                $offset++;
            }

            if ($offset >= $length) {
                return self::MESSAGE_INCOMPLETE;
            }

            $marker = ord($binary[$offset]);
            $offset++;

            if ($marker === 0xD9) {
                return null;
            }

            if (++$segments > self::MAX_JPEG_SEGMENTS) {
                return self::MESSAGE_JPEG_SEGMENTS;
            }

            // Standalone markers carry no length.
            if (($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
                continue;
            }

            if ($offset + 2 > $length) {
                return self::MESSAGE_INCOMPLETE;
            }

            $segment = (ord($binary[$offset]) << 8) | ord($binary[$offset + 1]);

            if ($segment < 2) {
                return self::MESSAGE_RESAVE;
            }

            $offset += $segment;

            if ($offset > $length) {
                return self::MESSAGE_INCOMPLETE;
            }

            if ($marker !== 0xDA) {
                continue;
            }

            if (++$scans > self::MAX_JPEG_SCANS) {
                return self::MESSAGE_JPEG_SCANS;
            }

            // Entropy-coded data runs to the next real marker.
            while (true) {
                $next = $offset < $length ? strpos($binary, "\xFF", $offset) : false;

                if ($next === false || $next + 1 >= $length) {
                    return self::MESSAGE_INCOMPLETE;
                }

                $follower = ord($binary[$next + 1]);

                if ($follower === 0x00 || ($follower >= 0xD0 && $follower <= 0xD7)) {
                    $offset = $next + 2;

                    continue;
                }

                if ($follower === 0xFF) {
                    $offset = $next + 1;

                    continue;
                }

                $offset = $next;

                break;
            }
        }

        return self::MESSAGE_INCOMPLETE;
    }

    /**
     * Decode with GD, collecting (never displaying) the decoder's warnings.
     *
     * Review L-1 — libjpeg reports a truncated scan ("premature end") only to
     * GD's FILE-based decoder and only while gd.jpeg_ignore_warning is off;
     * imagecreatefromstring() always hides it and GD fills the missing rows
     * with grey. So a JPEG is decoded from a tmpfile() that this method owns
     * and closes (which removes it), with that setting off for this call only.
     *
     * @return array{0: GdImage|false, 1: list<string>}
     */
    private function decode(string $binary, int $type): array
    {
        $warnings = [];
        $handle = null;
        $ignoreWarning = ini_get('gd.jpeg_ignore_warning');

        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            if ($type !== IMAGETYPE_JPEG) {
                return [imagecreatefromstring($binary), $warnings];
            }

            $handle = tmpfile();

            if ($handle === false || fwrite($handle, $binary) !== strlen($binary) || ! fflush($handle)) {
                return [false, $warnings];
            }

            ini_set('gd.jpeg_ignore_warning', '0');
            $image = imagecreatefromjpeg((string) stream_get_meta_data($handle)['uri']);

            return [$image, $warnings];
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            ini_set('gd.jpeg_ignore_warning', $ignoreWarning === false ? '1' : (string) $ignoreWarning);
            restore_error_handler();
        }
    }

    /** EXIF orientation 1–8 of a JPEG (1 when absent, unreadable or out of range). */
    private function exifOrientation(string $binary): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $stream = fopen('php://memory', 'r+b');

        if ($stream === false) {
            return 1;
        }

        try {
            fwrite($stream, $binary);
            rewind($stream);
            $exif = @exif_read_data($stream);
        } catch (Throwable) {
            $exif = false;
        } finally {
            fclose($stream);
        }

        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    /**
     * The transform that shows the picture upright: GD rotates counter-clockwise
     * (orientation 6 — "rotate 90° clockwise to view" — is 270), then a
     * horizontal mirror for the mirrored orientations 2, 4, 5 and 7.
     */
    private function orient(GdImage $image, int $orientation): GdImage
    {
        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => 270,
            7, 8 => 90,
            default => 0,
        };

        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);

            if (! $rotated instanceof GdImage) {
                imagedestroy($image);
                $this->refuse(self::MESSAGE_UNREADABLE);
            }

            imagedestroy($image);
            $image = $rotated;
        }

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        return $image;
    }

    private function encodeJpeg(GdImage $image): string
    {
        ob_start();

        try {
            $ok = imagejpeg($image, null, self::OUTPUT_QUALITY);
        } finally {
            $bytes = (string) ob_get_clean();
        }

        return $ok ? $bytes : '';
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['photo' => $message]);
    }
}
