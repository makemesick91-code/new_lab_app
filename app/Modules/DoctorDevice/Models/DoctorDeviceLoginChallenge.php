<?php

namespace App\Modules\DoctorDevice\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1.
 *
 * A nonce issued for a PUBLIC KEY FINGERPRINT rather than a device row, so the
 * very first login from brand-new hardware can still prove possession before
 * any registry entry exists. Single-use and time-bounded; `isUsable()` is the
 * only thing that opens the door and it fails closed.
 */
class DoctorDeviceLoginChallenge extends Model
{
    protected $table = 'trx_doctor_device_login_challenges';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'doctor_device_id' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(DoctorDevice::class, 'doctor_device_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isUsable(): bool
    {
        return ! $this->isConsumed() && ! $this->isExpired();
    }
}
