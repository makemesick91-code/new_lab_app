<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\MassUpload\Support;

use App\Modules\Patient\Models\Patient;

/**
 * Outcome of resolving one manifest medical record number to a patient (§11).
 *
 * Resolution is SERVER-SIDE and must yield exactly one patient. Zero matches
 * and two matches are both refusals, and the two-match case is the one that
 * matters: a mass tool that picked "the first match" would bind a clinical
 * document to a patient nobody chose. There is no defensible tie-break, so
 * ambiguity blocks the row and an operator disambiguates it by hand.
 *
 * The actor is always part of the lookup, so a manifest can only ever reach
 * patients its submitter is already allowed to see. The manifest itself can
 * never name a patient id or a branch — those headers are refused by the
 * parser — which is what keeps this the only path in.
 */
final class LegacyMassUploadPatientResolution
{
    private function __construct(
        public readonly ?Patient $patient,
        public readonly ?string $reasonCode,
        public readonly int $matchCount = 0,
    ) {}

    public static function found(Patient $patient): self
    {
        return new self($patient, null, 1);
    }

    public static function notFound(): self
    {
        return new self(null, LegacyMassUploadReason::PATIENT_NOT_FOUND, 0);
    }

    public static function ambiguous(int $matchCount): self
    {
        return new self(null, LegacyMassUploadReason::PATIENT_AMBIGUOUS, $matchCount);
    }

    public static function notAuthorized(): self
    {
        return new self(null, LegacyMassUploadReason::PATIENT_NOT_AUTHORIZED, 0);
    }

    public static function missingIdentifier(): self
    {
        return new self(null, LegacyMassUploadReason::SOURCE_RM_MISSING, 0);
    }

    public function resolved(): bool
    {
        return $this->patient !== null;
    }
}
