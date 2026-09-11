<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Models;

use App\Models\User;
use App\Modules\Doctor\Models\Doctor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — the claim that says 'this
 * doctor currently holds this session'.
 *
 * Before this row existed there was nothing outside the owning request that
 * could answer that question: the whole session binding is six session-
 * payload keys (DoctorDeviceSessionService.php:163-179), all of them inside
 * the session they describe.
 *
 * THERE IS EXACTLY ONE LEASE MODEL, AND IT IS THIS ONE. It sits in
 * App\Modules\DoctorAccess\Models rather than beside DoctorDeviceAuthorization,
 * because a middleware under an App\Modules\DoctorDevice\... namespace is
 * structurally forbidden: the route-middleware scan at
 * tests/Feature/DoctorDevice/DoctorDeviceApiAndNoEnforcementTest.php:246-278
 * asserts that any registered middleware whose class string contains
 * 'DoctorDevice' IS EnsureDoctorDeviceSession, and the collision is on the
 * namespace segment, so renaming the class would not save it. The lease, its
 * middleware and its listener therefore share one new bounded context.
 *
 * AT MOST ONE UNRELEASED ROW PER USER, and the database says so — a partial
 * unique index on (user_id) WHERE released_at IS NULL. Released rows stay as
 * the audit trail, which is why the key is a nullable stamp and not a boolean.
 *
 * REFUSED, NOT EVICTED. A second login is denied; the first is untouched. That
 * is the requirement, and it constrains two things this class must not tempt
 * anyone into:
 *  - there is NO idle reclaim. `last_seen_at` is display and audit only. A
 *    lease is reclaimable when its `sessions` row is gone — dead — never when
 *    it is merely quiet.
 *  - the deny path must NOT use Auth::guard('web')->logout(), which cycles the
 *    SHARED users.remember_token (SessionGuard::logout() :650 -> :657) and
 *    would therefore partially break the session it just refused to evict.
 *    logoutCurrentDevice() (:680) does not cycle it.
 *
 * THIS MODEL CARRIES NO BRANCH, AND THAT IS THE SCOPE OF THIS PULL REQUEST
 * RATHER THAN AN OVERSIGHT. Binding a session to the branch it was established
 * under needs a resolver that can answer what that branch IS, and that
 * resolver — with the home lock and the temporary cover it reads — ships
 * separately. Every column, cast and relation here has a writer in this pull
 * request; nothing is reserved for later.
 */
class DoctorSessionLease extends Model
{
    /** The doctor logged out. The ordinary release. */
    public const RELEASE_LOGOUT = 'logout';

    /**
     * The incumbent's `sessions` row was gone, so the lease was reclaimed by
     * the next login. NOT an idle timeout — dead, not quiet.
     */
    public const RELEASE_DEAD_SESSION_RECLAIMED = 'dead_session_reclaimed';

    /** An approver cleared a stuck lease. `released_by_user_id` names them. */
    public const RELEASE_ADMIN = 'admin_release';

    /** The device session was torn down (revocation, proof no longer valid). */
    public const RELEASE_DEVICE_INVALIDATED = 'device_invalidated';

    /** The account was deleted. */
    public const RELEASE_ACCOUNT_DELETED = 'account_deleted';

    public const RELEASE_REASONS = [
        self::RELEASE_LOGOUT,
        self::RELEASE_DEAD_SESSION_RECLAIMED,
        self::RELEASE_ADMIN,
        self::RELEASE_DEVICE_INVALIDATED,
        self::RELEASE_ACCOUNT_DELETED,
    ];

    protected $table = 'trx_doctor_session_leases';

    /**
     * Nothing is fillable. Every column is either the claim itself or a
     * lifecycle decision, and a request payload must never drive one — the
     * reading DoctorDeviceAuthorization states at :65-70. The service writes
     * with forceFill.
     *
     * @var array<int, string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'doctor_id' => 'integer',
            'released_by_user_id' => 'integer',
            'claimed_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_user_id');
    }

    /** The predicate the partial unique index is built on. Keep them identical. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }

    public function isActive(): bool
    {
        return $this->released_at === null;
    }

    /**
     * Is the request in front of us the holder of this lease?
     *
     * hash_equals rather than ===, because this is a comparison against a
     * value derived from a bearer credential.
     */
    public function matchesTokenHash(string $candidateHash): bool
    {
        return hash_equals((string) $this->session_token_hash, $candidateHash);
    }
}
