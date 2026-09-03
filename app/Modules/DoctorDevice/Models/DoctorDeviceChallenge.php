<?php

namespace App\Modules\DoctorDevice\Models;

use Database\Factories\DoctorDeviceChallengeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A server-issued nonce the device must sign. Single-use and time-bounded:
 * `isUsable()` is the only thing that may open the door, and it fails closed.
 */
class DoctorDeviceChallenge extends Model
{
    use HasFactory;

    protected $table = 'trx_doctor_device_challenges';

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

    protected static function newFactory(): DoctorDeviceChallengeFactory
    {
        return DoctorDeviceChallengeFactory::new();
    }
}
