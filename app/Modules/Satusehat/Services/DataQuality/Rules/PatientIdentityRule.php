<?php

namespace App\Modules\Satusehat\Services\DataQuality\Rules;

use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Support\SatusehatDataQualityContext;

/**
 * Minimum patient identity for SATUSEHAT readiness: name, date of birth,
 * gender, and a medical record number. NIK is deliberately NOT mandated here —
 * it only matters for the future external Patient lookup (SATUSEHAT-2 campaign).
 */
class PatientIdentityRule extends AbstractDataQualityRule
{
    public function code(): string
    {
        return 'patient_identity';
    }

    public function evaluate(SatusehatDataQualityContext $context): array
    {
        $patient = $context->patient();
        if ($patient === null) {
            return [$this->issue(
                SatusehatDataQualityIssue::SEVERITY_HARD,
                'Relasi pasien tidak konsisten pada kunjungan ini.',
                'clinic_visit',
                $context->visit?->id,
                'patient_id',
                'Periksa integritas data kunjungan (hubungi IT Operator).',
                'IT Operator',
            )];
        }

        $issues = [];
        $fields = [
            'name' => [blank($patient->name), 'Nama pasien belum terisi.'],
            'date_of_birth' => [blank($patient->date_of_birth), 'Tanggal lahir pasien belum terisi.'],
            'gender' => [blank($patient->gender), 'Jenis kelamin pasien belum terisi.'],
            'medical_record_number' => [
                blank($patient->medical_record_number) && blank($patient->manual_rm_number),
                'Nomor rekam medis pasien belum tersedia.',
            ],
        ];

        foreach ($fields as $field => [$missing, $message]) {
            if ($missing) {
                $issues[] = $this->issue(
                    SatusehatDataQualityIssue::SEVERITY_SOFT,
                    $message,
                    'patient',
                    (int) $patient->id,
                    $field,
                    'Lengkapi data pasien melalui halaman remediasi pasien.',
                    'Admin Klinik',
                );
            }
        }

        return $issues;
    }
}
