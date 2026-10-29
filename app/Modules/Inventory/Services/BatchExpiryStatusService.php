<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Support\InventoryBusinessCalendar;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class BatchExpiryStatusService
{
    public const STATUS_EXPIRED = 'expired';

    public const STATUS_NEAR_EXPIRY = 'near_expiry';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_NO_EXPIRY = 'no_expiry';

    public const NEAR_EXPIRY_THRESHOLD_DAYS = 90;

    public function __construct(
        private readonly InventoryBusinessCalendar $calendar,
    ) {}

    public function thresholdDays(): int
    {
        return self::NEAR_EXPIRY_THRESHOLD_DAYS;
    }

    public function status(CarbonInterface|string|null $expiryDate): string
    {
        $expiry = $this->normalizeExpiryDate($expiryDate);

        if ($expiry === null) {
            return self::STATUS_NO_EXPIRY;
        }

        $today = $this->calendar->today();

        if ($expiry->lt($today)) {
            return self::STATUS_EXPIRED;
        }

        if ($expiry->lte($this->nearExpiryThresholdDate())) {
            return self::STATUS_NEAR_EXPIRY;
        }

        return self::STATUS_ACTIVE;
    }

    public function label(CarbonInterface|string|null $expiryDate): string
    {
        return match ($this->status($expiryDate)) {
            self::STATUS_EXPIRED => 'Kedaluwarsa',
            self::STATUS_NEAR_EXPIRY => 'Akan Kedaluwarsa',
            self::STATUS_ACTIVE => 'Aktif',
            default => 'Tanpa Expired',
        };
    }

    public function daysText(CarbonInterface|string|null $expiryDate): string
    {
        $expiry = $this->normalizeExpiryDate($expiryDate);

        if ($expiry === null) {
            return 'Tanpa tanggal kedaluwarsa';
        }

        $today = $this->calendar->today();
        $days = (int) $today->diffInDays($expiry, false);

        if ($days < 0) {
            return 'Kedaluwarsa '.abs($days).' hari lalu';
        }

        if ($days === 0) {
            return 'Kedaluwarsa hari ini';
        }

        return 'Sisa '.$days.' hari';
    }

    public function isExpired(CarbonInterface|string|null $expiryDate): bool
    {
        return $this->status($expiryDate) === self::STATUS_EXPIRED;
    }

    public function isNearExpiry(CarbonInterface|string|null $expiryDate): bool
    {
        return $this->status($expiryDate) === self::STATUS_NEAR_EXPIRY;
    }

    public function isActive(CarbonInterface|string|null $expiryDate): bool
    {
        return $this->status($expiryDate) === self::STATUS_ACTIVE;
    }

    public function isNoExpiry(CarbonInterface|string|null $expiryDate): bool
    {
        return $this->status($expiryDate) === self::STATUS_NO_EXPIRY;
    }

    public function nearExpiryThresholdDate(): Carbon
    {
        return $this->calendar->today()->addDays(self::NEAR_EXPIRY_THRESHOLD_DAYS);
    }

    private function normalizeExpiryDate(CarbonInterface|string|null $expiryDate): ?Carbon
    {
        if ($expiryDate === null) {
            return null;
        }

        if ($expiryDate instanceof CarbonInterface) {
            return Carbon::parse($expiryDate)->startOfDay();
        }

        return Carbon::parse($expiryDate)->startOfDay();
    }
}
