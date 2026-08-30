<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Support;

use App\Modules\Patient\Models\Patient;

/**
 * BUGFIX-LEGACY-ODONTOGRAM-PATIENT-LOOKUP-1 — the ONLY patient data the upload
 * page is allowed to see.
 *
 * WHY A DTO RATHER THAN THE MODEL. The page previously received a full
 * `Patient`, whose attributes include `ktp_number`, `phone`, `whatsapp_number`,
 * `address` and `date_of_birth`. Nothing rendered them, so nothing leaked — but
 * the restraint lived only in the Blade template, one careless `{{ $patient->…}}`
 * away from disclosing a national identity number on a migration screen.
 *
 * This carries the four fields an operator needs to confirm "yes, that is the
 * patient on this chart" and structurally cannot carry anything else. The
 * least-disclosure rule is enforced by the type, not by discipline.
 */
final readonly class LegacyOdontogramPatientIdentity
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $medicalRecordNumber,
        public string $branchLabel,
    ) {}

    public static function fromPatient(Patient $patient): self
    {
        return new self(
            id: (int) $patient->getKey(),
            name: (string) $patient->name,
            medicalRecordNumber: $patient->medical_record_number !== null
                ? (string) $patient->medical_record_number
                : null,
            branchLabel: $patient->branchLabel(),
        );
    }
}
