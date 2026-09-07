<?php

namespace App\Modules\DoctorDevice\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DOCTOR-PWA-WEBAUTHN-1 — a single-use, expiring, ceremony-bound nonce.
 *
 * Everything about replay protection lives in `isUsable()` plus the claim
 * transaction in DoctorDeviceWebAuthnChallengeService. Nothing else may decide
 * that a challenge is still good.
 */
class DoctorDeviceWebAuthnChallenge extends Model
{
    public const CEREMONY_REGISTRATION = 'registration';

    public const CEREMONY_ASSERTION = 'assertion';

    public const CEREMONIES = [
        self::CEREMONY_REGISTRATION,
        self::CEREMONY_ASSERTION,
    ];

    protected $table = 'trx_doctor_device_webauthn_challenges';

    protected $fillable = [
        'uuid',
        'challenge',
        'ceremony',
        'user_id',
        'doctor_device_id',
        'session_id_hash',
        'expires_at',
        'consumed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(DoctorDevice::class, 'doctor_device_id');
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isConsumed() && ! $this->isExpired();
    }
}
