<?php

namespace App\Modules\Satusehat\Exceptions;

use RuntimeException;

/**
 * Thrown whenever code attempts an external SATUSEHAT operation while the
 * integration is disabled (SATUSEHAT_ENABLED=false, the SATUSEHAT-1 default).
 * Its existence is the runtime guarantee that no network call is ever made.
 */
class SatusehatIntegrationDisabledException extends RuntimeException
{
    public static function forOperation(string $operation): self
    {
        return new self(
            "SATUSEHAT integration is disabled — the operation [{$operation}] cannot run. ".
            'No external request was made. Enable it only in SATUSEHAT-2 after sandbox '.
            'credentials and official FHIR profile validation.'
        );
    }
}
