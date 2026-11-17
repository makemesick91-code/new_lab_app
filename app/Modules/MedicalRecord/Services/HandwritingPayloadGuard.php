<?php

declare(strict_types=1);

namespace App\Modules\MedicalRecord\Services;

use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 (rule 237 §5) — the bounds on what a submitted
 * RM handwriting page may cost the server: the handwriting counterpart of rule
 * 228 §10, which bounded the prescription and consent canvases but not this one.
 *
 * The page canvas is a fixed 900 x 1273 RGBA canvas exported as PNG. Its worst
 * case — every pixel different, stored uncompressed — is about 4.6 MB of PNG,
 * about 6.1M base64 characters. Every bound sits above that, so no page the
 * application draws is ever refused, and a refusal happens before any write, so
 * the saved page stays as it was.
 *
 * Before: no payload limit, an IHDR size taken on trust (the blank check
 * allocates a scanline buffer from it) and an unbounded inflate, so a small
 * crafted PNG could make one request allocate far more memory than any page.
 *
 * What a malformed-but-small image means for the page (blank or not) is the
 * blank check of decodeCanvas().
 *
 * Rule 240 §8 (memo M3.3 PR6) — decodeCanvas() is the one canvas decoder: the
 * handwriting save and the amendment correction page both call it (it moved here
 * from MedicalRecordHandwritingController unchanged). The correction page is also
 * held to assertCleanPng() (a chunk allowlist, every chunk at its exact size,
 * IDAT consecutive, the image data decoding to exactly the declared image with
 * nothing after its zlib stream, nothing after IEND — so nothing rides along
 * outside the image) and, once stored, read back
 * only through storedPng() / intactPng() (its SHA-256 verified; a read fault told
 * apart from tampering). The IHDR must be 13 bytes on every path (review INFO).
 */
class HandwritingPayloadGuard
{
    public const MAX_HANDWRITING_PAYLOAD_LENGTH = 8_000_000;

    public const MAX_HANDWRITING_DIMENSION = 2048;

    /** 2048 x 1024, about 1.8x the 900 x 1273 page canvas. */
    public const MAX_HANDWRITING_PIXELS = 2_097_152;

    public const MESSAGE_TOO_LARGE = 'Ukuran gambar tulisan tangan melebihi batas yang diizinkan.';

    public const MESSAGE_INVALID = 'Data tulisan tangan tidak valid atau kosong.';

    public const MESSAGE_BLANK = 'Tulisan tangan kosong tidak dapat disimpan dan tidak akan menghapus tulisan tangan yang sudah ada.';

    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /**
     * Review L1 — the ONLY chunks an amendment page may carry: the critical ones,
     * and the colour-space, density and transparency chunks a browser canvas
     * export (toDataURL) can emit. Anything else — text, private or unknown
     * chunks, whatever their case bits — could carry a payload no drawing needs.
     */
    private const ALLOWED_CHUNKS = ['IHDR', 'PLTE', 'IDAT', 'IEND', 'sRGB', 'gAMA', 'cHRM', 'pHYs', 'iCCP', 'tRNS'];

    /** An embedded colour profile, bounded (a canvas profile is a few hundred bytes). */
    private const ICCP_MAX_BYTES = 65536;

    /** What that profile may inflate to. */
    private const ICCP_MAX_INFLATED_BYTES = 1_048_576;

    /**
     * Re-review LOW-1 — the exact data length of each fixed-size chunk (PNG
     * §11.2–11.3); PLTE, tRNS and iCCP are bounded by their own rules below.
     */
    private const FIXED_CHUNK_LENGTHS = ['IHDR' => 13, 'IEND' => 0, 'sRGB' => 1, 'gAMA' => 4, 'cHRM' => 32, 'pHYs' => 9];

    /** Re-review LOW-2 — samples per pixel and the bit depths PNG allows, per colour type (PNG §11.2.2). */
    private const COLOUR_TYPES = [
        0 => [1, [1, 2, 4, 8, 16]],
        2 => [3, [8, 16]],
        3 => [1, [1, 2, 4, 8]],
        4 => [2, [8, 16]],
        6 => [4, [8, 16]],
    ];

    /** Review L2 — what reading a stored amendment page found. */
    public const STORED_OK = 'ok';

    public const STORED_MISSING = 'missing';

    public const STORED_UNREADABLE = 'unreadable';

    public const STORED_TAMPERED = 'tampered';

    /**
     * Decode, validate and blank-guard a submitted canvas (a `data:image/png`
     * URI). Returns the raw PNG bytes or throws a ValidationException keyed
     * `handwriting_data` (Sprint 59 / 59.1 semantics, rule 237 §5 bounds). Nothing
     * is written by this method; the caller writes only what it returns.
     */
    public function decodeCanvas(?string $raw): string
    {
        // Rule 237 §5 — the payload, then the size the PNG declares and what its
        // data inflates to, are bounded before the blank check allocates anything.
        $this->assertPayloadLength($raw);

        // Strip the data URI prefix if present (data:image/png;base64,...)
        $base64 = preg_replace('/^data:image\/\w+;base64,/', '', (string) $raw);

        $decoded = base64_decode((string) $base64, strict: true);

        // Must decode successfully and have valid PNG magic bytes
        if ($decoded === false || strlen($decoded) < 8 || substr($decoded, 0, 4) !== "\x89PNG") {
            throw ValidationException::withMessages(['handwriting_data' => self::MESSAGE_INVALID]);
        }

        $this->assertBoundedPng($decoded);

        // Sprint 59.1 — never overwrite saved handwriting with an accidental
        // blank canvas. A fully transparent / uniform PNG is rejected before
        // any storage or DB write, so previously saved strokes are preserved.
        if ($this->isBlankCanvas($decoded)) {
            throw ValidationException::withMessages(['handwriting_data' => self::MESSAGE_BLANK]);
        }

        return $decoded;
    }

    /**
     * Rule 240 §8 — the structure of a PNG that will be kept as an amendment page,
     * so nothing rides along outside the image: the signature; a 13-byte IHDR
     * first, declaring a valid colour type and bit depth, compression and filter
     * method 0 and NO interlacing (a canvas export is never interlaced); every
     * chunk complete with a valid CRC and on the ALLOWLIST (review L1); every
     * chunk but IDAT at most once and at its exact size (re-review LOW-1: IEND 0,
     * sRGB 1, gAMA 4, cHRM 32, pHYs 9, PLTE a multiple of 3 up to 768 and only
     * in a palette image, tRNS the size its colour type defines, an iCCP a
     * profile name and ONE zlib stream ending at the chunk's end); the IDAT
     * chunks consecutive, then only IEND,
     * then nothing; and the image data ONE zlib stream that ends exactly where
     * the IDAT data ends and inflates to exactly the declared image, every
     * scanline starting with a filter type 0–4 (re-review LOW-2 — so a page that
     * cannot be decoded is refused, never sealed). A canvas export passes.
     *
     * The palette and the pixels are image content — any byte value there is a
     * colour — so the page is also served as `image/png` with `nosniff`, never
     * read as markup. Runs on the amendment path only, never the ordinary save.
     */
    public function assertCleanPng(string $png): void
    {
        $length = strlen($png);

        if ($length < 8 || substr($png, 0, 8) !== self::PNG_SIGNATURE) {
            throw ValidationException::withMessages(['handwriting_data' => self::MESSAGE_INVALID]);
        }

        $offset = 8;
        $seen = [];
        $header = null;
        $idat = '';

        while ($offset + 12 <= $length) {
            $chunkLength = unpack('N', substr($png, $offset, 4))[1];
            $type = substr($png, $offset + 4, 4);

            if ($chunkLength > $length - $offset - 12
                || ! in_array($type, self::ALLOWED_CHUNKS, true)
                || (($seen === []) !== ($type === 'IHDR'))
                || ($type !== 'IDAT' && isset($seen[$type]))
                // IDAT chunks follow one another (PNG §5.6) and every allowed
                // ancillary chunk precedes them: after the image data, only IEND.
                || (isset($seen['IDAT']) && $type !== 'IDAT' && $type !== 'IEND')
                || unpack('N', substr($png, $offset + 8 + $chunkLength, 4))[1] !== crc32($type.substr($png, $offset + 8, $chunkLength))) {
                throw ValidationException::withMessages(['handwriting_data' => self::MESSAGE_INVALID]);
            }

            $data = substr($png, $offset + 8, $chunkLength);

            if ($type === 'IHDR') {
                $header = $this->imageHeader($data);
            }

            if ($header === null || ! $this->chunkDataFits($type, $data, $header)) {
                throw ValidationException::withMessages(['handwriting_data' => self::MESSAGE_INVALID]);
            }

            if ($type === 'IDAT') {
                $idat .= $data;
            }

            $seen[$type] = true;
            $offset += 12 + $chunkLength;

            if ($type === 'IEND') {
                if ($offset !== $length
                    || $idat === ''
                    || ($header['colour_type'] === 3 && ! isset($seen['PLTE']))
                    || $this->inflatedLength($idat, $header['image_bytes'], $header['scanline_bytes']) !== $header['image_bytes']) {
                    break;
                }

                return;
            }
        }

        throw ValidationException::withMessages(['handwriting_data' => self::MESSAGE_INVALID]);
    }

    /**
     * Re-review LOW-2 — an IHDR this path can decode: the declared colour type and
     * bit depth a valid pair, compression and filter method 0, not interlaced;
     * then what the image data must inflate to. Null when it is not.
     *
     * @return array{colour_type: int, scanline_bytes: int, image_bytes: int}|null
     */
    private function imageHeader(string $data): ?array
    {
        if (strlen($data) !== 13) {
            return null;
        }

        ['width' => $width, 'height' => $height, 'depth' => $depth, 'colour' => $colour, 'compression' => $compression, 'filter' => $filter, 'interlace' => $interlace]
            = unpack('Nwidth/Nheight/Cdepth/Ccolour/Ccompression/Cfilter/Cinterlace', $data);

        if ($width < 1 || $height < 1 || ! isset(self::COLOUR_TYPES[$colour]) || ! in_array($depth, self::COLOUR_TYPES[$colour][1], true)
            || $compression !== 0 || $filter !== 0 || $interlace !== 0) {
            return null;
        }

        $scanline = 1 + intdiv($width * self::COLOUR_TYPES[$colour][0] * $depth + 7, 8);

        return ['colour_type' => $colour, 'scanline_bytes' => $scanline, 'image_bytes' => $height * $scanline];
    }

    /**
     * Re-review LOW-1 — a chunk's data holds exactly what its type defines, so no
     * payload rides inside an allowed chunk.
     *
     * @param  array{colour_type: int, scanline_bytes: int, image_bytes: int}  $header
     */
    private function chunkDataFits(string $type, string $data, array $header): bool
    {
        $length = strlen($data);

        if (isset(self::FIXED_CHUNK_LENGTHS[$type])) {
            return $length === self::FIXED_CHUNK_LENGTHS[$type];
        }

        return match ($type) {
            // PNG §11.2.3: 1–256 entries; only where the image needs it (a palette
            // image) — beside RGB(A) samples it is a hint no decoder needs.
            'PLTE' => $length >= 3 && $length <= 768 && $length % 3 === 0 && $header['colour_type'] === 3,
            // PNG §11.3.2.1: one grey or RGB sample, or an alpha per palette entry;
            // never with an alpha channel.
            'tRNS' => match ($header['colour_type']) {
                0 => $length === 2,
                2 => $length === 6,
                3 => $length >= 1 && $length <= 256,
                default => false,
            },
            'iCCP' => $length <= self::ICCP_MAX_BYTES && $this->colourProfileFits($data),
            default => true,
        };
    }

    /**
     * PNG §11.3.3.3: a profile name of 1–79 printable Latin-1 bytes, a NUL,
     * compression method 0, then one zlib stream ending at the chunk's end.
     */
    private function colourProfileFits(string $data): bool
    {
        $nul = strpos($data, "\0");

        return $nul !== false && $nul >= 1 && $nul <= 79
            && preg_match('/^[\x20-\x7E\xA1-\xFF]+$/', substr($data, 0, $nul)) === 1
            && substr($data, $nul + 1, 1) === "\0"
            && $this->inflatedLength(substr($data, $nul + 2), self::ICCP_MAX_INFLATED_BYTES) !== null;
    }

    /**
     * The length $compressed inflates to when it is ONE complete zlib stream that
     * ends exactly at its last byte (nothing after it) and inflates to at most
     * $limit bytes; with $scanline > 0, every scanline must also start with a PNG
     * filter type 0–4. Null otherwise. Measured piece by piece, so a stream is
     * never held past $limit.
     */
    private function inflatedLength(string $compressed, int $limit, int $scanline = 0): ?int
    {
        $context = $compressed === '' ? false : @inflate_init(ZLIB_ENCODING_DEFLATE);

        if ($context === false) {
            return null;
        }

        $total = strlen($compressed);
        $fed = 0;
        $inflated = 0;

        foreach (str_split($compressed, 8192) as $piece) {
            $output = @inflate_add($context, $piece, ZLIB_SYNC_FLUSH);

            if ($output === false || $inflated + strlen($output) > $limit) {
                return null;
            }

            if ($scanline > 0) {
                for ($row = intdiv($inflated + $scanline - 1, $scanline) * $scanline; $row < $inflated + strlen($output); $row += $scanline) {
                    if (ord($output[$row - $inflated]) > 4) {
                        return null;
                    }
                }
            }

            $inflated += strlen($output);
            $fed += strlen($piece);

            if (inflate_get_status($context) === ZLIB_STREAM_END) {
                // The stream ends here: any byte after its end is a payload.
                return $fed === $total && inflate_get_read_len($context) === $total ? $inflated : null;
            }
        }

        // The stream never ended: truncated image data.
        return null;
    }

    /**
     * Rule 240 §8 — a stored amendment page read back for display or apply: the
     * bytes when the object exists on the private clinical disk, is a PNG and its
     * SHA-256 equals the recorded one; otherwise null (absent, unreadable or
     * tampered — never served, never applied). An inline data URI is not an
     * object and answers null. Logs carry no key.
     */
    public static function intactPng(?string $key, ?string $sha256): ?string
    {
        return self::storedPng($key, $sha256)['bytes'];
    }

    /**
     * Review L2 — the same read, saying what it found: `ok` (the bytes), `missing`
     * (no key, no object or no recorded digest), `unreadable` (the storage read
     * failed — a fault to retry, not evidence of tampering) or `tampered` (not a
     * PNG, or its SHA-256 differs from the recorded one). Logs carry no key.
     *
     * @return array{status: string, bytes: ?string}
     */
    public static function storedPng(?string $key, ?string $sha256): array
    {
        if ($key === null || $key === '' || ClinicalEvidenceStorage::isInlineDataUri($key)
            || ! is_string($sha256) || preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            return ['status' => self::STORED_MISSING, 'bytes' => null];
        }

        try {
            $disk = ClinicalEvidenceStorage::disk();
            if (! $disk->exists($key)) {
                return ['status' => self::STORED_MISSING, 'bytes' => null];
            }

            $bytes = $disk->get($key);
        } catch (Throwable $e) {
            Log::warning('A stored clinical evidence object could not be read.', ['type' => $e::class]);

            return ['status' => self::STORED_UNREADABLE, 'bytes' => null];
        }

        if (! is_string($bytes)) {
            return ['status' => self::STORED_UNREADABLE, 'bytes' => null];
        }

        if (! str_starts_with($bytes, self::PNG_SIGNATURE) || ! hash_equals($sha256, hash('sha256', $bytes))) {
            Log::warning('A stored clinical evidence object failed its SHA-256 integrity check.');

            return ['status' => self::STORED_TAMPERED, 'bytes' => null];
        }

        return ['status' => self::STORED_OK, 'bytes' => $bytes];
    }

    /** The submitted data URI, before anything is decoded or allocated from it. */
    public function assertPayloadLength(?string $raw): void
    {
        if (strlen((string) $raw) > self::MAX_HANDWRITING_PAYLOAD_LENGTH) {
            throw ValidationException::withMessages(['handwriting_data' => self::MESSAGE_TOO_LARGE]);
        }
    }

    /**
     * Refuse a PNG whose IHDR (which the format requires to be the first chunk)
     * declares more than an RM page may be, that carries a second IHDR (the blank
     * check reads every IHDR it meets), or whose image data inflates past what the
     * declared size can hold (8 bytes per pixel, the widest PNG pixel, plus one
     * filter byte per row).
     */
    public function assertBoundedPng(string $png): void
    {
        // The IHDR holds exactly 13 bytes; a shorter one let the blank check read
        // past its end (an "Uninitialized string offset" — a 500, review INFO).
        if (strlen($png) < 33 || substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n" || substr($png, 12, 4) !== 'IHDR'
            || unpack('N', substr($png, 8, 4))[1] !== 13) {
            throw ValidationException::withMessages(['handwriting_data' => self::MESSAGE_INVALID]);
        }

        $width = unpack('N', substr($png, 16, 4))[1];
        $height = unpack('N', substr($png, 20, 4))[1];

        if ($width < 1 || $height < 1) {
            throw ValidationException::withMessages(['handwriting_data' => self::MESSAGE_INVALID]);
        }

        if ($width > self::MAX_HANDWRITING_DIMENSION
            || $height > self::MAX_HANDWRITING_DIMENSION
            || $width * $height > self::MAX_HANDWRITING_PIXELS) {
            throw ValidationException::withMessages(['handwriting_data' => self::MESSAGE_TOO_LARGE]);
        }

        $idat = '';
        $headers = 0;
        $offset = 8;
        $length = strlen($png);

        while ($offset + 12 <= $length) {
            $chunkLength = unpack('N', substr($png, $offset, 4))[1];
            $type = substr($png, $offset + 4, 4);

            if ($type === 'IHDR') {
                $headers++;
            } elseif ($type === 'IDAT') {
                $idat .= substr($png, $offset + 8, $chunkLength);
            } elseif ($type === 'IEND') {
                break;
            }

            $offset += 12 + $chunkLength;
        }

        if ($headers !== 1) {
            throw ValidationException::withMessages(['handwriting_data' => self::MESSAGE_INVALID]);
        }

        if ($this->inflatesBeyond($idat, $height * (1 + $width * 8))) {
            throw ValidationException::withMessages(['handwriting_data' => self::MESSAGE_TOO_LARGE]);
        }
    }

    /**
     * Detect a blank handwriting submission (a fully transparent canvas or a
     * single uniform colour) so an accidental empty save never overwrites
     * previously stored handwriting. Pure-PHP PNG inspection — GD/Imagick are
     * not required, only zlib (always present). Only the 8-bit RGBA output of
     * canvas.toDataURL() is inspected; anything else is treated as non-blank to
     * avoid false rejections of legitimate content.
     */
    private function isBlankCanvas(string $png): bool
    {
        if (strlen($png) < 8 || substr($png, 0, 8) !== self::PNG_SIGNATURE) {
            return false;
        }

        $offset = 8;
        $width = $height = $bitDepth = $colorType = null;
        $idat = '';

        while ($offset + 12 <= strlen($png)) {
            $length = unpack('N', substr($png, $offset, 4))[1];
            $type = substr($png, $offset + 4, 4);
            $data = substr($png, $offset + 8, $length);

            if ($type === 'IHDR') {
                $width = unpack('N', substr($data, 0, 4))[1];
                $height = unpack('N', substr($data, 4, 4))[1];
                $bitDepth = ord($data[8]);
                $colorType = ord($data[9]);
            } elseif ($type === 'IDAT') {
                $idat .= $data;
            } elseif ($type === 'IEND') {
                break;
            }

            $offset += 12 + $length;
        }

        if ($colorType !== 6 || $bitDepth !== 8 || $width === null || $width <= 0 || $height <= 0) {
            return false;
        }

        // Bounded by the declared size, which assertBoundedPng() capped.
        $raw = @zlib_decode($idat, $height * (1 + $width * 8));
        if ($raw === false || $raw === '') {
            return false;
        }

        $bytesPerPixel = 4;
        $stride = $width * $bytesPerPixel;
        $previous = array_fill(0, $stride, 0);
        $position = 0;
        $allTransparent = true;
        $uniform = true;
        $firstPixel = null;

        for ($y = 0; $y < $height; $y++) {
            if ($position >= strlen($raw)) {
                return false;
            }

            $filter = ord($raw[$position++]);
            $line = array_values(unpack('C*', substr($raw, $position, $stride)));

            if (count($line) < $stride) {
                return false;
            }

            $position += $stride;

            // Reverse the PNG scanline filter so we read true pixel values.
            for ($x = 0; $x < $stride; $x++) {
                $a = $x >= $bytesPerPixel ? $line[$x - $bytesPerPixel] : 0;
                $b = $previous[$x];
                $c = $x >= $bytesPerPixel ? $previous[$x - $bytesPerPixel] : 0;

                switch ($filter) {
                    case 0:
                        break;
                    case 1:
                        $line[$x] = ($line[$x] + $a) & 0xFF;
                        break;
                    case 2:
                        $line[$x] = ($line[$x] + $b) & 0xFF;
                        break;
                    case 3:
                        $line[$x] = ($line[$x] + intdiv($a + $b, 2)) & 0xFF;
                        break;
                    case 4:
                        $p = $a + $b - $c;
                        $pa = abs($p - $a);
                        $pb = abs($p - $b);
                        $pc = abs($p - $c);
                        $predictor = ($pa <= $pb && $pa <= $pc) ? $a : (($pb <= $pc) ? $b : $c);
                        $line[$x] = ($line[$x] + $predictor) & 0xFF;
                        break;
                    default:
                        return false;
                }
            }

            for ($x = 0; $x < $stride; $x += $bytesPerPixel) {
                $pixel = [$line[$x], $line[$x + 1], $line[$x + 2], $line[$x + 3]];

                if ($pixel[3] !== 0) {
                    $allTransparent = false;
                }

                if ($firstPixel === null) {
                    $firstPixel = $pixel;
                } elseif ($pixel !== $firstPixel) {
                    $uniform = false;
                }

                // Any non-transparent, non-uniform pixel means real content.
                if (! $allTransparent && ! $uniform) {
                    return false;
                }
            }

            $previous = $line;
        }

        return $allTransparent || $uniform;
    }

    /**
     * Whether the image data inflates to more than $limit bytes, measured
     * incrementally so the whole output is never held at once (one 8 KiB piece
     * inflates to at most about 8.4 MB). Corrupt data simply stops the
     * measurement.
     */
    private function inflatesBeyond(string $compressed, int $limit): bool
    {
        if ($compressed === '') {
            return false;
        }

        $context = @inflate_init(ZLIB_ENCODING_DEFLATE);

        if ($context === false) {
            return false;
        }

        $inflated = 0;

        foreach (str_split($compressed, 8192) as $piece) {
            $output = @inflate_add($context, $piece, ZLIB_SYNC_FLUSH);

            if ($output === false) {
                return false;
            }

            $inflated += strlen($output);

            if ($inflated > $limit) {
                return true;
            }
        }

        return false;
    }
}
