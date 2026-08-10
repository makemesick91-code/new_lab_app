<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Interfaces;

use App\Modules\LegacyRme\Support\LegacyRmePdfException;
use App\Modules\LegacyRme\Support\LegacyRmePdfMetadata;

/**
 * LEGACY-RME-PDF-1B — structural inspection of a source PDF.
 *
 * Implementations MUST:
 *  - never interpolate a caller-supplied value into a shell string (argument
 *    arrays only);
 *  - be bounded by an explicit timeout;
 *  - throw LegacyRmePdfException with a stable failure code rather than
 *    leaking a driver exception, a process command line or a path;
 *  - read structure only — never document text, and never a "document date"
 *    (the legacy RME date is always chosen manually by the operator).
 */
interface LegacyRmePdfInspectorInterface
{
    /**
     * @param  string  $absolutePath  A path produced by this application, never by a request.
     *
     * @throws LegacyRmePdfException
     */
    public function inspect(string $absolutePath): LegacyRmePdfMetadata;
}
