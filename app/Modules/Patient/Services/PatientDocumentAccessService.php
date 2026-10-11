<?php

namespace App\Modules\Patient\Services;

use App\Models\User;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\Patient\Controllers\PatientDocumentController;
use App\Modules\Patient\Models\PatientDocument;
use App\Modules\Patient\Policies\PatientDocumentPolicy;
use App\Modules\Patient\Support\PatientDocumentAccessDecision;
use App\Modules\RmeOnlineContext\Services\DailyBranchContextService;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * SECURITY-PATIENT-KTP-ARCHIVE-BRANCH-ISOLATION-1 — the ONE place that decides
 * whether an account may read (or delete) a stored patient identity document.
 *
 * Both {@see PatientDocumentPolicy} (UI gating,
 * `@can`) and {@see PatientDocumentController}
 * (the file response) ask {@see decide()}; neither re-derives the rule, so the
 * button and the bytes can never disagree.
 *
 * The rule, evaluated fresh on every call:
 *
 *   1. No `manage patients`                    -> deny  (no_permission)
 *   2. Super Admin, or a DIRECT per-account grant of
 *      `view_cross_branch_patient_ktp_documents` -> allow (privileged)
 *      The permission is granted to NO role, and a role-level grant (e.g. via
 *      the role editor) is deliberately IGNORED: the owner rule is "explicit,
 *      per account". Supervisor RME gets nothing from its role name.
 *   3. No server-resolved working branch       -> deny  (no_working_branch)
 *      Only context-bound roles (Admin Klinik / Front Office, Perawat, Kasir)
 *      have one, from {@see RmeWorkingBranchScope::activeBranchId()} — the
 *      canonical, fail-closed working branch (online context, front-office
 *      pin). A request `branch_id`, header or session value is never consulted.
 *   4. Working branch is not the operator's COMMITTED branch for the clinical
 *      day ({@see DailyBranchContextService::lockedBranchIdFor()})
 *                                              -> deny  (uncommitted_working_branch)
 *      An online context is SELF-SELECTED. For Admin Klinik / Front Office the
 *      daily lock turns that selection into a commitment (moving needs Super
 *      Admin approval); a Perawat has no daily lock by design and can re-select
 *      at will, so its "working branch" is ambiguous authority for an identity
 *      card and is refused. Without this rule a Perawat could hop branches and
 *      read each branch's cards, audited as same_branch.
 *   5. Document provenance unknown (NULL)      -> deny  (unknown_provenance)
 *   6. Working branch != capture branch        -> deny  (branch_mismatch)
 *   7. otherwise                               -> allow (same_branch)
 *
 * What it deliberately does NOT consult: the patient's `branch_id`, the Nomor
 * RM, the patient's visits, or patient-search visibility. All four describe the
 * PATIENT; the first two are editable through the unscoped master-data form, and
 * the last two would turn "branch B can find / has treated this person" into
 * "branch B may read the identity card captured at branch A".
 *
 * A denial is a 404, so a cross-branch request is indistinguishable from a
 * document that does not exist (no existence oracle).
 */
class PatientDocumentAccessService
{
    public const BASE_PERMISSION = 'manage patients';

    public const CROSS_BRANCH_PERMISSION = 'view_cross_branch_patient_ktp_documents';

    /** Per account, per document route. A real operator opens a card a handful of times. */
    public const REQUESTS_PER_MINUTE = 30;

    public const RATE_LIMITER = 'patient-document';

    public const AUDIT_ENTITY = 'mst_patient_documents';

    public const AUDIT_VIEW_GRANTED = 'PATIENT_DOCUMENT_VIEWED';

    public const AUDIT_VIEW_PRIVILEGED = 'PATIENT_DOCUMENT_VIEWED_PRIVILEGED';

    public const AUDIT_ACCESS_DENIED = 'PATIENT_DOCUMENT_ACCESS_DENIED';

    public const AUDIT_DELETED = 'PATIENT_DOCUMENT_DELETED';

    public const AUDIT_PROVENANCE_BACKFILLED = 'PATIENT_DOCUMENT_CAPTURE_BRANCH_BACKFILLED';

    public const OPERATION_VIEW = 'view';

    public const OPERATION_DELETE = 'delete';

    public const REASON_SAME_BRANCH = 'same_branch';

    public const REASON_PRIVILEGED = 'privileged';

    public const REASON_NO_PERMISSION = 'no_permission';

    public const REASON_NO_WORKING_BRANCH = 'no_working_branch';

    public const REASON_UNKNOWN_PROVENANCE = 'unknown_provenance';

    public const REASON_BRANCH_MISMATCH = 'branch_mismatch';

    public const REASON_UNCOMMITTED_WORKING_BRANCH = 'uncommitted_working_branch';

    /** The role the single global Gate::before treats as all-powerful. */
    public const PRIVILEGED_ROLE = 'Super Admin';

    public function __construct(
        private readonly RmeWorkingBranchScope $workingBranch,
        private readonly DailyBranchContextService $dailyBranches,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * Super Admin, or a DIRECT grant on this account. A grant inherited from a
     * role is not counted (fail closed): cross-branch identity-card access is a
     * per-account owner decision, and the role editor can grant to a whole role.
     * Reads the account's direct permissions as a collection, so an unseeded
     * permission (the window between deploy and seeding) is "not held", never
     * an exception.
     */
    public function isPrivileged(User $user): bool
    {
        if ($user->hasRole(self::PRIVILEGED_ROLE)) {
            return true;
        }

        return $user->getDirectPermissions()->contains('name', self::CROSS_BRANCH_PERMISSION);
    }

    public function decide(?User $user, PatientDocument $document): PatientDocumentAccessDecision
    {
        $captured = $document->captured_branch_id !== null ? (int) $document->captured_branch_id : null;

        if ($user === null || ! $user->can(self::BASE_PERMISSION)) {
            return PatientDocumentAccessDecision::deny(self::REASON_NO_PERMISSION, null, $captured);
        }

        if ($this->isPrivileged($user)) {
            return PatientDocumentAccessDecision::allow(self::REASON_PRIVILEGED, true, null, $captured);
        }

        $operator = $this->workingBranch->activeBranchId($user);

        if ($operator === null) {
            return PatientDocumentAccessDecision::deny(self::REASON_NO_WORKING_BRANCH, null, $captured);
        }

        if ($this->dailyBranches->lockedBranchIdFor($user) !== $operator) {
            return PatientDocumentAccessDecision::deny(self::REASON_UNCOMMITTED_WORKING_BRANCH, $operator, $captured);
        }

        if ($captured === null) {
            return PatientDocumentAccessDecision::deny(self::REASON_UNKNOWN_PROVENANCE, $operator, null);
        }

        if ($captured !== $operator) {
            return PatientDocumentAccessDecision::deny(self::REASON_BRANCH_MISMATCH, $operator, $captured);
        }

        return PatientDocumentAccessDecision::allow(self::REASON_SAME_BRANCH, false, $operator, $captured);
    }

    /**
     * Decide; on refusal record the denial and throw a non-disclosing 404.
     *
     * A GRANT is not recorded here: the caller records it only once the act
     * has actually happened ({@see recordViewed()} after the file was found,
     * {@see recordDeleted()} after the delete), so the trail never claims a
     * view that ended in a missing-file 404.
     *
     * @throws NotFoundHttpException
     */
    public function authorize(User $user, PatientDocument $document, string $operation): PatientDocumentAccessDecision
    {
        $decision = $this->decide($user, $document);

        if (! $decision->allowed) {
            $this->record($user, $document, self::AUDIT_ACCESS_DENIED, $decision, $operation);

            throw new NotFoundHttpException;
        }

        return $decision;
    }

    public function recordViewed(User $user, PatientDocument $document, PatientDocumentAccessDecision $decision): void
    {
        $this->record(
            $user,
            $document,
            $decision->privileged ? self::AUDIT_VIEW_PRIVILEGED : self::AUDIT_VIEW_GRANTED,
            $decision,
            self::OPERATION_VIEW,
        );
    }

    public function recordDeleted(User $user, PatientDocument $document, PatientDocumentAccessDecision $decision): void
    {
        $this->record($user, $document, self::AUDIT_DELETED, $decision, self::OPERATION_DELETE);
    }

    private function record(User $user, PatientDocument $document, string $action, PatientDocumentAccessDecision $decision, string $operation): void
    {
        $this->audit->log(
            self::AUDIT_ENTITY,
            (int) $document->getKey(),
            $action,
            null,
            $decision->toAuditPayload($operation, $document->document_type),
            $user,
        );
    }
}
