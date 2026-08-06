<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Interfaces;

use App\Modules\LegacyRme\Support\LegacyRmePdfException;
use App\Modules\LegacyRme\Support\LegacyRmeRasterizationResult;

/**
 * LEGACY-RME-PDF-1B — rendering a source PDF into per-page images.
 *
 * Implementations MUST:
 *  - write only inside the supplied temporary directory (the caller owns its
 *    lifecycle and always removes it, including on failure);
 *  - emit deterministic, normalized page numbering — never rely on whatever
 *    filenames the underlying renderer happens to produce;
 *  - use argument arrays, never an interpolated shell string;
 *  - be bounded by an explicit timeout;
 *  - throw LegacyRmePdfException with a stable failure code.
 *
 * Rendering NEVER happens inside an HTTP request: only the queued job calls a
 * rasterizer.
 */
interface LegacyRmePdfRasterizerInterface
{
    /**
     * @throws LegacyRmePdfException
     */
    public function rasterize(
        string $sourceAbsolutePath,
        string $temporaryOutputDirectory,
        int $dpi,
    ): LegacyRmeRasterizationResult;
}
