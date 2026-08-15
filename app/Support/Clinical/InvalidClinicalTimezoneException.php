<?php

declare(strict_types=1);

namespace App\Support\Clinical;

use RuntimeException;

/**
 * LEGACY-RME-DATE-TZ-1 — raised when the clinical calendar timezone cannot be
 * resolved to a usable IANA identifier.
 *
 * This is deliberately fatal rather than a fallback. A clinical eligibility
 * decision computed under the wrong calendar is silently wrong forever; a
 * refusal is loud, immediate and correctable. Falling back to UTC here is
 * exactly the defect LEGACY-RME-DATE-TZ-1 exists to remove.
 */
final class InvalidClinicalTimezoneException extends RuntimeException
{
    public static function forValue(mixed $value): self
    {
        $shown = is_string($value) && $value !== ''
            ? $value
            : '(empty)';

        return new self(sprintf(
            'The clinical calendar timezone "%s" is not a valid IANA identifier. '
            .'Set %s to a valid identifier such as "%s". '
            .'Clinical date decisions fail closed rather than fall back to UTC.',
            $shown,
            ClinicalTimezone::ENV_KEY,
            ClinicalTimezone::DEFAULT,
        ));
    }
}
