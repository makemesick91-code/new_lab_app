<?php

namespace App\Modules\Satusehat\Services\DataQuality\Rules;

use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Support\SatusehatDataQualityContext;

/**
 * Billed treatments need an ACTIVE SATUSEHAT Procedure mapping. The canonical
 * detection lives in the readiness engine (treatment_mapping_missing) — this
 * rule translates it into a triageable issue for the mapping governor.
 */
class TreatmentMappingRule extends AbstractDataQualityRule
{
    public function code(): string
    {
        return 'treatment_mapping';
    }

    public function evaluate(SatusehatDataQualityContext $context): array
    {
        if (! $context->hasReason('treatment_mapping_missing')) {
            return [];
        }

        return [$this->issue(
            SatusehatDataQualityIssue::SEVERITY_SOFT,
            'Terdapat tindakan pada kunjungan ini tanpa mapping Procedure SATUSEHAT aktif.',
            'clinic_visit',
            $context->visit?->id,
            'treatments',
            'Lengkapi mapping Procedure pada halaman Mapping Kode (butuh review klinis).',
            'Supervisor RME',
        )];
    }
}
