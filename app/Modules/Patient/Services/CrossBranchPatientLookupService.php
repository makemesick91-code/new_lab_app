<?php

namespace App\Modules\Patient\Services;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Patient\Models\Patient;
use Illuminate\Support\Carbon;

/**
 * Cross-branch Nomor RM lookup (Sprint 57).
 *
 * DELIBERATE BRANCH-ISOLATION EXCEPTION. This is the ONLY place that queries
 * patients across every branch regardless of the active BranchContext. It exists
 * so staff can detect whether a Nomor RM already exists in another branch
 * (duplicate-registration prevention) and identify the patient's origin branch.
 *
 * Constraints, by design:
 *  - EXACT `medical_record_number` match only (no name/substring search).
 *  - Read-only: returns a small, privacy-safe array. Never mutates anything and
 *    never grants cross-branch edit/visit/payment actions.
 *  - Exposes ONLY non-sensitive identity fields. NEVER ktp_number, whatsapp,
 *    phone, email, address, or any clinical detail.
 *
 * Do NOT reuse this for normal listing — branch-scoped index queries must stay
 * scoped via their existing services.
 */
class CrossBranchPatientLookupService
{
    /** Defensive cap; medical_record_number is unique so 1 row is expected. */
    private const MAX_RESULTS = 5;

    public function __construct(
        private readonly BranchContext $branchContext,
    ) {}

    /**
     * Look up patients by EXACT Nomor RM across ALL branches.
     *
     * @return array{
     *     searched: bool,
     *     query: string,
     *     results: array<int, array{
     *         medical_record_number: string,
     *         name: string,
     *         branch_label: string,
     *         is_active: bool,
     *         latest_visit_date: ?string,
     *         is_current_branch: bool
     *     }>,
     *     is_duplicate: bool
     * }
     */
    public function lookupByMedicalRecordNumberAcrossBranches(?string $rm): array
    {
        $rm = trim((string) $rm);

        if ($rm === '') {
            return ['searched' => false, 'query' => '', 'results' => [], 'is_duplicate' => false];
        }

        $currentBranchId = $this->branchContext->id();

        $patients = Patient::query()
            ->select(['id', 'name', 'medical_record_number', 'branch_id', 'is_active'])
            ->where('medical_record_number', $rm) // EXACT match, intentionally no branch filter
            ->with('branch:id,code,name')
            ->limit(self::MAX_RESULTS)
            ->get();

        $results = $patients->map(fn (Patient $patient): array => [
            'medical_record_number' => (string) $patient->medical_record_number,
            'name' => (string) $patient->name,
            'branch_label' => $patient->branchLabel(),
            'is_active' => (bool) $patient->is_active,
            'latest_visit_date' => $this->latestVisitDate($patient->id),
            'is_current_branch' => $currentBranchId !== null && $patient->branch_id === $currentBranchId,
        ])->all();

        return [
            'searched' => true,
            'query' => $rm,
            'results' => $results,
            'is_duplicate' => count($results) > 1,
        ];
    }

    private function latestVisitDate(int $patientId): ?string
    {
        $date = ClinicVisit::query()
            ->where('patient_id', $patientId)
            ->max('visit_date');

        return $date ? Carbon::parse($date)->format('d M Y') : null;
    }
}
