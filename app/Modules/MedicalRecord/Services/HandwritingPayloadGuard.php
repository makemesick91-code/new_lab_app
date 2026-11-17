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
 * held to assertCleanPng() (no polyglot) and, once stored, read back only through
 * intactPng() (its SHA-256 verified).
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

    /** Text chunks carry arbitrary payloads (a script, markup) and no drawing needs one. */
    private const TEXT_CHUNKS = ['tEXt', 'zTXt', 'iTXt'];

    /** The critical chunks a PNG may carry; any other critical chunk is refused. */
    private const CRITICAL_CHUNKS = ['IHDR', 'PLTE', 'IDAT', 'IEND'];

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
     * so nothing but the drawing rides along (no polyglot): the signature, IHDR
     * first, every chunk complete with a valid CRC, no text chunk, no unknown
     * critical chunk, and nothing after IEND. A canvas export always passes.
     */
    public function assertCleanPng(string $png): void
    {
        $length = strlen($png);

        if ($length < 8 || substr($png, 0, 8) !== self::PNG_SIGNATURE) {
            throw ValidationException::withMessages(['handwriting_data' => self::MESSAGE_INVALID]);
        }

        $offset = 8;
        $first = true;

        while ($offset + 12 <= $length) {
            $chunkLength = unpack('N', substr($png, $offset, 4))[1];
            $type = substr($png, $offset + 4, 4);

            if ($chunkLength > $length - $offset - 12
                || preg_match('/^[A-Za-z]{4}$/', $type) !== 1
                || ($first && $type !== 'IHDR')
                || in_array($type, self::TEXT_CHUNKS, true)
                || (ctype_upper($type[0]) && ! in_array($type, self::CRITICAL_CHUNKS, true))
                || unpack('N', substr($png, $offset + 8 + $chunkLength, 4))[1] !== crc32($type.substr($png, $offset + 8, $chunkLength))) {
                throw ValidationException::withMessages(['handwriting_data' => self::MESSAGE_INVALID]);
            }

            $first = false;
            $offset += 12 + $chunkLength;

            if ($type === 'IEND') {
                if ($offset !== $length) {
                    break;
                }

                return;
            }
        }

        throw ValidationException::withMessages(['handwriting_data' => self::MESSAGE_INVALID]);
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
        if ($key === null || $key === '' || ClinicalEvidenceStorage::isInlineDataUri($key)
            || ! is_string($sha256) || preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            return null;
        }

        try {
            $disk = ClinicalEvidenceStorage::disk();
            if (! $disk->exists($key)) {
                return null;
            }

            $bytes = (string) $disk->get($key);
        } catch (Throwable $e) {
            Log::warning('A stored clinical evidence object could not be read.', ['type' => $e::class]);

            return null;
        }

        if (! str_starts_with($bytes, self::PNG_SIGNATURE) || ! hash_equals($sha256, hash('sha256', $bytes))) {
            Log::warning('A stored clinical evidence object failed its SHA-256 integrity check.');

            return null;
        }

        return $bytes;
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
        if (strlen($png) < 33 || substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n" || substr($png, 12, 4) !== 'IHDR') {
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
