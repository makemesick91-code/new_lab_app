<?php

namespace App\Modules\DoctorDevice\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DOCTOR-PWA-WEBAUTHN-1 — a public key that proves one browser is running on
 * one approved clinic device.
 *
 * A credential is NOT an authorization. `isUsable()` answers only "is this key
 * still one we will verify a signature against". Whether the doctor behind that
 * signature may work on that device is `DoctorDeviceAuthorization`, and whether
 * the device itself is trusted is `DoctorDevice`. All three are checked, in that
 * order, on every login — see DoctorDeviceWebAuthnLoginService.
 */
class DoctorDeviceWebAuthnCredential extends Model
{
    /** The credential is confined to the authenticator that created it. */
    public const VERDICT_DEVICE_BOUND = 'device_bound';

    /** The authenticator says the key MAY be synced to other devices. */
    public const VERDICT_BACKUP_ELIGIBLE = 'backup_eligible';

    /** The authenticator said nothing. Silence is not a pass. */
    public const VERDICT_UNKNOWN = 'unknown';

    protected $table = 'trx_doctor_device_webauthn_credentials';

    protected $fillable = [
        'uuid',
        'doctor_device_id',
        'credential_id',
        'public_key',
        'signature_counter',
        'aaguid',
        'transports',
        'attachment',
        'user_verified',
        'backup_eligible',
        'backup_state',
        'device_bound_verdict',
        'attestation_format',
        'registered_at',
        'registered_by',
        'last_used_at',
        'revoked_at',
        'revoked_by',
        'revoked_reason',
    ];

    protected $casts = [
        'transports' => 'array',
        'signature_counter' => 'integer',
        'user_verified' => 'boolean',
        'backup_eligible' => 'boolean',
        'backup_state' => 'boolean',
        'registered_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(DoctorDevice::class, 'doctor_device_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * May a signature from this key still be accepted?
     *
     * Deliberately narrow. It does not consult the device or the authorization,
     * because a method that answered the whole question here would be a second
     * copy of the login decision, and the two copies would eventually disagree.
     */
    public function isUsable(): bool
    {
        return ! $this->isRevoked();
    }

    /**
     * Does this credential satisfy the physical-device trust policy?
     *
     * `unknown` is NOT a pass. An authenticator that declined to report backup
     * flags has not told us the key is confined to it, and an unstated property
     * is not a measured one.
     */
    public function isDeviceBound(): bool
    {
        return $this->device_bound_verdict === self::VERDICT_DEVICE_BOUND;
    }
}
