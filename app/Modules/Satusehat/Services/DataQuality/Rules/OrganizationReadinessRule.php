<?php

namespace App\Modules\Satusehat\Services\DataQuality\Rules;

use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Support\SatusehatDataQualityContext;

/**
 * Organization (branch) readiness. The Organization IHS id comes from Kemkes
 * onboarding — an EXTERNAL item. It is never fabricated locally.
 */
class OrganizationReadinessRule extends AbstractDataQualityRule
{
    public function code(): string
    {
        return 'organization_readiness';
    }

    public function evaluate(SatusehatDataQualityContext $context): array
    {
        if (! $context->hasReason('organization_ihs_missing')) {
            return [];
        }

        return [$this->issue(
            SatusehatDataQualityIssue::SEVERITY_INFO,
            'IHS Organization cabang belum tersedia — menunggu onboarding Kemkes.',
            'branch',
            $context->visit?->branch_id,
            'organization_ihs',
            'Diblokir eksternal: butuh onboarding Organization SATUSEHAT.',
            'IT Operator',
            null,
            ['external' => true],
        )];
    }
}
