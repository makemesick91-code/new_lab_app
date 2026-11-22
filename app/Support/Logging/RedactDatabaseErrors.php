<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;

/**
 * DMS-SPN4-READY-PILOT-1 G6 / REM-2b — the logging `tap` every writing channel in
 * config/logging.php carries. A stack channel merges its members' processors, so
 * tapping each member covers the stack too.
 */
final class RedactDatabaseErrors
{
    public function __construct(private readonly DatabaseErrorLogSanitizer $sanitizer) {}

    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(new RedactDatabaseErrorsProcessor($this->sanitizer));
        }
    }
}
