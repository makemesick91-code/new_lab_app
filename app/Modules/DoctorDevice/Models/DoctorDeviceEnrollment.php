<?php

namespace App\Modules\DoctorDevice\Models;

use App\Models\User;
use Database\Factories\DoctorDeviceEnrollmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Android pairing attempt. The plaintext pairing code is never stored —
 * only its hash — so the database cannot be used to replay an enrolment.
 */
class DoctorDeviceEnrollment extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CONSUMED = 'consumed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CONSUMED,
    ];

    protected $table = 'trx_doctor_device_enrollments';

    /** Every column is service-written; nothing is mass assignable from a request. */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'doctor_device_id' => 'integer',
            'approved_by' => 'integer',
            'rejected_by' => 'integer',
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(DoctorDevice::class, 'doctor_device_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /** Usable only while approved, unconsumed and unexpired. Fails closed. */
    public function isRedeemable(): bool
    {
        return $this->isApproved() && $this->consumed_at === null && ! $this->isExpired();
    }

    protected static function newFactory(): DoctorDeviceEnrollmentFactory
    {
        return DoctorDeviceEnrollmentFactory::new();
    }
}
