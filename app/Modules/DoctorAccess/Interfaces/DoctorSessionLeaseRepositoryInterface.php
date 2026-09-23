<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Interfaces;

use App\Modules\DoctorAccess\Models\DoctorSessionLease;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — the single-active-session
 * boundary.
 *
 * THIS INTERFACE IS PRIMITIVE ON PURPOSE. It reads, inserts, releases and
 * renews rows and judges nothing. Whether a second login is DENIED, whether an
 * incumbent is DEAD and may be reclaimed, and which release reason applies are
 * decisions that live in one service, so a console command and the HTTP surface
 * cannot diverge.
 *
 * `trx_doctor_session_leases_active_uq` — UNIQUE(user_id) WHERE released_at IS
 * NULL — is the real guarantee that one user holds at most one unreleased
 * lease, proven by attempting the second insert. A caller must therefore treat
 * a unique violation from {@see self::create()} as the LOST RACE it is: read
 * the incumbent under a lock and judge against it. On PostgreSQL a failed
 * statement poisons the whole transaction, so that insert belongs inside a
 * NESTED transaction (a SAVEPOINT) with the catch OUTSIDE it.
 */
interface DoctorSessionLeaseRepositoryInterface
{
    /**
     * Insert a lease.
     *
     * Writes with forceFill because `$fillable` on the model is deliberately
     * empty: every column here is a lifecycle decision, not request input.
     *
     * May throw a unique violation — see the class docblock.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): DoctorSessionLease;

    /**
     * The user's unreleased lease read `FOR UPDATE`. MUST be inside a
     * transaction. This is the incumbent a claim judges against.
     */
    public function lockActiveForUser(int $userId): ?DoctorSessionLease;

    /**
     * The same row without a lock, for read paths and for display.
     */
    public function activeForUser(int $userId): ?DoctorSessionLease;

    /**
     * The per-request middleware lookup: the unreleased lease carrying this
     * token hash, or null.
     *
     * Null is the ordinary answer for a session that never claimed one, and the
     * caller must let such a session pass through untouched.
     */
    public function activeByTokenHash(string $hash): ?DoctorSessionLease;

    /**
     * Stamp the lease released. Idempotent by contract: releasing an already
     * released lease must not move `released_at`, so the audit keeps the first
     * reason.
     *
     * @param  string  $reason  one of DoctorSessionLease::RELEASE_REASONS
     */
    public function release(
        DoctorSessionLease $lease,
        string $reason,
        ?int $releasedByUserId = null,
    ): DoctorSessionLease;

    /**
     * Refresh `last_seen_at`, and re-stamp `session_id` when the framework has
     * regenerated it.
     *
     * A null `$sessionId` leaves the recorded id alone rather than blanking a
     * NOT NULL column.
     */
    public function renew(DoctorSessionLease $lease, ?string $sessionId = null): DoctorSessionLease;
}
