<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Exceptions;

use RuntimeException;

/**
 * REVISION-LEGACY-SINGLE-ACTIVE-DOCUMENT-PER-PATIENT-1 — the slot guard could
 * not be armed.
 *
 * This is a PROGRAMMING/ENVIRONMENT fault, never an operator mistake, so it is
 * deliberately NOT a ValidationException: an operator has nothing to fix and
 * must not be shown a form error that implies they do. It fails the request
 * loudly instead of letting an unguarded insert through, because the only thing
 * worse than a 500 here is a duplicate clinical archive.
 */
final class LegacyDocumentSlotLockUnavailable extends RuntimeException
{
    public static function unsupportedType(string $type): self
    {
        return new self(
            'No legacy document slot namespace is registered for import type ['.$type.'].',
        );
    }

    public static function patientIdOutOfRange(int $patientId): self
    {
        return new self(
            'Patient id ['.$patientId.'] is outside the range addressable by a PostgreSQL advisory lock objid.',
        );
    }

    public static function outsideTransaction(): self
    {
        return new self(
            'The legacy document slot must be asserted INSIDE a database transaction: a '
            .'transaction-scoped advisory lock taken outside one is released immediately and '
            .'guards nothing.',
        );
    }
}
