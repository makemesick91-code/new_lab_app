<?php

namespace App\Modules\Satusehat\Services\DataQuality\Rules;

use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Support\SatusehatDataQualityContext;
use Carbon\Carbon;

/**
 * Demographic VALIDITY (as opposed to presence): impossible birth dates and
 * non-canonical gender values are HARD issues — invalid data can never be
 * waived into readiness; it must actually be corrected.
 */
class PatientDemographicsRule extends AbstractDataQualityRule
{
    public function code(): string
    {
        return 'patient_demographics';
    }

    public function evaluate(SatusehatDataQualityContext $context): array
    {
        $patient = $context->patient();
        if ($patient === null) {
            return [];
        }

        $issues = [];

        if (filled($patient->date_of_birth)) {
            $dob = Carbon::parse($patient->date_of_birth);
            $minYear = (int) config('satusehat_data_quality.patient.dob_min_year', 1900);
            $maxAge = (int) config('satusehat_data_quality.patient.max_age_years', 130);

            if ($dob->isFuture() || $dob->year < $minYear || $dob->diffInYears(now()) > $maxAge) {
                $issues[] = $this->issue(
                    SatusehatDataQualityIssue::SEVERITY_HARD,
                    'Tanggal lahir pasien tidak valid (tanggal mustahil).',
                    'patient',
                    (int) $patient->id,
                    'date_of_birth',
                    'Perbaiki tanggal lahir pasien pada halaman remediasi pasien.',
                    'Admin Klinik',
                );
            }
        }

        if (filled($patient->gender)) {
            $canonical = (array) config('satusehat_data_quality.patient.gender_canonical', []);
            if (! in_array(mb_strtolower(trim((string) $patient->gender)), $canonical, true)) {
                $issues[] = $this->issue(
                    SatusehatDataQualityIssue::SEVERITY_HARD,
                    'Jenis kelamin pasien tidak sesuai nilai kanonis.',
                    'patient',
                    (int) $patient->id,
                    'gender',
                    'Pilih nilai jenis kelamin kanonis (Male/Female/Other).',
                    'Admin Klinik',
                );
            }
        }

        return $issues;
    }
}
