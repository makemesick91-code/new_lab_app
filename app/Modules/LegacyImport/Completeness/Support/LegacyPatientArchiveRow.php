<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Completeness\Support;

/**
 * FEATURE-LEGACY-PATIENT-DOCUMENT-COMPLETENESS-1 — one row of the completeness
 * report.
 *
 * IMMUTABLE, AND DELIBERATELY NARROW. The page renders this object and nothing
 * else, so the set of properties here IS the disclosure boundary. It carries
 * exactly what an operator needs to find the patient and decide what to upload:
 * the Nomor RM, the name, the branch, the two document states and the verdict.
 *
 * WHAT IS ABSENT IS THE POINT. No KTP/NIK, no address, no phone, no WhatsApp
 * number, no e-mail, no date of birth, no occupation, no clinical content, no
 * document title or description, no storage path and no checksum. A patient
 * NAME and Nomor RM are disclosed because they are already the established
 * identity pair on every authorized patient surface in this system
 * (see PatientSelectorSearchService, which returns id/name/medical_record_number/
 * branch_label to the same class of operator); everything else on the patient
 * row stays on the server. A test asserts the property set so a future field
 * cannot be added without the assertion failing.
 *
 * NOT AN AUTHORIZATION OBJECT. The branch label is here to disambiguate a
 * patient, never to prove anything. Scope was decided before this row was
 * built, and `patient_id` is carried only so a future row action can address
 * the patient — it grants nothing on its own.
 */
final class LegacyPatientArchiveRow
{
    /**
     * @param  string  $rmeState  one of {@see LegacyArchiveDocumentState::ALL}
     * @param  string|null  $rmeRawStatus  the canonical staging status behind an
     *                                     IN_PROGRESS state, for a precise label
     * @param  string  $odontogramState  one of {@see LegacyArchiveDocumentState::ALL}
     * @param  string  $completeness  one of {@see LegacyArchiveCompleteness::ALL}
     */
    public function __construct(
        public readonly int $patientId,
        public readonly ?string $medicalRecordNumber,
        public readonly string $name,
        public readonly ?string $branchCode,
        public readonly ?string $branchName,
        public readonly string $rmeState,
        public readonly ?string $rmeRawStatus,
        public readonly bool $rmeReviewBlocked,
        public readonly ?string $rmeArchiveDate,
        public readonly string $odontogramState,
        public readonly ?string $odontogramRawStatus,
        public readonly bool $odontogramReviewBlocked,
        public readonly ?string $odontogramArchiveDate,
        public readonly string $completeness,
        public readonly ?int $importBatchId,
        public readonly ?string $registeredAt,
    ) {}

    /**
     * The operator-facing branch label, or an explicit marker when the patient
     * carries no branch.
     *
     * A branchless legacy patient is a real data state (the column is nullable
     * and pre-dates the Cabang requirement), and it is reported rather than
     * hidden — such a row is only ever visible to the governance tier anyway,
     * because an unresolvable provenance branch cannot satisfy a pinned scope.
     */
    public function branchLabel(): string
    {
        if ($this->branchCode === null) {
            return 'Tanpa cabang';
        }

        return $this->branchName === null
            ? $this->branchCode
            : $this->branchCode.' — '.$this->branchName;
    }

    public function medicalRecordLabel(): string
    {
        return ($this->medicalRecordNumber ?? '') !== '' ? (string) $this->medicalRecordNumber : 'Belum ada RM';
    }
}
