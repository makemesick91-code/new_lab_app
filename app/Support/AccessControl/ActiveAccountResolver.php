<?php

namespace App\Support\AccessControl;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * HARDENING-RME-ACTIVE-USER-PATIENT-SELECTION-1 — the ONE answer to "may this
 * account still act at all?" (rule 200).
 *
 * The schema has exactly two account-status facts: `users.is_active` and the
 * SoftDeletes `deleted_at`. There is no "suspended" state and none is invented
 * here. An account is usable only when its PERSISTED row exists, is active and
 * is not soft-deleted.
 *
 * The decision is always taken against the row re-read from the database, never
 * against the object the caller holds: a session keeps the model it loaded, an
 * `actingAs()` test keeps the object it was given, and an unsaved object can
 * carry a real id. Each of those can claim an authority the account no longer
 * has (or never had).
 *
 * Shared by the web session middleware (`EnsureAccountIsActive`),
 * the clinic-visit actor guard and the RME billing actor guard, so the three can
 * never disagree about which accounts are usable. Abilities and branch scope are
 * deliberately NOT decided here — each domain asks its own policy of the account
 * this returns.
 *
 * Deliberately not memoised: a memo on a container- or route-cached object
 * outlives the request (rule 199 §27d), and one indexed primary-key read is the
 * whole cost.
 */
class ActiveAccountResolver
{
    /**
     * Shown only to someone who has just proven the password, or whose session
     * was already authenticated — never to an anonymous guess, so it reveals
     * nothing about which addresses have accounts.
     */
    public const INACTIVE_MESSAGE = 'Akun Anda tidak aktif. Hubungi administrator.';

    /** The persisted, active, non-deleted account behind `$account`, or null. */
    public function resolveUsable(?Authenticatable $account): ?User
    {
        $id = $this->persistedId($account);

        if ($id === null) {
            return null;
        }

        // User::query() carries the SoftDeletes scope, so a soft-deleted row
        // resolves to null here exactly as it does in the session provider.
        return User::query()
            ->whereKey($id)
            ->where('is_active', true)
            ->first();
    }

    public function isUsable(?Authenticatable $account): bool
    {
        $id = $this->persistedId($account);

        if ($id === null) {
            return false;
        }

        return User::query()
            ->whereKey($id)
            ->where('is_active', true)
            ->exists();
    }

    private function persistedId(?Authenticatable $account): int|string|null
    {
        if (! $account instanceof User || ! $account->exists) {
            return null;
        }

        return $account->getKey();
    }
}
