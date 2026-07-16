<?php

namespace App\Modules\Satusehat\Services\DataQuality\Rules;

use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Support\SatusehatDataQualityContext;

/**
 * Practitioner readiness: the handling doctor must exist and be active
 * (internal, fixable now); the Practitioner IHS identifier is an EXTERNAL item
 * (requires the credential campaign) and is surfaced as info, never fabricated.
 */
class PractitionerReadinessRule extends AbstractDataQualityRule
{
    public function code(): string
    {
        return 'practitioner_readiness';
    }

    public function evaluate(SatusehatDataQualityContext $context): array
    {
        $issues = [];
        $doctor = $context->doctor();

        if ($context->hasReason('practitioner_missing') || $doctor === null) {
            $issues[] = $this->issue(
                SatusehatDataQualityIssue::SEVERITY_SOFT,
                'Dokter penanggung jawab kunjungan belum tersedia.',
                'clinic_visit',
                $context->visit?->id,
                'doctor_id',
                'Tetapkan dokter penanggung jawab pada kunjungan.',
                'Admin Klinik',
            );
        } elseif (! (bool) $doctor->is_active) {
            $issues[] = $this->issue(
                SatusehatDataQualityIssue::SEVERITY_SOFT,
                'Dokter penanggung jawab berstatus nonaktif.',
                'doctor',
                (int) $doctor->id,
                'is_active',
                'Verifikasi status dokter pada master dokter.',
                'Supervisor RME',
            );
        }

        if ($context->hasReason('practitioner_ihs_missing')) {
            $issues[] = $this->issue(
                SatusehatDataQualityIssue::SEVERITY_INFO,
                'IHS Practitioner belum tersedia — menunggu kampanye kredensial eksternal.',
                'doctor',
                $doctor?->id,
                'practitioner_ihs',
                'Diblokir eksternal: butuh kredensial SATUSEHAT (SATUSEHAT-2).',
                'IT Operator',
                null,
                ['external' => true],
            );
        }

        return $issues;
    }
}
