<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services\Pdf;

use App\Modules\LegacyRme\Interfaces\LegacyRmePdfRasterizerInterface;
use App\Modules\LegacyRme\Support\LegacyRmePdfException;
use App\Modules\LegacyRme\Support\LegacyRmePdfFailure;
use App\Modules\LegacyRme\Support\LegacyRmeProcessRunner;
use App\Modules\LegacyRme\Support\LegacyRmeRasterizationResult;
use App\Modules\LegacyRme\Support\LegacyRmeRenderedPage;

/**
 * LEGACY-RME-PDF-1B — Poppler (`pdftoppm`) implementation of page rendering.
 *
 * Exactly two bounded process calls per document:
 *  1. full-resolution pages at the configured DPI;
 *  2. thumbnails via `-scale-to`.
 *
 * Thumbnails are produced by Poppler itself rather than a PHP image extension,
 * so rendering behaves identically on every environment (the local dev machine
 * has no GD; CI and the VPS do) and the module adds no image dependency.
 *
 * `pdftoppm` names its output `<prefix>-<n>.<ext>`, zero-padding `n` to the
 * width of the page count — so 9 pages give `-1`, and 10 give `-01`. Output is
 * therefore always discovered by scanning the directory and parsing the numeric
 * suffix, never by assuming a filename.
 */
class PopplerLegacyRmePdfRasterizer implements LegacyRmePdfRasterizerInterface
{
    private const PAGE_PREFIX = 'render-page';

    private const THUMBNAIL_PREFIX = 'render-thumb';

    public function __construct(
        private readonly LegacyRmeProcessRunner $processes,
    ) {}

    public function rasterize(string $sourceAbsolutePath, string $temporaryOutputDirectory, int $dpi): LegacyRmeRasterizationResult
    {
        if (! is_file($sourceAbsolutePath) || ! is_readable($sourceAbsolutePath)) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::SOURCE_FILE_MISSING);
        }

        if (! is_dir($temporaryOutputDirectory) || ! is_writable($temporaryOutputDirectory)) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::PDF_STORAGE_FAILED);
        }

        $dpi = $dpi > 0 ? $dpi : 180;

        $this->render([
            $this->binary(),
            '-png',
            '-r', (string) $dpi,
            $sourceAbsolutePath,
            $temporaryOutputDirectory.DIRECTORY_SEPARATOR.self::PAGE_PREFIX,
        ]);

        $pageFiles = $this->collect($temporaryOutputDirectory, self::PAGE_PREFIX);

        if ($pageFiles === []) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::PDF_RENDER_FAILED);
        }

        // Thumbnails are a convenience: a failure here must not lose an
        // otherwise perfectly rendered document, so it degrades to "no
        // thumbnail" instead of failing the import.
        $thumbnailFiles = [];

        try {
            $this->render([
                $this->binary(),
                '-png',
                '-scale-to', (string) $this->thumbnailMaxEdge(),
                $sourceAbsolutePath,
                $temporaryOutputDirectory.DIRECTORY_SEPARATOR.self::THUMBNAIL_PREFIX,
            ]);

            $thumbnailFiles = $this->collect($temporaryOutputDirectory, self::THUMBNAIL_PREFIX);
        } catch (LegacyRmePdfException) {
            $thumbnailFiles = [];
        }

        $pages = [];

        foreach ($pageFiles as $pageNumber => $path) {
            $size = @getimagesize($path);

            // getimagesize() is a core function — it needs no image extension —
            // so this validation runs identically on every environment.
            if ($size === false || ($size['mime'] ?? null) !== 'image/png') {
                throw LegacyRmePdfException::make(LegacyRmePdfFailure::PAGE_IMAGE_INVALID);
            }

            $pages[] = LegacyRmeRenderedPage::make(
                $pageNumber,
                $path,
                $thumbnailFiles[$pageNumber] ?? null,
                (int) $size[0],
                (int) $size[1],
                $dpi,
            );
        }

        return LegacyRmeRasterizationResult::make($pages, $dpi);
    }

    /**
     * @param  list<string>  $command
     */
    private function render(array $command): void
    {
        $result = $this->processes->run($command);

        if ($result['exit_code'] !== 0) {
            throw LegacyRmePdfException::make(LegacyRmePdfFailure::PDF_RENDER_FAILED);
        }
    }

    /**
     * Discover rendered output and normalize it to page numbers.
     *
     * @return array<int, string> page number => absolute path, ascending
     */
    private function collect(string $directory, string $prefix): array
    {
        $matches = glob($directory.DIRECTORY_SEPARATOR.$prefix.'-*.png') ?: [];

        $pages = [];

        foreach ($matches as $path) {
            if (preg_match('/-(\d+)\.png$/', basename($path), $found) !== 1) {
                continue;
            }

            // Zero padding is a Poppler formatting detail; the page number is
            // the integer value.
            $pages[(int) $found[1]] = $path;
        }

        ksort($pages);

        return $pages;
    }

    private function binary(): string
    {
        $binary = config('legacy_rme.processing.pdftoppm_binary', 'pdftoppm');

        return is_string($binary) && $binary !== '' ? $binary : 'pdftoppm';
    }

    private function thumbnailMaxEdge(): int
    {
        $edge = (int) config('legacy_rme.processing.thumbnail_max_edge', 320);

        return $edge > 0 ? $edge : 320;
    }
}
