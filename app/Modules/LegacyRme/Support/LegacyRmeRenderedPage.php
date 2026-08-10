<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

/**
 * LEGACY-RME-PDF-1B — one rendered page produced by the rasterizer.
 *
 * Paths point into the job's private TEMPORARY directory; the processing
 * service is what moves the bytes onto the private disk and then removes the
 * temporary directory.
 */
final class LegacyRmeRenderedPage
{
    private function __construct(
        public readonly int $pageNumber,
        public readonly string $imagePath,
        public readonly ?string $thumbnailPath,
        public readonly int $width,
        public readonly int $height,
        public readonly int $dpi,
        public readonly string $format,
    ) {}

    public static function make(
        int $pageNumber,
        string $imagePath,
        ?string $thumbnailPath,
        int $width,
        int $height,
        int $dpi,
        string $format = 'png',
    ): self {
        return new self($pageNumber, $imagePath, $thumbnailPath, $width, $height, $dpi, $format);
    }
}
