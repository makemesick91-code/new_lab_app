<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Models\User;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\CrossBranchPatientLookupService;
use App\Modules\RME\Services\DoctorPatientScopeService;

/**
 * LEGACY-RME-PDF-1B — choosing the patient a legacy document belongs to.
 *
 * The SEARCH itself is delegated to the canonical CrossBranchPatientLookupService,
 * so the archive workspace inherits its rules rather than inventing a second
 * patient search: Nomor RM only (never a name, phone, or KTP/NIK search), an
 * exact match before a bounded suffix match, and a capped result set.
 *
 * This service adds exactly one thing the canonical payload deliberately omits:
 * the surrogate key needed to actually select a row. It is attached by matching
 * on the already-returned Nomor RM, and the projection is restricted to
 * non-sensitive identity columns — KTP/NIK is never selected, let alone shown.
 */
class LegacyRmePatientLookupService
{
    public function __construct(
        private readonly CrossBranchPatientLookupService $lookup,
        private readonly PatientEarliestNativeRmeDateResolver $earliestNativeRmeDate,
        private readonly DoctorPatientScopeService $doctorScope,
    ) {}

    /**
     * Resolve a patient chosen by surrogate key (the id attached to a search
     * result) back to a row the CALLER is allowed to see.
     *
     * The canonical search deliberately constrains itself — Nomor RM only, a
     * minimum suffix length, a capped result set, and the doctor patient scope.
     * Selecting by id must not be a weaker door into the same data than the
     * search that produced the link, so the same doctor scope is applied here.
     * Returns null (rendered as "not found") rather than throwing, so ids
     * cannot be probed.
     */
    public function findSelectable(?User $user, int $patientId): ?Patient
    {
        if ($patientId <= 0) {
            return null;
        }

        $query = Patient::query()->whereKey($patientId);

        if ($user !== null) {
            $query = $this->doctorScope->applyPatientScopeForUser($user, $query);
        }

        return $query->first();
    }

    /**
     * @return array<string, mixed> the canonical payload, with an `id` attached
     *                              to each result that resolves to exactly one patient
     */
    public function search(?string $medicalRecordNumber): array
    {
        $payload = $this->lookup->lookupByMedicalRecordNumberAcrossBranches($medicalRecordNumber);

        $numbers = array_values(array_filter(array_map(
            fn (array $result): string => (string) ($result['medical_record_number'] ?? ''),
            $payload['results'] ?? [],
        )));

        if ($numbers === []) {
            return $payload;
        }

        $patients = Patient::query()
            ->whereIn('medical_record_number', $numbers)
            ->get(['id', 'medical_record_number', 'date_of_birth'])
            ->groupBy('medical_record_number');

        $payload['results'] = array_map(function (array $result) use ($patients): array {
            $matches = $patients->get((string) ($result['medical_record_number'] ?? ''));

            // Only attach an id when the Nomor RM resolves unambiguously. A
            // genuinely duplicated RM must be disambiguated by a human, not by
            // this service picking one arbitrarily.
            $result['id'] = ($matches !== null && $matches->count() === 1)
                ? (int) $matches->first()->id
                : null;

            $result['date_of_birth'] = ($matches !== null && $matches->count() === 1)
                ? $matches->first()->date_of_birth?->toDateString()
                : null;

            return $result;
        }, $payload['results'] ?? []);

        return $payload;
    }

    /**
     * The archive-relevant summary for one patient: never KTP/NIK, never a
     * clinical detail — just the bounds an operator needs to pick a valid date.
     *
     * @return array<string, mixed>
     */
    public function summarize(Patient $patient): array
    {
        $earliest = $this->earliestNativeRmeDate->resolve((int) $patient->getKey());

        return [
            'id' => (int) $patient->getKey(),
            'name' => (string) $patient->name,
            'medical_record_number' => $patient->medical_record_number,
            'date_of_birth' => $patient->date_of_birth?->toDateString(),
            'branch_label' => $patient->branch?->name,
            'earliest_native_rme_date' => $earliest?->toDateString(),
            'has_native_rme' => $earliest !== null,
        ];
    }
}
