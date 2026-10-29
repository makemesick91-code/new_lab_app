<?php

namespace App\Modules\Inventory\Support;

use App\Support\Clinical\ClinicalClock;
use Illuminate\Support\Carbon;

/**
 * SECURITY-F4B-CLINIC-CALENDAR-INVENTORY-1 (owner decision D15) — the inventory
 * business calendar is the clinic calendar (Asia/Makassar), never the UTC one.
 *
 * The application timezone is UTC. Between 00:00 and 08:00 WITA the UTC date is
 * still yesterday, so a raw `now()->toDateString()` stamped stock movements,
 * receipts, opnames, transfers and purchase orders with the previous clinic day,
 * let an expired batch leave stock for eight hours after midnight, and shifted
 * every "days since" figure by one.
 *
 * This class is not a second clock. `ClinicalClock` stays the single authority
 * for "what is today's clinic date"; this class only places that date in the
 * frame the inventory date columns use (`movement_date`, `expiry_date`,
 * `received_date` are date casts held at 00:00 in the application timezone), so
 * date arithmetic and comparisons stay in one frame. Comparing a date cast with
 * `ClinicalClock::today()` directly would mix two frames eight hours apart and
 * `diffInDays()` would truncate a whole day away.
 *
 * What it never does: shift a stored date, reinterpret a date a user entered, or
 * touch an event timestamp (`created_at`, `posted_at`, `approved_at` stay UTC
 * instants). It only supplies "today" where code needs a business date.
 */
final class InventoryBusinessCalendar
{
    public function __construct(
        private readonly ClinicalClock $clock,
    ) {}

    /** Today's clinic date at 00:00 in the application frame. A fresh instance every call. */
    public function today(): Carbon
    {
        return Carbon::parse($this->clock->todayString());
    }

    public function todayString(): string
    {
        return $this->clock->todayString();
    }
}
