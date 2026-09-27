<?php

namespace App\Modules\Patient\Exceptions;

use RuntimeException;

/**
 * REVISION-LEGACY-PATIENT-STAGED-VERIFICATION-CANCEL-1.
 *
 * Thrown from inside the confirmation transaction when the batch may not be
 * imported. Throwing rather than returning is the mechanism that makes the
 * import all-or-nothing: the throw unwinds the surrounding DB::transaction, so
 * every row inserted before the refusal is discarded by the database itself
 * rather than by compensating code that could fail on its own.
 *
 * The reason code is the stable contract (callers branch on it); the message is
 * the operator-facing Indonesian sentence and may be reworded.
 */
class LegacyPatientImportBlockedException extends RuntimeException
{
    /** The batch is not in a state that may be confirmed. */
    public const REASON_NOT_READY = 'not_ready';

    /** At least one row carries a blocking ERROR. */
    public const REASON_ERROR_ROWS = 'error_rows';

    /** The stored source file no longer matches the hash recorded at upload. */
    public const REASON_SOURCE_CHANGED = 'source_changed';

    /** The stored source file is gone, so the reviewed file cannot be re-proven. */
    public const REASON_SOURCE_MISSING = 'source_missing';

    /**
     * Confirm-time revalidation found blocking errors that the preview did not:
     * the database moved while the operator was reviewing.
     */
    public const REASON_REVALIDATION_FAILED = 'revalidation_failed';

    /**
     * @param  array<int, array{row: int, severity: string, message: string}>  $findings
     */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly array $findings = [],
    ) {
        parent::__construct($message);
    }
}
