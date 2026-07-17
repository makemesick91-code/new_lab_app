<?php

namespace App\Modules\Satusehat\Services\DataQuality\Rules;

use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Support\SatusehatDataQualityContext;

/**
 * SATUSEHAT-4B — data-integrity guard: a medical record must never carry more
 * than one PRIMARY structured diagnosis. The service layer enforces this on
 * write; this rule catches any historical/imported violation. HARD — can never
 * be waived and resolves only by revalidation.
 */
class DuplicatePrimaryDiagnosisRule extends AbstractDataQualityRule
{
    public function code(): string
    {
        return 'duplicate_primary_diagnosis';
    }

    public function evaluate(SatusehatDataQualityContext $context): array
    {
        $mr = $context->medicalRecord();
        if ($mr === null) {
            return [];
        }

        $primaryCount = $context->diagnoses()
            ->where('diagnosis_role', MedicalRecordDiagnosis::ROLE_PRIMARY)
            ->count();

        if ($primaryCount <= 1) {
            return [];
        }

        return [$this->issue(
            SatusehatDataQualityIssue::SEVERITY_HARD,
            "Rekam medis memiliki {$primaryCount} diagnosis utama — maksimal satu.",
            'medical_record',
            (int) $mr->id,
            'primary_diagnosis',
            'Turunkan diagnosis utama berlebih menjadi sekunder sehingga tersisa tepat satu.',
            'Doctor',
            null,
            ['primary_count' => $primaryCount],
        )];
    }
}
