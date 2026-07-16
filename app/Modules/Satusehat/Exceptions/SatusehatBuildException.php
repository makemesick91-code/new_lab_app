<?php

namespace App\Modules\Satusehat\Exceptions;

use RuntimeException;

/**
 * Thrown when a FHIR resource cannot be built because mandatory local data
 * (an IHS identifier, an active mapping, a normalizable period, a structured
 * diagnosis) is missing. The resource stays BLOCKED — never sent with fabricated
 * data. The message is a safe, PII-free reason.
 */
class SatusehatBuildException extends RuntimeException {}
