<?php

declare(strict_types=1);

namespace App\Modules\MedicalRecord\Services;

use Illuminate\Validation\ValidationException;

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
 * What a malformed-but-small image means for the page (blank or not) stays the
 * controller's existing blank check; this class only bounds the cost.
 */
class HandwritingPayloadGuard
{
    public const MAX_HANDWRITING_PAYLOAD_LENGTH = 8_000_000;

    public const MAX_HANDWRITING_DIMENSION = 2048;

    /** 2048 x 1024, about 1.8x the 900 x 1273 page canvas. */
    public const MAX_HANDWRITING_PIXELS = 2_097_152;

    public const MESSAGE_TOO_LARGE = 'Ukuran gambar tulisan tangan melebihi batas yang diizinkan.';

    public const MESSAGE_INVALID = 'Data tulisan tangan tidak valid atau kosong.';

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
