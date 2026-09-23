<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Repositories;

use App\Modules\DoctorAccess\Interfaces\DoctorSessionLeaseRepositoryInterface;
use App\Modules\DoctorAccess\Models\DoctorSessionLease;

class DoctorSessionLeaseRepository implements DoctorSessionLeaseRepositoryInterface
{
    /**
     * forceFill, because `$fillable` on the model is empty by design: every
     * column is a lifecycle decision and none of them may be driven by request
     * input.
     *
     * This insert is the one that races. `trx_doctor_session_leases_active_uq`
     * rejects a second unreleased lease for the same user, so the caller must
     * run it inside a NESTED transaction and catch the unique violation
     * OUTSIDE that savepoint — on PostgreSQL a failed statement aborts the
     * enclosing transaction and every later query raises 25P02.
     */
    public function create(array $attributes): DoctorSessionLease
    {
        $lease = new DoctorSessionLease;

        $lease->forceFill($attributes)->save();

        return $lease;
    }

    public function lockActiveForUser(int $userId): ?DoctorSessionLease
    {
        return DoctorSessionLease::query()
            ->where('user_id', $userId)
            ->whereNull('released_at')
            ->lockForUpdate()
            ->first();
    }

    public function activeForUser(int $userId): ?DoctorSessionLease
    {
        return DoctorSessionLease::query()
            ->where('user_id', $userId)
            ->whereNull('released_at')
            ->first();
    }

    public function activeByTokenHash(string $hash): ?DoctorSessionLease
    {
        return DoctorSessionLease::query()
            ->where('session_token_hash', $hash)
            ->whereNull('released_at')
            ->first();
    }

    /**
     * Releasing an already released lease is a no-op.
     *
     * The reason and the moment are audit evidence: a second call would
     * overwrite why the session actually ended with whatever the later caller
     * happened to be doing.
     */
    public function release(
        DoctorSessionLease $lease,
        string $reason,
        ?int $releasedByUserId = null,
    ): DoctorSessionLease {
        if ($lease->released_at !== null) {
            return $lease;
        }

        $lease->forceFill([
            'released_at' => now(),
            'released_reason' => $reason,
            'released_by_user_id' => $releasedByUserId,
        ])->save();

        return $lease->refresh();
    }

    /**
     * A null `$sessionId` leaves the recorded id alone.
     *
     * `session_id` is NOT NULL, and the framework regenerates the id on login
     * and on every re-authentication, so a caller that has nothing new to say
     * must not blank the column.
     */
    public function renew(DoctorSessionLease $lease, ?string $sessionId = null): DoctorSessionLease
    {
        $attributes = ['last_seen_at' => now()];

        if ($sessionId !== null) {
            $attributes['session_id'] = $sessionId;
        }

        $lease->forceFill($attributes)->save();

        return $lease;
    }
}
