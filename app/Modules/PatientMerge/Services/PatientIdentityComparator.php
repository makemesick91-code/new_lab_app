<?php

namespace App\Modules\PatientMerge\Services;

use App\Modules\Patient\Models\Patient;
use App\Modules\PatientMerge\Support\PatientIdentityMask;
use App\Modules\PatientMerge\Support\PatientMergeField;

/**
 * Field-by-field comparison of two patients.
 *
 * MATCH    both present and equal after normalization
 * CONFLICT both present and different
 * MISSING  at least one side empty
 *
 * Returns display values only — masked for KTP/NIK and phone. The raw values
 * never leave the server; the merge reads them from the locked rows.
 */
class PatientIdentityComparator
{
    /**
     * @return array<string, array{field: string, label: string, status: string, critical: bool, sensitive: bool, a_display: ?string, b_display: ?string, a_present: bool, b_present: bool, name_similarity: ?float}>
     */
    public function compare(Patient $a, Patient $b): array
    {
        $rows = [];

        foreach (PatientMergeField::fields() as $field) {
            $aValue = PatientMergeField::normalize($field, $a->getAttribute($field));
            $bValue = PatientMergeField::normalize($field, $b->getAttribute($field));

            $status = match (true) {
                $aValue === null || $bValue === null => PatientMergeField::MISSING,
                $aValue === $bValue => PatientMergeField::MATCH,
                default => PatientMergeField::CONFLICT,
            };

            $rows[$field] = [
                'field' => $field,
                'label' => PatientMergeField::label($field),
                'status' => $status,
                'critical' => PatientMergeField::isCritical($field),
                'sensitive' => PatientMergeField::isSensitive($field),
                'a_display' => PatientIdentityMask::field($field, $a->getAttribute($field)),
                'b_display' => PatientIdentityMask::field($field, $b->getAttribute($field)),
                'a_present' => $aValue !== null,
                'b_present' => $bValue !== null,
                'name_similarity' => $field === 'name' && $aValue !== null && $bValue !== null
                    ? self::similarity($aValue, $bValue)
                    : null,
            ];
        }

        return $rows;
    }

    /**
     * sha256 over both patients' normalized identity. Stored at submission;
     * a mismatch at merge time means somebody edited a patient after the
     * reviewer looked, and the merge refuses.
     */
    public function fingerprint(Patient $a, Patient $b): string
    {
        $parts = [];

        foreach ([$a, $b] as $patient) {
            $parts[] = (string) $patient->id;
            $parts[] = (string) $patient->medical_record_number;

            foreach (PatientMergeField::fields() as $field) {
                $parts[] = (string) PatientMergeField::normalize($field, $patient->getAttribute($field));
            }
        }

        return hash('sha256', implode("\x1f", $parts));
    }

    /** sha256 over ONE patient's normalized identity fields. */
    public function identityHash(Patient $patient): string
    {
        $parts = [];

        foreach (PatientMergeField::fields() as $field) {
            $parts[] = (string) PatientMergeField::normalize($field, $patient->getAttribute($field));
        }

        return hash('sha256', implode("\x1f", $parts));
    }

    /** Similarity 0..1 of two already-normalized names. */
    public static function similarity(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }

        similar_text($a, $b, $percent);

        return round($percent / 100, 2);
    }
}
