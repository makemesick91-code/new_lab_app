<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services\Pdf;

use App\Modules\LegacyRme\Interfaces\LegacyRmePdfInspectorInterface;
use App\Modules\LegacyRme\Support\LegacyRmePdfException;
use App\Modules\LegacyRme\Support\LegacyRmePdfFailure;
use App\Modules\LegacyRme\Support\LegacyRmePdfMetadata;
use App\Modules\LegacyRme\Support\LegacyRmeProcessRunner;

/**
 * LEGACY-RME-PDF-1B — Poppler (`pdfinfo`) implementation of PDF inspection.
 *
 * Two bounded passes:
 *  1. a plain `pdfinfo` for page count, encryption and version — cheap, and
 *     enough to refuse an oversized document before doing any more work;
 *  2. `pdfinfo -f 1 -l <pages>` for per-page dimensions, only once the page
 *     count is known to be inside the configured limit.
 *
 * Structure only: no document text and no PDF metadata date is ever read. The
 * legacy RME date is chosen manually by the operator (1A invariant).
 */
class PopplerLegacyRmePdfInspector implements LegacyRmePdfInspectorInterface
{
    public function __construct(
        private readonly LegacyRmeProcessRunner $processes,
    ) {}

    public function inspect(string $absolutePath): LegacyRmePdfMetadata
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::SOURCE_FILE_MISSING);
        }

        $this->assertPdfHeader($absolutePath);

        $summary = $this->runPdfInfo([$absolutePath]);

        $pageCount = $this->parsePageCount($summary);

        if ($pageCount === null || $pageCount < 1) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::PDF_PAGE_COUNT_INVALID);
        }

        $maxPages = $this->maxPages();

        if ($pageCount > $maxPages) {
            throw LegacyRmePdfException::make(
                LegacyRmePdfFailure::PDF_PAGE_LIMIT_EXCEEDED,
                sprintf('Dokumen memiliki %d halaman, melebihi batas %d halaman.', $pageCount, $maxPages),
            );
        }

        if ($this->parseEncrypted($summary)) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::PDF_ENCRYPTED);
        }

        $detail = $this->runPdfInfo(['-f', '1', '-l', (string) $pageCount, $absolutePath]);

        return LegacyRmePdfMetadata::make(
            $pageCount,
            false,
            $this->parseVersion($summary),
            $this->parsePageDimensions($detail, $summary, $pageCount),
        );
    }

    private function assertPdfHeader(string $absolutePath): void
    {
        $handle = @fopen($absolutePath, 'rb');

        if ($handle === false) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::SOURCE_FILE_MISSING);
        }

        $head = (string) fread($handle, 8);
        fclose($handle);

        $magic = (string) config('legacy_rme.upload.pdf_magic', '%PDF-');

        if (! str_starts_with($head, $magic)) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::PDF_HEADER_INVALID);
        }
    }

    /**
     * @param  list<string>  $arguments
     */
    private function runPdfInfo(array $arguments): string
    {
        $result = $this->processes->run(array_merge([$this->binary()], $arguments));

        if ($result['exit_code'] !== 0) {
            $combined = $result['stdout'].$result['stderr'];

            // Poppler reports a locked document either through its permissions
            // exit code (3) or through an explicit password error.
            if (stripos($combined, 'password') !== false) {
                throw LegacyRmePdfException::make(LegacyRmePdfFailure::PDF_PASSWORD_PROTECTED);
            }

            if ($result['exit_code'] === 3) {
                throw LegacyRmePdfException::make(LegacyRmePdfFailure::PDF_ENCRYPTED);
            }

            throw LegacyRmePdfException::make(LegacyRmePdfFailure::PDF_INSPECTION_FAILED);
        }

        return $result['stdout'];
    }

    private function parsePageCount(string $output): ?int
    {
        return preg_match('/^Pages:\s+(\d+)\s*$/mi', $output, $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    private function parseEncrypted(string $output): bool
    {
        if (preg_match('/^Encrypted:\s+(\S+)/mi', $output, $matches) !== 1) {
            return false;
        }

        return strtolower($matches[1]) !== 'no';
    }

    private function parseVersion(string $output): ?string
    {
        return preg_match('/^PDF version:\s+(\S+)\s*$/mi', $output, $matches) === 1
            ? $matches[1]
            : null;
    }

    /**
     * `pdfinfo -f/-l` prints one "Page N size: W x H pts" line per page. When
     * that detail is unavailable, fall back to the document-level "Page size"
     * so a dimension guard is still applied rather than silently skipped.
     *
     * @return array<int, array{page: int, width: float, height: float}>
     */
    private function parsePageDimensions(string $detail, string $summary, int $pageCount): array
    {
        $dimensions = [];

        if (preg_match_all('/^Page\s+(\d+)\s+size:\s+([\d.]+)\s*x\s*([\d.]+)\s*pts/mi', $detail, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $dimensions[] = [
                    'page' => (int) $match[1],
                    'width' => (float) $match[2],
                    'height' => (float) $match[3],
                ];
            }

            return $dimensions;
        }

        if (preg_match('/^Page size:\s+([\d.]+)\s*x\s*([\d.]+)\s*pts/mi', $summary, $match) === 1) {
            for ($page = 1; $page <= $pageCount; $page++) {
                $dimensions[] = [
                    'page' => $page,
                    'width' => (float) $match[1],
                    'height' => (float) $match[2],
                ];
            }
        }

        return $dimensions;
    }

    private function binary(): string
    {
        $binary = config('legacy_rme.processing.pdfinfo_binary', 'pdfinfo');

        return is_string($binary) && $binary !== '' ? $binary : 'pdfinfo';
    }

    private function maxPages(): int
    {
        $max = (int) config('legacy_rme.upload.max_pages', 200);

        return $max > 0 ? $max : 200;
    }
}
