<?php

namespace App\Modules\Satusehat\Services\DataQuality\Rules;

use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Support\SatusehatDataQualityContext;

/**
 * A final medical record should carry at least one structured PRIMARY
 * diagnosis for SATUSEHAT Condition readiness. Legacy records are flagged —
 * never blocked, never auto-coded from free text.
 */
class StructuredDiagnosisRule extends AbstractDataQualityRule
{
    public function code(): string
    {
        return 'structured_diagnosis';
    }

    public function evaluate(SatusehatDataQualityContext $context): array
    {
        $mr = $context->medicalRecord();
        if ($mr === null) {
            return [];
        }

        $diagnoses = $context->diagnoses();

        if ($diagnoses->isEmpty()) {
            return [$this->issue(
                SatusehatDataQualityIssue::SEVERITY_SOFT,
                'Rekam medis belum memiliki diagnosis terstruktur.',
                'medical_record',
                (int) $mr->id,
                'diagnoses',
                'Dokter mencatat diagnosis terstruktur pada halaman rekam medis.',
                'Doctor',
            )];
        }

        $hasPrimary = $diagnoses->contains(
            fn (MedicalRecordDiagnosis $dx) => $dx->diagnosis_role === MedicalRecordDiagnosis::ROLE_PRIMARY
        );

        if (! $hasPrimary) {
            return [$this->issue(
                SatusehatDataQualityIssue::SEVERITY_SOFT,
                'Diagnosis terstruktur ada tetapi belum memiliki diagnosis utama (primary).',
                'medical_record',
                (int) $mr->id,
                'primary_diagnosis',
                'Tetapkan satu diagnosis utama pada rekam medis.',
                'Doctor',
            )];
        }

        return [];
    }
}
