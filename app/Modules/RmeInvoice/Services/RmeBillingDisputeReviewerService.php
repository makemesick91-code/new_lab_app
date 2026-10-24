<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\RmeInvoice\Interfaces\RmeBillingDisputeReviewerRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeBillingDisputeReviewer;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeAuditEvent;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeDenied;
use App\Modules\RmeInvoice\Support\RmeBillingDisputePermission;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeText;
use App\Support\AccessControl\ActiveAccountResolver;
use App\Support\AccessControl\FrontOfficeRole;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HARDENING-RME-BILLING-DISPUTE-BRANCH-REVIEWER-CONTROL-PAYMENT-1 — owner decision
 * OPS-OQ-1a (rule 204): an EXPLICITLY designated branch responsible person may
 * review and resolve that branch's billing disputes.
 *
 * The ONE place that answers "is this account the designated reviewer of this
 * branch?" and the ONE writer of designations. Policy, dispute service, routing
 * and the status command all ask here, so they cannot disagree.
 *
 * WHAT A DESIGNATION IS:
 *  - account-specific and branch-specific — never a role, so it can never reach
 *    every Front Office account through the Admin Klinik + Kasir union;
 *  - an authority ALONGSIDE the existing review permission (Super Admin), always
 *    ANDed by the caller with a usable account and a live working scope that
 *    reaches the branch;
 *  - NOT a Spatie permission: it grants no correction, price, refund,
 *    cancellation or payment power, and no other branch.
 *
 * WHO DESIGNATES: an account holding MANAGE_REVIEWERS — granted to no operational
 * role, so in practice Super Admin (RoleSeeder '*'). Never yourself. Never the
 * Owner (read-only by owner decision). Never an account that already reviews
 * every branch (redundant). Only an active, RME-enabled branch; the branch is the
 * request's claim, validated here, never trusted.
 *
 * WHO MAY BE A DESIGNEE — decided at the grant AND again on every use, so a
 * later role change voids the designation's effect without anyone remembering
 * to revoke it (brief §12 "if role changed, revalidate current authority"):
 * never the Owner (read-only); never a COUNTER role (Front Office, Kasir) —
 * the counter reports, it does not review or resolve (brief §13); always an RME
 * workspace account.
 *
 * Every refusal is audited after the rollback.
 */
class RmeBillingDisputeReviewerService
{
    private const OWNER_ROLE = 'Owner';

    /** The counter reports a dispute; it never reviews or resolves one (rule 204 §7). */
    private const COUNTER_ROLES = [FrontOfficeRole::NAME, FrontOfficeRole::LEGACY_KASIR];

    /**
     * A designee must already be an RME workspace account (the dispute surface
     * lives in the "Klinik & RME" workspace). A designation never grants that
     * access; designating someone who lacks it would record an authority they
     * cannot use (the legacy wave-operator precedent).
     */
    private const WORKSPACE_PERMISSIONS = ['view_clinic_visits', 'manage_clinic_visits'];

    public function __construct(
        private readonly RmeBillingDisputeReviewerRepositoryInterface $designations,
        private readonly ActiveAccountResolver $accounts,
        private readonly BranchService $branches,
        private readonly AuditLogService $auditLogs,
    ) {}

    // ─── Authority queries (used by policy, dispute service, routing) ────────

    /**
     * Whether $account holds an ACTIVE designation for $branchId. With $lock the
     * designation row is read FOR SHARE, so a concurrent revocation serializes
     * against the caller's transaction (the dispute service passes true, under the
     * invoice and dispute locks). Usability and working scope are the caller's job.
     */
    public function isDesignatedFor(User $account, int $branchId, bool $lock = false): bool
    {
        if (! $account->exists || ! $this->isEligibleDesignee($account)) {
            return false;
        }

        return $this->designations->activeFor((int) $account->getKey(), $branchId, $lock) !== null;
    }

    /** @return list<int> */
    public function designatedBranchIds(User $account): array
    {
        return $account->exists && $this->isEligibleDesignee($account)
            ? $this->designations->activeBranchIdsForUser((int) $account->getKey())
            : [];
    }

    public function hasAnyActiveDesignation(User $account): bool
    {
        return $account->exists
            && $this->isEligibleDesignee($account)
            && $this->designations->hasAnyActive((int) $account->getKey());
    }

    /**
     * Ids of accounts whose designation for $branchId is in force — an active row
     * AND an account still eligible to hold one (routing uses this).
     *
     * @return list<int>
     */
    public function designatedUserIdsFor(int $branchId): array
    {
        $ids = $this->designations->activeUserIdsForBranch($branchId);

        if ($ids === []) {
            return [];
        }

        return User::query()->whereIn('id', $ids)->with('roles:id,name')->orderBy('id')->get()
            ->filter(fn (User $user) => $this->isEligibleDesignee($user))
            ->map(fn (User $user) => (int) $user->id)
            ->values()
            ->all();
    }

    /**
     * Whether an account may hold (and use) a branch reviewer designation. The
     * single eligibility rule for the grant, the use and the routing.
     */
    public function isEligibleDesignee(User $account): bool
    {
        return ! $account->hasRole(self::OWNER_ROLE)
            && ! $account->hasAnyRole(self::COUNTER_ROLES)
            && $this->isWorkspaceAccount($account);
    }

    /**
     * Rule 204 §15 — a designee's "the original is correct" must be INDEPENDENT
     * of that invoice's billing: never the account that issued it, recorded a
     * payment on it, or requested / decided / applied a correction of it (each a
     * judgement about that price). Any correction status counts: confirming the
     * original contradicts — or re-certifies — that account's own judgement.
     * (Super Admin's rule-203 path is unchanged.)
     */
    public function hasBilledInvoice(User $account, RmeInvoice $invoice): bool
    {
        $accountId = (int) $account->getKey();
        $invoiceId = (int) $invoice->getKey();

        return (int) $invoice->cashier_id === $accountId
            || RmePayment::query()
                ->where('rme_invoice_id', $invoiceId)
                ->where('cashier_id', $accountId)
                ->exists()
            || RmeInvoiceCorrection::query()
                ->where('rme_invoice_id', $invoiceId)
                ->where(fn ($query) => $query
                    ->where('requested_by', $accountId)
                    ->orWhere('decided_by', $accountId)
                    ->orWhere('applied_by', $accountId))
                ->exists();
    }

    public function find(int $id): ?RmeBillingDisputeReviewer
    {
        return $this->designations->find($id);
    }

    /** @return Collection<int, RmeBillingDisputeReviewer> */
    public function listing(): Collection
    {
        return $this->designations->listing();
    }

    /**
     * Accounts the designation form may offer: usable, not the Owner, not an
     * account that already reviews every branch, not the viewer. Display only —
     * designate() decides again.
     *
     * @return Collection<int, User>
     */
    public function candidatesFor(User $viewer): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->with('roles:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->reject(fn (User $user) => (int) $user->id === (int) $viewer->id
                || ! $this->isEligibleDesignee($user)
                || $user->can(RmeBillingDisputePermission::REVIEW))
            ->values();
    }

    private function isWorkspaceAccount(User $user): bool
    {
        foreach (self::WORKSPACE_PERMISSIONS as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    // ─── Writes ──────────────────────────────────────────────────────────────

    public function designate(User $actor, User $target, int $branchId, mixed $reason): RmeBillingDisputeReviewer
    {
        $reason = RmeBillingDisputeText::assert($reason, 'reason', 'Alasan penunjukan');

        return $this->audited('designate', $actor, null, (int) $target->getKey(), $branchId, function () use ($actor, $target, $branchId, $reason) {
            try {
                return DB::transaction(function () use ($actor, $target, $branchId, $reason) {
                    $governor = $this->assertGovernor($actor);

                    if ((int) $governor->getKey() === (int) $target->getKey()) {
                        throw new RmeBillingDisputeDenied(
                            RmeBillingDisputeDenied::SELF_DESIGNATION,
                            'Anda tidak dapat menunjuk diri sendiri sebagai peninjau tagihan cabang.',
                        );
                    }

                    // The branch is the request's claim: only an active, RME-enabled
                    // branch can carry billing disputes.
                    if (! in_array($branchId, $this->branches->rmeEnabledIds(), true)) {
                        throw ValidationException::withMessages([
                            'branch_id' => '[BILLING_DISPUTE_REVIEWER_BRANCH_NOT_RME] Cabang harus cabang RME yang aktif.',
                        ]);
                    }

                    $candidate = $this->accounts->resolveUsable($target);
                    if ($candidate === null) {
                        throw ValidationException::withMessages([
                            'user_id' => '[BILLING_DISPUTE_REVIEWER_TARGET_INELIGIBLE] Akun yang ditunjuk tidak aktif atau tidak dikenali.',
                        ]);
                    }

                    // Owner decision: the Owner keeps read-only dispute access.
                    if ($candidate->hasRole(self::OWNER_ROLE)) {
                        throw ValidationException::withMessages([
                            'user_id' => '[BILLING_DISPUTE_REVIEWER_OWNER_READ_ONLY] Akun Owner hanya dapat melihat laporan ketidaksesuaian tagihan.',
                        ]);
                    }

                    // Owner decision (brief §13): the counter reports; it never reviews.
                    if ($candidate->hasAnyRole(self::COUNTER_ROLES)) {
                        throw ValidationException::withMessages([
                            'user_id' => '[BILLING_DISPUTE_REVIEWER_COUNTER_ROLE] Akun loket (Front Office / Kasir) melaporkan ketidaksesuaian tagihan dan tidak dapat ditunjuk sebagai peninjau.',
                        ]);
                    }

                    if ($candidate->can(RmeBillingDisputePermission::REVIEW)) {
                        throw ValidationException::withMessages([
                            'user_id' => '[BILLING_DISPUTE_REVIEWER_REDUNDANT] Akun ini sudah dapat meninjau laporan di semua cabang kerjanya.',
                        ]);
                    }

                    if (! $this->isWorkspaceAccount($candidate)) {
                        throw ValidationException::withMessages([
                            'user_id' => '[BILLING_DISPUTE_REVIEWER_TARGET_INELIGIBLE] Akun yang ditunjuk belum memiliki akses ruang kerja Klinik & RME.',
                        ]);
                    }

                    if ($this->designations->activeFor((int) $candidate->getKey(), $branchId) !== null) {
                        throw $this->duplicate();
                    }

                    $designation = $this->designations->create([
                        'user_id' => (int) $candidate->getKey(),
                        'branch_id' => $branchId,
                        'granted_by' => (int) $governor->getKey(),
                        'granted_at' => now(),
                        'grant_reason' => $reason,
                    ]);

                    $this->auditLogs->log(
                        RmeBillingDisputeAuditEvent::ENTITY_REVIEWER,
                        (int) $designation->getKey(),
                        RmeBillingDisputeAuditEvent::ACTION_REVIEWER_DESIGNATED,
                        null,
                        [
                            'designation_id' => (int) $designation->getKey(),
                            'user_id' => (int) $designation->user_id,
                            'branch_id' => (int) $designation->branch_id,
                            'reason_length' => mb_strlen($reason),
                        ],
                        $governor,
                    );

                    return $designation;
                });
            } catch (QueryException $e) {
                // The partial unique index is the database backstop for "one active
                // designation per account and branch": a racing grant that slipped
                // past the check above is refused here, never stored twice.
                if ($this->isUniqueViolation($e)) {
                    throw $this->duplicate();
                }

                throw $e;
            }
        });
    }

    /**
     * Soft revocation. Idempotent: revoking an already revoked designation changes
     * nothing and writes no second audit row. The row is never deleted.
     */
    public function revoke(User $actor, RmeBillingDisputeReviewer $designation, mixed $reason): RmeBillingDisputeReviewer
    {
        $reason = RmeBillingDisputeText::assert($reason, 'reason', 'Alasan pencabutan');

        return $this->audited('revoke', $actor, (int) $designation->getKey(), (int) $designation->user_id, (int) $designation->branch_id, function () use ($actor, $designation, $reason) {
            return DB::transaction(function () use ($actor, $designation, $reason) {
                $governor = $this->assertGovernor($actor);

                $locked = $this->designations->findForUpdate((int) $designation->getKey());
                if ($locked === null) {
                    throw ValidationException::withMessages([
                        'designation' => '[BILLING_DISPUTE_REVIEWER_NOT_FOUND] Penunjukan peninjau tidak ditemukan.',
                    ]);
                }

                if (! $locked->isActive()) {
                    return $locked;
                }

                $this->designations->revoke($locked, (int) $governor->getKey(), $reason);

                $this->auditLogs->log(
                    RmeBillingDisputeAuditEvent::ENTITY_REVIEWER,
                    (int) $locked->getKey(),
                    RmeBillingDisputeAuditEvent::ACTION_REVIEWER_REVOKED,
                    ['revoked_at' => null],
                    [
                        'designation_id' => (int) $locked->getKey(),
                        'user_id' => (int) $locked->user_id,
                        'branch_id' => (int) $locked->branch_id,
                        'reason_length' => mb_strlen($reason),
                    ],
                    $governor,
                );

                return $locked;
            });
        });
    }

    // ─── Internals ───────────────────────────────────────────────────────────

    /** Usable + governance permission, both asked of the PERSISTED account. */
    private function assertGovernor(User $actor): User
    {
        $account = $this->accounts->resolveUsable($actor);

        if ($account === null) {
            throw new RmeBillingDisputeDenied(
                RmeBillingDisputeDenied::ACTOR_UNUSABLE,
                'Akun ini tidak aktif atau tidak dikenali, sehingga tidak dapat mengatur peninjau tagihan.',
            );
        }

        if (! $account->can(RmeBillingDisputePermission::MANAGE_REVIEWERS)) {
            throw new RmeBillingDisputeDenied(
                RmeBillingDisputeDenied::PERMISSION_MISSING,
                'Anda tidak memiliki izin untuk mengatur peninjau tagihan cabang.',
            );
        }

        return $account;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function audited(string $action, User $actor, ?int $designationId, ?int $targetId, ?int $branchId, Closure $work): mixed
    {
        try {
            return $work();
        } catch (RmeBillingDisputeDenied $denied) {
            $this->logRefusal($action, $actor, $designationId, $targetId, $branchId, $denied->reasonCode);

            throw $denied;
        } catch (ValidationException $refused) {
            // An eligibility refusal (Owner, counter role, redundant, inactive,
            // duplicate, non-RME branch) is a governance event too.
            $this->logRefusal($action, $actor, $designationId, $targetId, $branchId, self::refusalCode($refused));

            throw $refused;
        }
    }

    private function logRefusal(string $action, User $actor, ?int $designationId, ?int $targetId, ?int $branchId, string $code): void
    {
        $this->auditLogs->log(
            RmeBillingDisputeAuditEvent::ENTITY_REVIEWER,
            $designationId,
            RmeBillingDisputeAuditEvent::ACTION_REVIEWER_REFUSED,
            null,
            [
                'attempted_action' => $action,
                'code' => $code,
                'designation_id' => $designationId,
                'user_id' => $targetId,
                'branch_id' => $branchId,
            ],
            $actor,
        );
    }

    /** The stable `[CODE]` of a refusal message, never its free text. */
    private static function refusalCode(ValidationException $refused): string
    {
        foreach ($refused->errors() as $messages) {
            foreach ((array) $messages as $message) {
                if (preg_match('/\[([A-Z0-9_]+)\]/', (string) $message, $match) === 1) {
                    return $match[1];
                }
            }
        }

        return 'VALIDATION_REFUSED';
    }

    private function duplicate(): ValidationException
    {
        return ValidationException::withMessages([
            'user_id' => '[BILLING_DISPUTE_REVIEWER_DUPLICATE] Akun ini sudah menjadi peninjau aktif di cabang tersebut.',
        ]);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $state = (string) $e->getCode();

        return $state === '23505'
            || $state === '23000'
            || str_contains(strtolower($e->getMessage()), 'unique constraint');
    }
}
