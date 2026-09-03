<?php

namespace App\Modules\DoctorDevice\Models;

use App\Models\User;
use App\Modules\Doctor\Models\Doctor;
use Database\Factories\DoctorDeviceAuthorizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1.
 *
 * "This doctor may use this device." Separate from the physical device on
 * purpose: one clinic tablet serves several doctors, so the device is
 * registered once and each doctor gets their own authorization.
 *
 * ONE ROW PER (doctor, device) PAIR, enforced by a unique index. Lifecycle is
 * expressed by mutating this row and preserving every stamp; history lives here
 * and in the append-only audit trail, never in duplicate rows.
 */
class DoctorDeviceAuthorization extends Model
{
    use HasFactory;

    /** Awaiting an approver's decision. Created automatically on first login. */
    public const STATUS_PENDING = 'pending';

    /**
     * Approved. The single canonical "approved" state — named ACTIVE to match
     * DoctorDevice::STATUS_ACTIVE rather than carrying two words for one idea.
     */
    public const STATUS_ACTIVE = 'active';

    /**
     * Refused. NOT a loop: the next login reports this and creates nothing.
     * Reopening requires a privileged `allow re-request`.
     */
    public const STATUS_REJECTED = 'rejected';

    /** Previously approved, trust withdrawn. Terminal for ordinary actions. */
    public const STATUS_REVOKED = 'revoked';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_REJECTED,
        self::STATUS_REVOKED,
    ];

    /** Created automatically by a proven device at doctor login. */
    public const SOURCE_APP_LOGIN = 'app_login';

    /** Created by an administrator ahead of time. */
    public const SOURCE_ADMIN = 'admin';

    public const SOURCES = [
        self::SOURCE_APP_LOGIN,
        self::SOURCE_ADMIN,
    ];

    protected $table = 'mst_doctor_device_authorizations';

    /**
     * Nothing is fillable. Every column here is a lifecycle decision, and a
     * request payload must never be able to drive one by mass assignment — a
     * fillable `status` would be an approval bypass.
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'doctor_id' => 'integer',
            'doctor_device_id' => 'integer',
            'requested_by' => 'integer',
            'approved_by' => 'integer',
            'rejected_by' => 'integer',
            'revoked_by' => 'integer',
            're_request_allowed_by' => 'integer',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'revoked_at' => 'datetime',
            're_request_allowed_at' => 'datetime',
            're_request_allowed_for_rejected_at' => 'datetime',
            'last_authorized_login_at' => 'datetime',
        ];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(DoctorDevice::class, 'doctor_device_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function reRequestAllowedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 're_request_allowed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /** Terminal for ordinary actions — a fresh lifecycle is required. */
    public function isRevoked(): bool
    {
        return $this->status === self::STATUS_REVOKED;
    }

    /**
     * May a rejected pair be reopened right now?
     *
     * An allowance names the exact rejection it forgives, and is live only
     * while that is still the current one. So reject → allow → reopen →
     * reject again leaves the old allowance pointing at a superseded rejection,
     * and it is spent — without erasing either decision.
     *
     * This deliberately does NOT compare "allowance newer than rejection".
     * That reads as equivalent and is not: an approver who allows a re-request
     * in the same second as the rejection produces two equal timestamps, and a
     * strict `>` then refuses the legitimate path. Identity of the forgiven
     * rejection is exact; clock ordering is not.
     */
    public function isReRequestAllowed(): bool
    {
        if (! $this->isRejected() || $this->re_request_allowed_at === null) {
            return false;
        }

        $forgiven = $this->re_request_allowed_for_rejected_at;

        if ($this->rejected_at === null) {
            return $forgiven === null;
        }

        return $forgiven !== null && $forgiven->equalTo($this->rejected_at);
    }

    protected static function newFactory(): DoctorDeviceAuthorizationFactory
    {
        return DoctorDeviceAuthorizationFactory::new();
    }
}
