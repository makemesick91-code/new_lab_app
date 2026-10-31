<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * FEATURE-RME-REFUND-SETTLEMENT-1 (rule 216) — the disbursement proof file: a bank
 * transfer receipt or a signed cash handover acknowledgment.
 *
 * Validated by CONTENT, never by the uploaded name or extension: the MIME type is
 * sniffed from the bytes, a PDF must start with its header and carry no active
 * content (JavaScript, launch actions, embedded files), an image must decode as the
 * PNG or JPEG it claims to be. It is stored on the private disk under a generated
 * name `<settlement uuid>/<sha256>.<ext>` — the uploaded name (which may carry a
 * patient's name) is never kept — and its SHA-256 is recorded so the stored bytes
 * can be proven unchanged and the same file can never settle two refunds.
 *
 * The system cannot read a bank: the file is evidence the settler attests to, not
 * a machine verification of the transfer. The screens say so.
 */
final class RmeRefundSettlementEvidence
{
    public const DISK = 'rme_refund_settlement_evidence';

    public const MAX_KILOBYTES_KEY = 'rme_refund_settlement.evidence.max_kilobytes';

    /** Sniffed MIME type => stored extension. */
    public const MIMES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    /**
     * PDF names whose presence refuses the file: the file is evidence, never a program.
     * A HEURISTIC, not a sanitizer — it is checked on the raw bytes and on every
     * Flate-compressed stream (object streams included), after decoding `#xx` name
     * escapes. Links (/URI) and open-at-page actions are allowed: they are common in
     * bank statements and harmless without one of these action types. The download is
     * always an attachment with a sandbox CSP, whatever this check concludes.
     */
    private const PDF_ACTIVE_NAMES = ['JavaScript', 'JS', 'Launch', 'EmbeddedFile', 'EmbeddedFiles', 'RichMedia', 'XFA', 'SubmitForm', 'ImportData', 'GoToE', 'Encrypt'];

    /** Upper bound of decompressed stream bytes inspected per file (a zip bomb is refused). */
    private const PDF_MAX_INFLATED_BYTES = 16 * 1024 * 1024;

    /** Upper bound of streams inspected per file (more is refused, never skipped). */
    private const PDF_MAX_STREAMS = 4000;

    public static function maxBytes(): int
    {
        $kilobytes = config(self::MAX_KILOBYTES_KEY, 5120);

        return (is_int($kilobytes) && $kilobytes > 0 ? $kilobytes : 5120) * 1024;
    }

    /**
     * The facts of a genuine evidence file, or null when it is not one.
     *
     * @return array{mime: string, extension: string, sha256: string, size: int}|null
     */
    public static function inspect(mixed $file): ?array
    {
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            return null;
        }

        $path = $file->getRealPath();
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return null;
        }

        $size = (int) filesize($path);
        if ($size <= 0 || $size > self::maxBytes()) {
            return null;
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! is_string($mime) || ! isset(self::MIMES[$mime])) {
            return null;
        }

        $bytes = (string) file_get_contents($path);

        if ($mime === 'application/pdf') {
            if (! str_starts_with($bytes, '%PDF-')) {
                return null;
            }
            if (self::pdfHasActiveContent($bytes)) {
                return null;
            }
        } else {
            $info = @getimagesizefromstring($bytes);
            $expected = $mime === 'image/png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;
            if (! is_array($info) || ($info[2] ?? null) !== $expected || (int) ($info[0] ?? 0) <= 0 || (int) ($info[1] ?? 0) <= 0) {
                return null;
            }
        }

        return [
            'mime' => $mime,
            'extension' => self::MIMES[$mime],
            'sha256' => hash('sha256', $bytes),
            'size' => $size,
        ];
    }

    /**
     * Writes the file under its generated name and returns the stored path.
     *
     * @param  array{mime: string, extension: string, sha256: string, size: int}  $facts
     */
    public static function store(string $settlementUuid, UploadedFile $file, array $facts): string
    {
        $name = $facts['sha256'].'.'.$facts['extension'];
        $path = Storage::disk(self::DISK)->putFileAs($settlementUuid, $file, $name);

        if (! is_string($path) || $path === '') {
            throw new \RuntimeException('The settlement evidence could not be stored.');
        }

        // The stored bytes must be the inspected bytes.
        if (hash('sha256', (string) Storage::disk(self::DISK)->get($path)) !== $facts['sha256']) {
            Storage::disk(self::DISK)->delete($path);

            throw new \RuntimeException('The stored settlement evidence does not match the uploaded file.');
        }

        return $path;
    }

    /** Removes a file written by a transaction that did not commit. */
    public static function discard(?string $path): void
    {
        if (is_string($path) && $path !== '') {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /** True when the PDF carries an active-content name, or cannot be inspected safely. */
    private static function pdfHasActiveContent(string $bytes): bool
    {
        if (self::containsActiveName($bytes)) {
            return true;
        }

        $budget = self::PDF_MAX_INFLATED_BYTES;
        $offset = 0;
        $streams = 0;
        while (($start = strpos($bytes, 'stream', $offset)) !== false) {
            $offset = $start + 6;
            // "endstream" also contains "stream": only a keyword followed by EOL opens one.
            if ($start >= 3 && substr($bytes, $start - 3, 3) === 'end') {
                continue;
            }
            $eol = substr($bytes, $offset, 2);
            $dataStart = $eol === "\r\n" ? $offset + 2 : (($eol[0] ?? '') === "\n" ? $offset + 1 : null);
            if ($dataStart === null) {
                continue;
            }
            $end = strpos($bytes, 'endstream', $dataStart);
            if ($end === false) {
                return true;
            }
            if (++$streams > self::PDF_MAX_STREAMS) {
                return true;
            }

            $decoded = self::inflate(substr($bytes, $dataStart, $end - $dataStart), $budget);
            if ($decoded === false) {
                return true; // over the decompression budget
            }
            if ($decoded !== null && self::containsActiveName($decoded)) {
                return true;
            }
            $offset = $end + 9;
        }

        return false;
    }

    private static function containsActiveName(string $text): bool
    {
        // A PDF name may spell any byte as #xx (/J#61vaScript): decode before matching.
        $normalized = (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn (array $m): string => chr((int) hexdec($m[1])), $text);
        $pattern = '#/(?:'.implode('|', self::PDF_ACTIVE_NAMES).')(?![A-Za-z0-9_.\-])#';

        $match = preg_match($pattern, $normalized);

        // A regex engine failure is not a clean bill of health.
        return $match !== 0;
    }

    /**
     * Inflates one zlib (FlateDecode) stream within the remaining budget: the text,
     * null when the stream is not zlib data, false when the budget would be exceeded.
     */
    private static function inflate(string $raw, int &$budget): string|false|null
    {
        $context = inflate_init(ZLIB_ENCODING_DEFLATE);
        if ($context === false) {
            return null;
        }

        $out = '';
        foreach (str_split($raw, 65536) ?: [''] as $chunk) {
            $piece = @inflate_add($context, $chunk, ZLIB_SYNC_FLUSH);
            if ($piece === false) {
                return $out === '' ? null : $out;
            }
            $out .= $piece;
            if (strlen($out) > $budget) {
                return false;
            }
        }

        $budget -= strlen($out);

        return $out === '' ? null : $out;
    }
}
