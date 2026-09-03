<?php

namespace App\Modules\DoctorDevice\Models;

use App\Models\User;
use App\Modules\Doctor\Models\Doctor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1.
 *
 * A receipt for a login the server has ALREADY approved, handed to a proven
 * device so the WebView can turn it into a session cookie. Stored as a hash,
 * single-use, seconds-long, and bound to user + doctor + device +
 * authorization, so it can never be replayed onto another account or tablet.
 */
class DoctorDeviceLoginTicket extends Model
{
    protected $table = 'trx_doctor_device_login_tickets';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'doctor_id' => 'integer',
            'doctor_device_id' => 'integer',
            'doctor_device_authorization_id' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
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

    public function device(): BelongsTo
    {
        return $this->belongsTo(DoctorDevice::class, 'doctor_device_id');
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(DoctorDeviceAuthorization::class, 'doctor_device_authorization_id');
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
