<?php

namespace App\Modules\Satusehat\Services\DataQuality\Rules;

use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Support\SatusehatDataQualityContext;

/**
 * Source drift after approval — the approval was already revoked by the
 * candidate service; this issue routes the candidate back to review.
 */
class SourceDriftRule extends AbstractDataQualityRule
{
    public function code(): string
    {
        return 'source_drift';
    }

    public function evaluate(SatusehatDataQualityContext $context): array
    {
        $candidate = $context->candidate;
        $drifted = $candidate->readiness_status === SatusehatCandidate::READINESS_SOURCE_CHANGED
            || $candidate->dental_readiness_status === SatusehatCandidate::DENTAL_SOURCE_CHANGED;

        if (! $drifted) {
            return [];
        }

        return [$this->issue(
            SatusehatDataQualityIssue::SEVERITY_HARD,
            'Data klinis berubah setelah persetujuan — persetujuan dicabut, wajib review ulang.',
            'satusehat_candidate',
            (int) $candidate->id,
            'source_hash',
            'Review ulang kandidat pada Filter Pengiriman, lalu setujui kembali.',
            'Supervisor RME',
        )];
    }
}
