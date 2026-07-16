<?php

namespace App\Modules\Satusehat\Services\DataQuality\Rules;

use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Support\SatusehatDataQualityContext;

/**
 * Location readiness. A visit without a treatment room is an INTERNAL gap
 * (fix now via room assignment); the Location IHS id is EXTERNAL.
 */
class LocationReadinessRule extends AbstractDataQualityRule
{
    public function code(): string
    {
        return 'location_readiness';
    }

    public function evaluate(SatusehatDataQualityContext $context): array
    {
        $issues = [];

        if ($context->hasReason('location_missing')) {
            $issues[] = $this->issue(
                SatusehatDataQualityIssue::SEVERITY_SOFT,
                'Ruangan perawatan kunjungan belum ditetapkan.',
                'clinic_visit',
                $context->visit?->id,
                'clinic_room_id',
                'Tetapkan ruangan pada kunjungan (alur Input Ruangan).',
                'Admin Klinik',
            );
        }

        if ($context->hasReason('location_ihs_missing')) {
            $issues[] = $this->issue(
                SatusehatDataQualityIssue::SEVERITY_INFO,
                'IHS Location ruangan belum tersedia — menunggu onboarding Kemkes.',
                'clinic_room',
                $context->visit?->clinic_room_id,
                'location_ihs',
                'Diblokir eksternal: butuh onboarding Location SATUSEHAT.',
                'IT Operator',
                null,
                ['external' => true],
            );
        }

        return $issues;
    }
}
