<?php

namespace App\Modules\DoctorDevice\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Database\Factories\DoctorDeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 2 — clinic device registry.
 *
 * Phase 2 is capability only. Nothing in the authentication path reads this
 * model, so an empty registry can never lock a doctor out.
 */
class DoctorDevice extends Model
{
    use HasFactory;

    /** Currently trusted, and eligible for a future enforcement phase. */
    public const STATUS_ACTIVE = 'active';

    /** Temporarily withdrawn. May be reactivated. */
    public const STATUS_DISABLED = 'disabled';

    /** Trust permanently withdrawn. Terminal — never reactivated. */
    public const STATUS_REVOKED = 'revoked';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_DISABLED,
        self::STATUS_REVOKED,
    ];

    /**
     * An administrator typed this row in. It is a REGISTERED DATABASE RECORD
     * and nothing more — the hardware has never proved possession of a key.
     */
    public const IDENTITY_UNVERIFIED = 'unverified';

    /**
     * The device proved possession of its private key against a server-issued
     * challenge. Reserved for Phase 3; unreachable in Phase 2 by construction,
     * so a hand-entered row can never masquerade as a proven device.
     */
    public const IDENTITY_CRYPTOGRAPHICALLY_VERIFIED = 'cryptographically_verified';

    public const IDENTITY_STATES = [
        self::IDENTITY_UNVERIFIED,
        self::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
    ];

    protected $table = 'mst_doctor_devices';

    /**
     * Lifecycle columns (status, identity_state, revoked_*, disabled_*,
     * registered_*) are deliberately NOT fillable. They change only through
     * DoctorDeviceService, so no request payload can drive a state transition
     * by mass assignment.
     */
    protected $fillable = [
        'device_name',
        'branch_id',
        'platform',
        'device_model',
        'os_version',
        'app_version',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'registered_by' => 'integer',
            'disabled_by' => 'integer',
            'revoked_by' => 'integer',
            'registered_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'disabled_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function disabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disabled_by');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isDisabled(): bool
    {
        return $this->status === self::STATUS_DISABLED;
    }

    /** Terminal. A revoked device is never restored to trust. */
    public function isRevoked(): bool
    {
        return $this->status === self::STATUS_REVOKED;
    }

    public function isCryptographicallyVerified(): bool
    {
        return $this->identity_state === self::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED;
    }

    /**
     * A short, safe identity summary for the admin UI. The full fingerprint is
     * not a secret, but there is no reason to spray it across list screens.
     */
    public function shortFingerprint(): ?string
    {
        if (! is_string($this->public_key_fingerprint) || $this->public_key_fingerprint === '') {
            return null;
        }

        return substr($this->public_key_fingerprint, 0, 12);
    }

    protected static function newFactory(): DoctorDeviceFactory
    {
        return DoctorDeviceFactory::new();
    }
}
