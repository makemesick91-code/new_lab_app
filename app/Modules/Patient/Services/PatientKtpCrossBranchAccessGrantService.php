<?php

namespace App\Modules\Patient\Services;

use App\Models\User;
use App\Modules\LabOrder\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * SECURITY-PATIENT-KTP-ARCHIVE-BRANCH-ISOLATION-1 — the governed, per-account
 * assignment of cross-branch KTP archive reading.
 *
 * The owner policy: Supervisor RME gets no cross-branch KTP access from the
 * role name; it needs an explicit, per-account grant. The application has no
 * screen that assigns a permission to ONE account (the role screen grants to
 * every holder of a role, which is exactly what must not happen), so without
 * this the only way to grant it would be a production REPL or raw SQL — both
 * forbidden, and both unaudited.
 *
 * Deliberately narrow:
 *  - the actor must be an active Super Admin;
 *  - the target must be an active Supervisor RME holding `manage patients`
 *    (any other role would be a permission expansion the owner did not
 *    approve), and never the actor themselves;
 *  - a written reason is required, and only its LENGTH is audited (operator
 *    prose may name a patient);
 *  - one account at a time, inside a transaction holding the target row.
 *
 * Nothing here runs on deployment. A grant is an owner decision.
 */
class PatientKtpCrossBranchAccessGrantService
{
    public const TARGET_ROLE = 'Supervisor RME';

    public const ACTOR_ROLE = 'Super Admin';

    public const AUDIT_ENTITY = 'users';

    public const AUDIT_GRANTED = 'PATIENT_KTP_CROSS_BRANCH_ACCESS_GRANTED';

    public const AUDIT_REVOKED = 'PATIENT_KTP_CROSS_BRANCH_ACCESS_REVOKED';

    public const REASON_MIN = 10;

    public const REASON_MAX = 1000;

    public function __construct(
        private readonly AuditLogService $audit,
    ) {}

    /**
     * Why this assignment may not happen, or null when it may.
     */
    public function refusal(User $actor, User $target): ?string
    {
        if (! $this->isActive($actor) || ! $actor->hasRole(self::ACTOR_ROLE)) {
            return 'Only an active Super Admin may assign cross-branch KTP access.';
        }

        if ((int) $actor->id === (int) $target->id) {
            return 'An account cannot assign cross-branch KTP access to itself.';
        }

        if (! $this->isActive($target)) {
            return 'The target account is not active.';
        }

        if ($target->hasRole(self::ACTOR_ROLE)) {
            return 'Super Admin already reads across branches; no assignment is needed.';
        }

        if (! $target->hasRole(self::TARGET_ROLE)) {
            return 'Cross-branch KTP access may only be assigned to a Supervisor RME account.';
        }

        if (! $target->can(PatientDocumentAccessService::BASE_PERMISSION)) {
            return 'The target account does not hold manage patients.';
        }

        return null;
    }

    /**
     * A DIRECT grant on this account — the only kind the access decision honours.
     */
    public function holds(User $target): bool
    {
        return $target->getDirectPermissions()->contains('name', PatientDocumentAccessService::CROSS_BRANCH_PERMISSION);
    }

    /**
     * True when the permission reaches this account only through a ROLE (for
     * example someone ticked it in the role editor). The access decision
     * ignores that path, so the account does NOT read across branches; this
     * surfaces the misconfiguration instead of letting it look like a grant.
     */
    public function roleLevelGrantIgnored(User $target): bool
    {
        return ! $target->hasRole(PatientDocumentAccessService::PRIVILEGED_ROLE)
            && ! $this->holds($target)
            && $target->getPermissionsViaRoles()->contains('name', PatientDocumentAccessService::CROSS_BRANCH_PERMISSION);
    }

    /**
     * @return bool true when the assignment changed
     *
     * @throws ValidationException
     */
    public function grant(User $actor, User $target, string $reason): bool
    {
        return $this->apply($actor, $target, $reason, true);
    }

    /**
     * @return bool true when the assignment changed
     *
     * @throws ValidationException
     */
    public function revoke(User $actor, User $target, string $reason): bool
    {
        return $this->apply($actor, $target, $reason, false);
    }

    private function apply(User $actor, User $target, string $reason, bool $grant): bool
    {
        $reason = trim($reason);
        $length = mb_strlen($reason);

        if ($length < self::REASON_MIN || $length > self::REASON_MAX) {
            throw ValidationException::withMessages([
                'reason' => 'A reason of '.self::REASON_MIN.'–'.self::REASON_MAX.' characters is required.',
            ]);
        }

        $changed = DB::transaction(function () use ($actor, $target, $grant, $length): bool {
            $locked = User::query()->whereKey($target->id)->lockForUpdate()->first();

            if ($locked === null) {
                throw ValidationException::withMessages(['user' => 'The target account no longer exists.']);
            }

            // Grant re-checks eligibility under the lock; a revoke must always be
            // possible, even for an account that has since lost its role.
            if ($grant && ($refusal = $this->refusal($actor, $locked)) !== null) {
                throw ValidationException::withMessages(['user' => $refusal]);
            }

            if (! $grant && (! $this->isActive($actor) || ! $actor->hasRole(self::ACTOR_ROLE))) {
                throw ValidationException::withMessages(['actor' => 'Only an active Super Admin may revoke cross-branch KTP access.']);
            }

            $holds = $this->holds($locked);

            if ($grant === $holds) {
                return false;
            }

            $grant
                ? $locked->givePermissionTo(PatientDocumentAccessService::CROSS_BRANCH_PERMISSION)
                : $locked->revokePermissionTo(PatientDocumentAccessService::CROSS_BRANCH_PERMISSION);

            $this->audit->log(
                self::AUDIT_ENTITY,
                (int) $locked->id,
                $grant ? self::AUDIT_GRANTED : self::AUDIT_REVOKED,
                ['holds' => $holds],
                [
                    'holds' => $grant,
                    'permission' => PatientDocumentAccessService::CROSS_BRANCH_PERMISSION,
                    'reason_length' => $length,
                ],
                $actor,
            );

            return true;
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $changed;
    }

    private function isActive(User $user): bool
    {
        return $user->deleted_at === null && (bool) ($user->is_active ?? true);
    }
}
