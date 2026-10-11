<?php

namespace App\Modules\Patient\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Branch\Support\BranchCodeAlias;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\Patient\Interfaces\PatientDocumentProvenanceRepositoryInterface;
use App\Modules\Patient\Models\PatientDocument;
use InvalidArgumentException;

/**
 * SECURITY-PATIENT-KTP-ARCHIVE-BRANCH-ISOLATION-1 — give a pre-sprint document
 * a capture branch ONLY where independent evidence agrees, and otherwise leave
 * it unknown (which the access policy treats as fail closed).
 *
 * A document is resolvable when ALL of these hold:
 *
 *   E1  the patient exists and carries a branch that is an active RME branch;
 *   E2  the branch code inside the patient's Nomor RM resolves (alias-aware) to
 *       that same branch — two independently written records agree;
 *   E3  the document was archived within the registration window of the
 *       patient row (KTP cards are archived by the registration submit, so a
 *       card attached later cannot have its capture branch read off the
 *       registration);
 *   E4  a clinic visit was created for the patient inside that window (the
 *       Kunjungan -> Pasien Baru flow), and EVERY such visit sits at that same
 *       branch. The visit's branch was pinned by the server at registration and
 *       is not editable, which makes it the only piece of evidence the unscoped
 *       patient master-data form cannot rewrite. E1 and E2 read fields that form
 *       CAN change, so on their own they could be steered to the wrong branch;
 *       anchored to the visit they can only ever DISAGREE (and refuse).
 *
 * A Master Data registration has no visit, and the branch it was captured at
 * (the operator's working branch that day) is not recorded anywhere a backfill
 * can trust, so such a document stays unknown — fail closed, by design.
 *
 * Nothing about a patient is changed. Patient branch, Nomor RM and visits are
 * only READ as evidence. The one write records a capture branch on a document
 * that has none, under a row lock, audited per document; a recorded value is
 * never rewritten (the model refuses).
 *
 * Reports aggregate counts and reason codes only — never a patient name, NIK,
 * Nomor RM or storage path.
 */
class PatientDocumentCaptureBranchBackfillService
{
    /** Archive may follow the patient row by at most this many seconds. */
    public const REGISTRATION_WINDOW_SECONDS = 600;

    /** Tolerated clock skew of the document before the patient row. */
    public const REGISTRATION_SKEW_SECONDS = 60;

    public const EVIDENCE_PATIENT_BRANCH = 'patient_branch_active_rme';

    public const EVIDENCE_RM_BRANCH = 'medical_record_number_branch_agrees';

    public const EVIDENCE_REGISTRATION_WINDOW = 'archived_at_registration';

    public const EVIDENCE_REGISTRATION_VISIT = 'registration_visit_branch_agrees';

    public const UNRESOLVED_NO_PATIENT = 'patient_missing';

    public const UNRESOLVED_PATIENT_BRANCH = 'patient_branch_missing_or_not_rme';

    public const UNRESOLVED_RM_UNPARSEABLE = 'medical_record_number_unparseable';

    public const UNRESOLVED_RM_DISAGREES = 'medical_record_number_branch_disagrees';

    public const UNRESOLVED_NOT_AT_REGISTRATION = 'not_archived_at_registration';

    public const UNRESOLVED_VISIT_DISAGREES = 'registration_visit_at_other_branch';

    public const UNRESOLVED_NO_REGISTRATION_VISIT = 'no_registration_visit';

    public function __construct(
        private readonly PatientDocumentProvenanceRepositoryInterface $documents,
        private readonly PatientMedicalRecordNumberService $medicalRecordNumbers,
        private readonly BranchService $branches,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @param  User|null  $actor  the named Super Admin applying the backfill
     *                            (required with $apply; recorded on every row)
     * @return array<string, mixed>
     */
    public function run(bool $apply, ?User $actor = null): array
    {
        if ($apply && ($actor === null || ! $actor->hasRole(PatientDocumentAccessService::PRIVILEGED_ROLE) || $actor->deleted_at !== null || ! (bool) ($actor->is_active ?? true))) {
            throw new InvalidArgumentException('Applying the capture-branch backfill requires an active Super Admin actor.');
        }

        $candidates = $this->documents->withoutCapturedBranch();
        $rmeBranchIds = $this->branches->rmeEnabledIds();

        $resolvable = 0;
        $applied = 0;
        $unresolved = [];

        foreach ($candidates as $document) {
            $verdict = $this->evaluate($document, $rmeBranchIds);

            if ($verdict['branch_id'] === null) {
                $unresolved[$verdict['reason']] = ($unresolved[$verdict['reason']] ?? 0) + 1;

                continue;
            }

            $resolvable++;

            if ($apply && $this->documents->recordCapturedBranchIfUnset((int) $document->id, $verdict['branch_id'])) {
                $applied++;
                $this->audit->log(
                    PatientDocumentAccessService::AUDIT_ENTITY,
                    (int) $document->id,
                    PatientDocumentAccessService::AUDIT_PROVENANCE_BACKFILLED,
                    ['captured_branch_id' => null],
                    [
                        'captured_branch_id' => $verdict['branch_id'],
                        'evidence' => $verdict['evidence'],
                        'document_type' => $document->document_type,
                    ],
                    $actor,
                );
            }
        }

        ksort($unresolved);

        return [
            'mode' => $apply ? 'apply' : 'dry-run',
            'scanned' => $candidates->count(),
            'resolvable' => $resolvable,
            'unresolved' => array_sum($unresolved),
            'unresolved_by_reason' => $unresolved,
            'applied' => $applied,
        ];
    }

    /**
     * @param  array<int, int>  $rmeBranchIds
     * @return array{branch_id: ?int, reason: ?string, evidence: array<int, string>}
     */
    private function evaluate(PatientDocument $document, array $rmeBranchIds): array
    {
        $patient = $document->patient;

        if ($patient === null) {
            return $this->unresolved(self::UNRESOLVED_NO_PATIENT);
        }

        $branchId = $patient->branch_id !== null ? (int) $patient->branch_id : null;

        if ($branchId === null || ! in_array($branchId, $rmeBranchIds, true)) {
            return $this->unresolved(self::UNRESOLVED_PATIENT_BRANCH);
        }

        $rmBranchCode = $this->medicalRecordNumbers->branchCodeFrom($patient->medical_record_number);

        if ($rmBranchCode === null) {
            return $this->unresolved(self::UNRESOLVED_RM_UNPARSEABLE);
        }

        $patientBranchCode = BranchCodeAlias::canonicalize($this->branches->find($branchId)?->code);

        if ($patientBranchCode === null || $patientBranchCode !== $rmBranchCode) {
            return $this->unresolved(self::UNRESOLVED_RM_DISAGREES);
        }

        $registeredAt = $patient->created_at;
        $archivedAt = $document->created_at;

        if ($registeredAt === null || $archivedAt === null) {
            return $this->unresolved(self::UNRESOLVED_NOT_AT_REGISTRATION);
        }

        $offset = $archivedAt->getTimestamp() - $registeredAt->getTimestamp();

        if ($offset < -self::REGISTRATION_SKEW_SECONDS || $offset > self::REGISTRATION_WINDOW_SECONDS) {
            return $this->unresolved(self::UNRESOLVED_NOT_AT_REGISTRATION);
        }

        $visitBranches = $this->documents->visitBranchIdsCreatedBetween(
            (int) $patient->id,
            $registeredAt->copy()->subSeconds(self::REGISTRATION_WINDOW_SECONDS),
            $archivedAt->copy()->addSeconds(self::REGISTRATION_WINDOW_SECONDS),
        );

        if ($visitBranches === []) {
            return $this->unresolved(self::UNRESOLVED_NO_REGISTRATION_VISIT);
        }

        if (array_diff($visitBranches, [$branchId]) !== []) {
            return $this->unresolved(self::UNRESOLVED_VISIT_DISAGREES);
        }

        return [
            'branch_id' => $branchId,
            'reason' => null,
            'evidence' => array_values(array_filter([
                self::EVIDENCE_PATIENT_BRANCH,
                self::EVIDENCE_RM_BRANCH,
                self::EVIDENCE_REGISTRATION_WINDOW,
                self::EVIDENCE_REGISTRATION_VISIT,
            ])),
        ];
    }

    /**
     * @return array{branch_id: null, reason: string, evidence: array<int, string>}
     */
    private function unresolved(string $reason): array
    {
        return ['branch_id' => null, 'reason' => $reason, 'evidence' => []];
    }
}
