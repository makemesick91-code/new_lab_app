<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\MassUpload\Exceptions;

use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadReason;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The archive itself is unusable — FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1 §17.
 *
 * This is a PACKAGE-integrity refusal, categorically different from a per-item
 * refusal. A malformed, hostile or unreadable archive tells us nothing
 * trustworthy about the rows inside it, so the batch never begins item dispatch
 * and no clinical lifecycle is created.
 *
 * The distinction is load-bearing for the operator: "your ZIP is broken, fix it
 * and re-upload" is a different instruction from "5 of your 250 patients
 * already have a document". Collapsing the two would send an operator hunting
 * for a data problem when the real problem is the archive.
 *
 * The message carried here is always a canned, operator-safe string from
 * LegacyMassUploadReason — never an exception message, a path, or a ZipArchive
 * error constant.
 */
final class LegacyMassUploadPackageRejected extends RuntimeException
{
    private function __construct(
        public readonly string $reasonCode,
        public readonly string $safeMessage,
        /** @var array<string, scalar|null> */
        public readonly array $context = [],
    ) {
        // The RuntimeException message is the SAFE message on purpose. If this
        // ever escapes to a log or a generic handler, what surfaces is still
        // something an operator could have been shown.
        parent::__construct($safeMessage);
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function because(string $reasonCode, array $context = []): self
    {
        return new self(
            $reasonCode,
            LegacyMassUploadReason::message($reasonCode),
            $context,
        );
    }

    /**
     * Surface as a validation error against the package field, so the operator
     * sees it on the upload form rather than as a 500.
     */
    public function toValidationException(string $field = 'package'): ValidationException
    {
        return ValidationException::withMessages([
            $field => [$this->safeMessage],
        ]);
    }

    /**
     * Audit payload. Context is restricted to scalars by the constructor's
     * type, which keeps a stray object or a file handle out of the trail.
     *
     * @return array<string, scalar|null>
     */
    public function auditContext(): array
    {
        return array_merge($this->context, ['reason_code' => $this->reasonCode]);
    }
}
