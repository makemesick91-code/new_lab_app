<?php

namespace App\Modules\Satusehat\Services\DataQuality\Rules;

use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Support\SatusehatDataQualityContext;

/**
 * Local structural conformance + unsupported local schema. Conformance failure
 * is HARD (invalid payload structure must be fixed, never waived); an
 * unsupported local schema is tracked in the dedicated `unsupported` state.
 */
class LocalConformanceRule extends AbstractDataQualityRule
{
    public function code(): string
    {
        return 'local_conformance';
    }

    public function evaluate(SatusehatDataQualityContext $context): array
    {
        $issues = [];
        $candidate = $context->candidate;
        $visitId = $context->visit?->id;

        if ($candidate->dental_readiness_status === SatusehatCandidate::DENTAL_CONFORMANCE_FAILED) {
            $issues[] = $this->issue(
                SatusehatDataQualityIssue::SEVERITY_HARD,
                'Validasi struktur lokal resource gigi GAGAL — data sumber tidak konsisten.',
                'satusehat_candidate',
                (int) $candidate->id,
                'dental_conformance',
                'Periksa data odontogram sumber (nomor gigi tidak dikenal / struktur rusak).',
                'Supervisor RME',
                null,
                ['dental_reasons' => $context->dentalReasonCodes],
            );
        }

        if ($candidate->dental_readiness_status === SatusehatCandidate::DENTAL_UNSUPPORTED) {
            $issues[] = $this->issue(
                SatusehatDataQualityIssue::SEVERITY_SOFT,
                'Skema lokal odontogram memuat status yang belum didukung profil SATUSEHAT.',
                'satusehat_candidate',
                (int) $candidate->id,
                'dental_schema',
                'Butuh keputusan governance terminologi (sprint terpisah) — jangan menebak kode.',
                'Clinical Reviewer',
                SatusehatDataQualityIssue::STATUS_UNSUPPORTED,
                ['dental_reasons' => $context->dentalReasonCodes],
            );
        }

        return $issues;
    }
}
