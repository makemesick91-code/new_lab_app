<?php

namespace App\Modules\RmeInvoice\Services;

use App\Models\User;

/**
 * FIX-RME-INVOICE-BRANCH-SCOPE-EXPLICIT-ACTOR-1 (rule 198) — the single answer
 * to "is this EXPLICIT actor a real account that may act in RME billing?".
 *
 * Shared by RmeInvoiceService (invoice creation, cashier read lists, the
 * existing-invoice lookup) and RmePaymentService (rule 197), so the invoice and
 * payment halves of the billing domain can never disagree about who is usable.
 * Both resolve the account here ONCE and ask the billing permission and the
 * working branch of the account returned — never of the object passed in.
 *
 * Every answer is decided against the PERSISTED account, re-read from the
 * database, never against the object the caller handed in:
 *  - an unsaved object carrying a real id is not that account;
 *  - a deactivation or deletion committed elsewhere is seen immediately, even
 *    when the caller still holds an older copy that says "active";
 *  - the billing permission is evaluated on the persisted account, so stale
 *    in-memory roles cannot grant it.
 *
 * It never reads the ambient session. The caller is the trust boundary (an
 * authenticated controller passes $request->user()); this guard only refuses an
 * actor that is not a usable billing account — it never substitutes another.
 */
class RmeBillingActorGuard
{
    public const BILLING_PERMISSION = 'manage_rme_billing';

    /**
     * The persisted, non-deleted, active account behind $actor, or null. The
     * SoftDeletes scope on User excludes a deleted account.
     */
    public function resolveUsable(User $actor): ?User
    {
        if (! $actor->exists || $actor->getKey() === null) {
            return null;
        }

        return User::query()
            ->whereKey($actor->getKey())
            ->where('is_active', true)
            ->first();
    }

    /**
     * The persisted account behind $actor when it is usable AND holds the RME
     * billing permission, or null. Callers authorize branch scope against the
     * account this returns, never against $actor.
     */
    public function resolveBillingActor(User $actor): ?User
    {
        $account = $this->resolveUsable($actor);

        if ($account === null || ! $account->can(self::BILLING_PERMISSION)) {
            return null;
        }

        return $account;
    }
}
