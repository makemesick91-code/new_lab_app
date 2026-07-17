<?php

namespace App\Modules\Satusehat\Services\DataQuality\Rules;

use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Support\SatusehatDataQualityContext;

/**
 * SATUSEHAT-4B — a recorded diagnosis references terminology that is no longer
 * ACTIVE (deprecated/rejected/back-in-review). Historical selections stay
 * readable — this only asks the doctor to re-code with active terminology (or
 * its designated replacement) before the record can be SATUSEHAT-ready.
 */
class DeprecatedDiagnosisSelectedRule extends AbstractDataQualityRule
{
    public function code(): string
    {
        return 'deprecated_diagnosis_selected';
    }

    public function evaluate(SatusehatDataQualityContext $context): array
    {
        $issues = [];

        foreach ($context->diagnoses() as $dx) {
            $master = $dx->clinicalDiagnosis;
            // Synthetic rehearsal entries are lifecycle-exempt (isolated
            // campaign branch only) — never flagged here.
            if ($master === null || in_array($master->status, [ClinicalDiagnosis::STATUS_ACTIVE, ClinicalDiagnosis::STATUS_SYNTHETIC], true)) {
                continue;
            }

            $issues[] = $this->issue(
                SatusehatDataQualityIssue::SEVERITY_SOFT,
                "Diagnosis {$master->code} merujuk terminologi yang tidak lagi aktif (status: {$master->status}).",
                'medical_record_diagnosis',
                (int) $dx->id,
                'clinical_diagnosis_id',
                'Ganti dengan terminologi aktif (atau terminologi pengganti yang ditetapkan reviewer).',
                'Doctor',
                null,
                ['diagnosis_code' => (string) $master->code, 'terminology_status' => (string) $master->status],
            );
        }

        return $issues;
    }
}
