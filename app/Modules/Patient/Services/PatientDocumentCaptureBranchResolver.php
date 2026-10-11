<?php

namespace App\Modules\Patient\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;

/**
 * SECURITY-PATIENT-KTP-ARCHIVE-BRANCH-ISOLATION-1 — the branch a KTP card was
 * CAPTURED at, resolved on the server at the moment it is archived.
 *
 * The answer is written once to `mst_patient_documents.captured_branch_id` and
 * is the only input to the per-branch read decision. It is never taken from the
 * request: the two registration surfaces each have a server-side authority.
 *
 *  - Kunjungan -> Pasien Baru: the VISIT's branch. ClinicVisitService already
 *    pinned it to the operator's working branch for a context-bound role, and
 *    validated it against the RME branch set for everyone else.
 *  - Master Data -> Tambah Pasien: for a context-bound operator, their working
 *    branch — where the card physically was — even when the form names another
 *    branch for the patient. For a governance account (no working branch), the
 *    patient's branch as validated by the request, because that account is not
 *    branch-scoped and is registering on that branch's behalf.
 *
 * Anything that is not an active RME branch resolves to NULL, which the access
 * policy treats as unknown provenance (fail closed).
 */
class PatientDocumentCaptureBranchResolver
{
    public function __construct(
        private readonly RmeWorkingBranchScope $workingBranch,
        private readonly BranchService $branches,
    ) {}

    public function forVisitRegistration(ClinicVisit $visit): ?int
    {
        return $this->activeRmeBranch($visit->branch_id);
    }

    public function forMasterDataRegistration(User $actor, Patient $patient): ?int
    {
        if ($this->workingBranch->isContextBound($actor)) {
            // A context-bound operator without a working branch records nothing:
            // never fall back to the branch the form happened to name.
            return $this->activeRmeBranch($this->workingBranch->activeBranchId($actor));
        }

        return $this->activeRmeBranch($patient->branch_id);
    }

    private function activeRmeBranch(mixed $branchId): ?int
    {
        if ($branchId === null || (int) $branchId <= 0) {
            return null;
        }

        $branchId = (int) $branchId;

        return in_array($branchId, $this->branches->rmeEnabledIds(), true) ? $branchId : null;
    }
}
