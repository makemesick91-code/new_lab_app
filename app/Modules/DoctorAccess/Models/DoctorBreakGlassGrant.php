<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Models;

use App\Models\User;
use App\Modules\Doctor\Models\Doctor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * REVISION-DOCTOR-PWA-WEBAUTHN-ONLY-ACCESS-1 Stage 2 — a bounded emergency
 * admission for ONE doctor account.
 *
 * A grant answers exactly one question: may THIS account hold a session
 * without proving a trusted device, right now? It confers no permission, no
 * role and no branch, and it is never consulted for anything but that.
 *
 * ACTIVE IS COMPUTED, NEVER STORED.
 *
 * There is no `is_active` column on purpose. A stored boolean is a second
 * source of truth that a missed scheduler run, a crashed worker or a forgotten
 * backfill turns into a permanent bypass — which is precisely the "unbounded
 * database boolean" this capability forbids. Expiry is resolved from the
 * current clock on every read, so a grant cannot outlive its window because
 * nothing ran.
 */
class DoctorBreakGlassGrant extends Model
{
    protected $table = 'trx_doctor_break_glass_grants';

    /**
     * Nothing is fillable, matching DoctorSessionLease and
     * DoctorDeviceAuthorization: every column here is either the grant itself
     * or a lifecycle decision, and a request payload must never drive one. The
     * service writes with forceFill.
     *
     * @var array<int, string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'doctor_id' => 'integer',
            'granted_by' => 'integer',
            'revoked_by' => 'integer',
            'granted_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'first_used_at' => 'datetime',
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

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Has the window closed?
     *
     * Read against the clock at call time rather than a stored flag, so a
     * scheduler that never ran cannot leave an expired grant admitting logins.
     */
    public function isExpired(?Carbon $at = null): bool
    {
        $at ??= Carbon::now();

        return $this->expires_at === null || $this->expires_at->lessThanOrEqualTo($at);
    }

    /**
     * The only question the authentication gate asks of this row.
     *
     * Revocation beats the window: an operator who revokes at minute three of
     * a twelve-hour grant has ended it, and the remaining eleven hours are not
     * a grace period.
     */
    public function isActive(?Carbon $at = null): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired($at);
    }

    /**
     * Operator-facing state, for the admin screen and for audit payloads.
     *
     * Deliberately derived from the same two predicates the gate uses, so a
     * screen can never show ACTIVE for a row the gate is refusing.
     */
    public function state(?Carbon $at = null): string
    {
        if ($this->isRevoked()) {
            return 'revoked';
        }

        return $this->isExpired($at) ? 'expired' : 'active';
    }
}
