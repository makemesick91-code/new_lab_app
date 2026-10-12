<?php

namespace App\Modules\ClinicOversight\Repositories;

use App\Modules\ClinicOversight\Interfaces\OversightClinicalRecordRepositoryInterface;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use Illuminate\Support\Collection;

class OversightClinicalRecordRepository implements OversightClinicalRecordRepositoryInterface
{
    public function findVisitInBranches(array $branchIds, int $visitId): ?ClinicVisit
    {
        if ($branchIds === []) {
            return null;
        }

        return ClinicVisit::query()
            ->whereIn('branch_id', $branchIds)
            ->whereKey($visitId)
            ->first();
    }

    public function findVisitByNumberInBranches(array $branchIds, string $visitNumber): ?ClinicVisit
    {
        $visitNumber = trim($visitNumber);

        if ($branchIds === [] || $visitNumber === '') {
            return null;
        }

        return ClinicVisit::query()
            ->whereIn('branch_id', $branchIds)
            ->where('visit_number', $visitNumber)
            ->first();
    }

    public function visitsForPatient(array $branchIds, int $patientId, int $limit = 200): Collection
    {
        if ($branchIds === []) {
            return collect();
        }

        return ClinicVisit::query()
            ->whereIn('branch_id', $branchIds)
            ->where('patient_id', $patientId)
            ->with(['branch', 'doctor', 'clinicRoom', 'medicalRecord', 'odontogram', 'rmePrescription'])
            ->orderByDesc('visit_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function sheetsForPatient(array $branchIds, int $patientId): Collection
    {
        if ($branchIds === []) {
            return collect();
        }

        return MedicalRecord::query()
            ->select('trx_medical_records.*')
            ->join('trx_clinic_visits', 'trx_clinic_visits.id', '=', 'trx_medical_records.clinic_visit_id')
            ->whereIn('trx_medical_records.branch_id', $branchIds)
            ->where('trx_medical_records.patient_id', $patientId)
            ->whereNull('trx_clinic_visits.deleted_at')
            ->with(['clinicVisit', 'handwritingPages', 'finalizedBy', 'diagnoses'])
            ->orderBy('trx_clinic_visits.visit_date')
            ->orderBy('trx_clinic_visits.id')
            ->get();
    }

    public function patientIdsByMedicalRecordNumbers(array $numbers): array
    {
        $numbers = array_values(array_filter($numbers, fn ($n) => is_string($n) && $n !== ''));

        if ($numbers === []) {
            return [];
        }

        return Patient::query()
            ->whereIn('medical_record_number', $numbers)
            ->orderBy('id')
            ->limit(5)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
