<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Exceptions;

use App\Modules\LegacyImport\Support\LegacyDocumentSlotOccupancy;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * REVISION-LEGACY-SINGLE-ACTIVE-DOCUMENT-PER-PATIENT-1 — the race loser.
 *
 * Thrown from INSIDE the intake transaction, after the advisory lock has been
 * taken and the occupancy re-read, when another operator won the slot between
 * the advisory pre-check and here.
 *
 * It is deliberately NOT a ValidationException at the throw site. The intake
 * services wrap their transaction in a compensating `catch` that deletes the
 * bytes already written to disk, and that compensation must run before the
 * operator sees anything. The caller converts this into a ValidationException
 * AFTER the transaction has rolled back and the file has been cleaned up, which
 * is also the only point at which the refusal can be audited durably — an audit
 * row written inside the failing transaction would roll back with it.
 */
final class LegacyDocumentSlotOccupied extends RuntimeException
{
    private function __construct(
        public readonly LegacyDocumentSlotOccupancy $occupancy,
    ) {
        parent::__construct($occupancy->message());
    }

    public static function from(LegacyDocumentSlotOccupancy $occupancy): self
    {
        return new self($occupancy);
    }

    /**
     * The operator-facing form error. `document` is the field every other
     * intake refusal on this path already uses, so the message lands beside the
     * file input the operator was trying to submit.
     */
    public function toValidationException(): ValidationException
    {
        return ValidationException::withMessages([
            'document' => $this->occupancy->message(),
        ]);
    }
}
