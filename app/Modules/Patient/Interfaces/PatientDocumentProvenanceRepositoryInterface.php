<?php

namespace App\Modules\Patient\Interfaces;

use App\Modules\Patient\Models\PatientDocument;
use Illuminate\Support\Collection;

/**
 * SECURITY-PATIENT-KTP-ARCHIVE-BRANCH-ISOLATION-1 — the reads and the single
 * write the capture-branch backfill needs, and nothing else.
 *
 * Deliberately narrow: there is no "set any branch" method. The only write
 * records a capture branch on a row that has none, under a row lock, and the
 * model itself refuses to change a value that is already recorded.
 */
interface PatientDocumentProvenanceRepositoryInterface
{
    /**
     * Live documents whose capture branch is unknown, with their patient.
     *
     * @return Collection<int, PatientDocument>
     */
    public function withoutCapturedBranch(): Collection;

    /**
     * Branch ids of the patient's clinic visits created within [from, to].
     *
     * @return array<int, int>
     */
    public function visitBranchIdsCreatedBetween(int $patientId, \DateTimeInterface $from, \DateTimeInterface $to): array;

    /**
     * Record a capture branch on a document that still has none. Returns false
     * when the row is gone or already carries a value (nothing is changed).
     */
    public function recordCapturedBranchIfUnset(int $documentId, int $branchId): bool;
}
