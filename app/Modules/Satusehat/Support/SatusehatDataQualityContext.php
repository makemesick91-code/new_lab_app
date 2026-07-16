<?php

namespace App\Modules\Satusehat\Support;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\Patient\Models\Patient;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use Illuminate\Support\Collection;

/**
 * Immutable evaluation context handed to every data-quality rule. Built once
 * per candidate from the freshly refreshed candidate row (the canonical
 * readiness engine's stored verdict — rules never re-implement readiness).
 * PII-free by construction: rules read models directly but must only emit
 * structured, PII-free drafts.
 */
final class SatusehatDataQualityContext
{
    /** @var Collection<int, MedicalRecordDiagnosis>|null */
    private ?Collection $diagnoses = null;

    /**
     * @param  list<string>  $reasonCodes  codes from the candidate's stored readiness_reasons
     * @param  list<string>  $dentalReasonCodes
     */
    public function __construct(
        public readonly SatusehatCandidate $candidate,
        public readonly ?ClinicVisit $visit,
        public readonly array $reasonCodes,
        public readonly array $dentalReasonCodes,
        public readonly string $environment,
    ) {}

    public function hasReason(string $code): bool
    {
        return in_array($code, $this->reasonCodes, true);
    }

    public function hasDentalReason(string $code): bool
    {
        return in_array($code, $this->dentalReasonCodes, true);
    }

    public function patient(): ?Patient
    {
        return $this->visit?->patient;
    }

    public function doctor(): ?Doctor
    {
        return $this->visit?->doctor;
    }

    public function medicalRecord(): ?MedicalRecord
    {
        return $this->visit?->medicalRecord;
    }

    /**
     * Structured diagnoses for the candidate's medical record (cached).
     *
     * @return Collection<int, MedicalRecordDiagnosis>
     */
    public function diagnoses(): Collection
    {
        if ($this->diagnoses !== null) {
            return $this->diagnoses;
        }

        $mr = $this->medicalRecord();

        return $this->diagnoses = $mr === null
            ? collect()
            : MedicalRecordDiagnosis::query()
                ->where('medical_record_id', $mr->id)
                ->with('clinicalDiagnosis:id,code_system,code,display,status')
                ->get();
    }
}
