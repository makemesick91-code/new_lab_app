<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services\Pdf;

use App\Modules\LegacyRme\Interfaces\LegacyRmePdfRasterizerInterface;
use App\Modules\LegacyRme\Support\LegacyRmePdfException;
use App\Modules\LegacyRme\Support\LegacyRmeRasterizationResult;
use App\Modules\LegacyRme\Support\LegacyRmeRenderedPage;

/**
 * LEGACY-RME-PDF-1B — deterministic rasterizer for tests.
 *
 * Writes real 1x1 PNG bytes into the supplied temporary directory so the whole
 * downstream pipeline (image validation, hashing, private storage, cleanup)
 * exercises its real code path without requiring Poppler.
 */
class FakeLegacyRmePdfRasterizer implements LegacyRmePdfRasterizerInterface
{
    /** A real, minimal PNG — validated by getimagesize() like any other page. */
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private ?LegacyRmePdfException $failure = null;

    private int $pageCount = 1;

    private bool $emitThumbnails = true;

    public int $calls = 0;

    public function withPages(int $pageCount): self
    {
        $this->pageCount = $pageCount;

        return $this;
    }

    /**
     * Simulate a renderer that produced a different number of pages than the
     * document actually has — the mismatch guard must catch it.
     */
    public function withoutThumbnails(): self
    {
        $this->emitThumbnails = false;

        return $this;
    }

    public function failWith(string $failureCode): self
    {
        $this->failure = LegacyRmePdfException::make($failureCode);

        return $this;
    }

    public function rasterize(string $sourceAbsolutePath, string $temporaryOutputDirectory, int $dpi): LegacyRmeRasterizationResult
    {
        $this->calls++;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        $bytes = (string) base64_decode(self::PNG_1X1, true);
        $pages = [];

        for ($page = 1; $page <= $this->pageCount; $page++) {
            $imagePath = $temporaryOutputDirectory.DIRECTORY_SEPARATOR.'fake-page-'.$page.'.png';
            file_put_contents($imagePath, $bytes);

            $thumbnailPath = null;

            if ($this->emitThumbnails) {
                $thumbnailPath = $temporaryOutputDirectory.DIRECTORY_SEPARATOR.'fake-thumb-'.$page.'.png';
                file_put_contents($thumbnailPath, $bytes);
            }

            $pages[] = LegacyRmeRenderedPage::make($page, $imagePath, $thumbnailPath, 1, 1, $dpi);
        }

        return LegacyRmeRasterizationResult::make($pages, $dpi);
    }
}
