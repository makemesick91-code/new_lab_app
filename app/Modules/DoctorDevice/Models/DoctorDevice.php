<?php

namespace App\Modules\DoctorDevice\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Database\Factories\DoctorDeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /**
     * REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 — auto-provisioned
     * hardware that has proved possession of its key but that no administrator
     * has admitted yet.
     *
     * STRICTLY LESS PRIVILEGED THAN EVERY STATUS THAT CAME BEFORE IT. Nothing
     * that was denied in Phase 3 becomes permitted: `isActive()` is false, so
     * the proof endpoint, `isTrustworthy()` and login-ticket minting all still
     * refuse it. It exists so the first doctor login can register the tablet
     * without also trusting it, which is what lets one operator decision cover
     * both the device and the doctor.
     */
    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    /** Temporarily withdrawn. May be reactivated. */
    public const STATUS_DISABLED = 'disabled';

    /** Trust permanently withdrawn. Terminal — never reactivated. */
    public const STATUS_REVOKED = 'revoked';

    public const STATUSES = [
        self::STATUS_PENDING_APPROVAL,
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

    /**
     * Phase 3 enrolment protocol state. Deliberately SEPARATE from `status`:
     * `status` is the administrative decision, this is where the device sits in
     * the pairing protocol. The proof endpoint requires BOTH.
     */
    public const ENROLLMENT_NOT_ENROLLED = 'not_enrolled';

    public const ENROLLMENT_PENDING = 'pending';

    public const ENROLLMENT_VERIFIED = 'verified';

    public const ENROLLMENT_REJECTED = 'rejected';

    public const ENROLLMENT_STATUSES = [
        self::ENROLLMENT_NOT_ENROLLED,
        self::ENROLLMENT_PENDING,
        self::ENROLLMENT_VERIFIED,
        self::ENROLLMENT_REJECTED,
    ];

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
            'verified_by' => 'integer',
            'enrollment_requested_at' => 'datetime',
            'verified_at' => 'datetime',
            'last_verified_at' => 'datetime',
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

    /**
     * Registered by a proven device, not yet admitted by a human. Never trusted:
     * `isActive()` is deliberately false for this status.
     */
    public function isPendingApproval(): bool
    {
        return $this->status === self::STATUS_PENDING_APPROVAL;
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

    public function enrollments(): HasMany
    {
        return $this->hasMany(DoctorDeviceEnrollment::class, 'doctor_device_id');
    }

    /**
     * Which doctors may use this device. One physical tablet legitimately
     * serves several doctors, so this is a collection, not a column.
     */
    public function authorizations(): HasMany
    {
        return $this->hasMany(DoctorDeviceAuthorization::class, 'doctor_device_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isEnrollmentVerified(): bool
    {
        return $this->enrollment_status === self::ENROLLMENT_VERIFIED;
    }

    protected static function newFactory(): DoctorDeviceFactory
    {
        return DoctorDeviceFactory::new();
    }
}
