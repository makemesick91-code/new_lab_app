<?php

namespace App\Modules\Satusehat\Exceptions;

use RuntimeException;

/**
 * Thrown when a SATUSEHAT OAuth token cannot be acquired. The message is always
 * a safe, credential-free summary — it never carries the token, the secret, or a
 * raw upstream response.
 */
class SatusehatTokenException extends RuntimeException {}
