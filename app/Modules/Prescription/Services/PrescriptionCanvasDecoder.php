<?php

namespace App\Modules\Prescription\Services;

use Illuminate\Validation\ValidationException;

class PrescriptionCanvasDecoder
{
    /**
     * DMS-RME-REVISION-PROGRAM-8C (P8R-S5, rule 228 §10) — the bounds on what a
     * submitted canvas may cost the server.
     *
     * The application draws fixed canvases (R/ 900x480, signature 400x140), so
     * the worst case it can produce is about 2.3M base64 characters for R/ and
     * about 0.3M for a signature. The bounds sit above that with headroom and far
     * below what an unbounded field allowed: the decoder held the whole payload,
     * its decoded bytes and — inside the blank check — the fully inflated image
     * in memory, with nothing limiting any of the three.
     *
     * The FormRequests apply the per-field payload limit at the edge; this class
     * applies MAX_PAYLOAD_LENGTH again so a caller that never passes through a
     * request (a job, a command) is bounded the same way (CG-06).
     */
    public const MAX_PAYLOAD_LENGTH = 3_500_000;

    /** The signature field's own, tighter payload limit (FormRequest `max`). */
    public const MAX_SIGNATURE_PAYLOAD_LENGTH = 1_000_000;

    /** The largest width or height a submitted PNG may declare. */
    public const MAX_DIMENSION = 4096;

    /** The largest pixel count a submitted PNG may declare (2048 x 2048). */
    public const MAX_PIXELS = 4_194_304;

    /**
     * Decode a submitted canvas PNG data URI into raw bytes.
     *
     * @throws ValidationException
     */
    public function decode(?string $raw, string $field, bool $allowBlank = false): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        // Before anything is decoded or allocated from the payload.
        if (strlen($raw) > self::MAX_PAYLOAD_LENGTH) {
            throw ValidationException::withMessages([
                $field => 'Ukuran gambar melebihi batas yang diizinkan.',
            ]);
        }

        $base64 = preg_replace('/^data:image\/\w+;base64,/', '', (string) $raw);
        $decoded = base64_decode($base64, strict: true);

        if ($decoded === false || strlen($decoded) < 8 || substr($decoded, 0, 4) !== "\x89PNG") {
            throw ValidationException::withMessages([
                $field => 'Data gambar tidak valid.',
            ]);
        }

        // The size the image DECLARES is checked before it is inflated, so a
        // small, highly compressed payload cannot ask for a huge decode.
        [$width, $height] = $this->declaredSize($decoded) ?? [null, null];

        if ($width === null || $height === null) {
            throw ValidationException::withMessages([
                $field => 'Data gambar tidak valid.',
            ]);
        }

        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION || $width * $height > self::MAX_PIXELS) {
            throw ValidationException::withMessages([
                $field => 'Ukuran gambar melebihi batas yang diizinkan.',
            ]);
        }

        if (! $allowBlank && $this->isBlankPng($decoded)) {
            throw ValidationException::withMessages([
                $field => 'Area gambar kosong tidak dapat disimpan.',
            ]);
        }

        return $decoded;
    }

    /**
     * The width and height of a PNG's IHDR, which the format requires to be the
     * first chunk. Null when it is not, or when either is zero.
     *
     * @return array{0: int, 1: int}|null
     */
    private function declaredSize(string $png): ?array
    {
        if (strlen($png) < 24 || substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n" || substr($png, 12, 4) !== 'IHDR') {
            return null;
        }

        $width = unpack('N', substr($png, 16, 4))[1];
        $height = unpack('N', substr($png, 20, 4))[1];

        return $width > 0 && $height > 0 ? [$width, $height] : null;
    }

    private function isBlankPng(string $png): bool
    {
        if (strlen($png) < 8 || substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") {
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

        // Bounded by the size the header declared (one filter byte per row plus
        // four bytes per pixel), which decode() has already capped: a stream that
        // inflates past it is not the image it claims to be.
        $raw = @zlib_decode($idat, $height * ($width * 4 + 1));
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

            for ($x = 0; $x < $stride; $x++) {
                $a = $x >= $bytesPerPixel ? $line[$x - $bytesPerPixel] : 0;
                $b = $previous[$x];
                $c = $x >= $bytesPerPixel ? $previous[$x - $bytesPerPixel] : 0;

                switch ($filter) {
                    case 0: break;
                    case 1: $line[$x] = ($line[$x] + $a) & 0xFF;
                        break;
                    case 2: $line[$x] = ($line[$x] + $b) & 0xFF;
                        break;
                    case 3: $line[$x] = ($line[$x] + intdiv($a + $b, 2)) & 0xFF;
                        break;
                    case 4:
                        $p = $a + $b - $c;
                        $pa = abs($p - $a);
                        $pb = abs($p - $b);
                        $pc = abs($p - $c);
                        $predictor = ($pa <= $pb && $pa <= $pc) ? $a : (($pb <= $pc) ? $b : $c);
                        $line[$x] = ($line[$x] + $predictor) & 0xFF;
                        break;
                    default: return false;
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

                if (! $allTransparent && ! $uniform) {
                    return false;
                }
            }

            $previous = $line;
        }

        return $allTransparent || $uniform;
    }
}
