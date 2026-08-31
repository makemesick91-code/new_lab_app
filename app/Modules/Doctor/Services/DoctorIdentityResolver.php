<?php

namespace App\Modules\Doctor\Services;

use App\Models\User;
use App\Modules\Doctor\Models\Doctor;

/**
 * FEATURE-DOCTOR-ACCOUNT-PERFORMANCE-INCOME-LINKAGE-1
 *
 * The single canonical answer to "which doctor is this logged-in user?".
 *
 * Authentication identity (`users`) and clinical identity (`mst_doctors`) are
 * separate concepts joined by exactly one explicit, persisted, unique column:
 * `mst_doctors.user_id`. Nothing else is authoritative.
 *
 * Identity is deliberately NOT inferred from a display name, email address or
 * phone number. Those values are mutable, human-entered and can legitimately
 * collide, and a wrong match here would hand one doctor another doctor's
 * clinical history and income. A human may use them to *choose* the right
 * record in the Master Data linking screen; the system only ever trusts the id.
 *
 * This resolver answers identity only. Whether that doctor may currently
 * practise (`is_active`), which branch they are working from, and what they are
 * permitted to see remain separate decisions owned by their own callers — so
 * consumers keep their existing policies unchanged.
 */
class DoctorIdentityResolver
{
    /**
     * The doctor this account represents, or null when the account has not been
     * linked. Soft-deleted doctor records are excluded by the model's global
     * scope, so a removed doctor never resolves.
     */
    public function resolveForUser(User $user): ?Doctor
    {
        return Doctor::query()
            ->where('user_id', $user->id)
            ->first();
    }

    /**
     * The account this doctor record represents, or null when unlinked.
     */
    public function resolveUserForDoctor(Doctor $doctor): ?User
    {
        if ($doctor->user_id === null) {
            return null;
        }

        return User::query()->find($doctor->user_id);
    }

    /**
     * True when this account is linked to a doctor record.
     */
    public function isLinked(User $user): bool
    {
        return $this->resolveForUser($user) !== null;
    }
}
