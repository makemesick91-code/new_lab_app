<?php

namespace App\Modules\ClinicVisit\Services;

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\RmeInvoice\Services\RmeBillingActorGuard;

/**
 * AUDIT-RME-CLINIC-VISIT-BRANCH-AUTHORITY-1 — the ONE answer to "is this
 * explicit actor a usable clinic-visit account?" (rule 199).
 *
 * The actor ClinicVisitService is handed is trusted only as far as its ID. The
 * decision is taken against the PERSISTED row, re-read here: it must exist, be
 * active and not be soft-deleted, so a stale object, a deactivated account
 * whose session is still alive, or an unsaved object carrying a real
 * governance id cannot carry authority it no longer has (or never had).
 *
 * Same "judge the persisted row" semantics as
 * {@see RmeBillingActorGuard::resolveUsable()},
 * deliberately NOT the same permission set: billing authority is
 * `manage_rme_billing`; clinic-visit authority is the ClinicVisitPolicy ability
 * the HTTP controller already checks for the same operation. Abilities are asked
 * of the policy rather than restated as permission strings, so this guard and
 * the controller can never disagree about who may do what.
 */
class ClinicVisitActorGuard
{
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

    /** A usable account that may read the visit domain (`viewAny`). */
    public function resolveViewer(User $actor): ?User
    {
        $account = $this->resolveUsable($actor);

        return $account !== null && $account->can('viewAny', ClinicVisit::class) ? $account : null;
    }

    /** A usable account that may register visits (`create`). */
    public function resolveRegistrar(User $actor): ?User
    {
        $account = $this->resolveUsable($actor);

        return $account !== null && $account->can('create', ClinicVisit::class) ? $account : null;
    }

    /** A usable account that holds `$ability` on this specific visit. */
    public function resolveForVisit(User $actor, string $ability, ClinicVisit $visit): ?User
    {
        $account = $this->resolveUsable($actor);

        return $account !== null && $account->can($ability, $visit) ? $account : null;
    }
}
