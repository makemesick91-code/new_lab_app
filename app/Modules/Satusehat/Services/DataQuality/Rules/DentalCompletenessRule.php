<?php

namespace App\Modules\Satusehat\Services\DataQuality\Rules;

use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Support\SatusehatDataQualityContext;

/**
 * Dental (SATUSEHAT-3) completeness triage. A visit with NO odontogram at all
 * is informational (no dental Observation is emitted for it — dental data is
 * never fabricated); an odontogram that exists but is incomplete or blocked on
 * mapping governance is an actionable internal item.
 */
class DentalCompletenessRule extends AbstractDataQualityRule
{
    public function code(): string
    {
        return 'dental_completeness';
    }

    public function evaluate(SatusehatDataQualityContext $context): array
    {
        $status = $context->candidate->dental_readiness_status;
        $visitId = $context->visit?->id;

        if ($status === SatusehatCandidate::DENTAL_INCOMPLETE) {
            // No odontogram at all ⇒ informational (dental Observations are
            // simply not emitted; dental IHS gaps are covered by the external
            // identifier rules). Only an EXISTING but incomplete odontogram is
            // an actionable clinical item.
            $odontogramMissing = in_array('dental_odontogram_missing', $context->dentalReasonCodes, true);
            $internalGaps = collect($context->dentalReasonCodes)
                ->reject(fn (string $c) => $c === 'dental_odontogram_missing' || str_ends_with($c, '_ihs_missing'));

            if ($odontogramMissing || $internalGaps->isEmpty()) {
                return [$this->issue(
                    SatusehatDataQualityIssue::SEVERITY_INFO,
                    'Kunjungan tidak memiliki odontogram — resource Observation gigi tidak dibuat.',
                    'clinic_visit',
                    $visitId,
                    'odontogram',
                    'Opsional: dokter melengkapi odontogram bila relevan secara klinis.',
                    'Doctor',
                )];
            }

            return [$this->issue(
                SatusehatDataQualityIssue::SEVERITY_SOFT,
                'Data odontogram belum lengkap untuk kesiapan SATUSEHAT gigi.',
                'clinic_visit',
                $visitId,
                'odontogram',
                'Dokter melengkapi data odontogram pada kunjungan.',
                'Doctor',
                null,
                ['dental_reasons' => $context->dentalReasonCodes],
            )];
        }

        if ($status === SatusehatCandidate::DENTAL_MAPPING_BLOCKED) {
            return [$this->issue(
                SatusehatDataQualityIssue::SEVERITY_SOFT,
                'Terminologi gigi belum diverifikasi/diaktifkan — kesiapan gigi terblokir mapping.',
                'clinic_visit',
                $visitId,
                'dental_mapping',
                'Verifikasi + aktifkan mapping gigi resmi pada halaman Mapping Kode.',
                'Clinical Reviewer',
                null,
                ['dental_reasons' => $context->dentalReasonCodes],
            )];
        }

        return [];
    }
}
