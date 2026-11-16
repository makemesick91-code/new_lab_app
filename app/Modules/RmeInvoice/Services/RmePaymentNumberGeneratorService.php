<?php

namespace App\Modules\RmeInvoice\Services;

use App\Modules\RmeInvoice\Interfaces\RmePaymentRepositoryInterface;
use App\Support\Clinical\ClinicalClock;

class RmePaymentNumberGeneratorService
{
    public function __construct(
        private readonly RmePaymentRepositoryInterface $payments,
        private readonly ClinicalClock $clock,
    ) {}

    public function generate(): string
    {
        // DMS-RME-COMPREHENSIVE-CLOSURE-1 (P8R-S6, CG-12) — the month is the
        // clinic's month (ClinicalClock, Asia/Makassar), as the consent number
        // already was: from 00:00 to 07:59 WITA on the 1st, UTC is still on the
        // previous month. Numbers already issued are never renumbered (rule 227
        // §4); one $month still drives the lock key, the MAX read and the prefix.
        $month = $this->clock->today()->format('Ym');

        // DMS-RME-REVISION-PROGRAM-8B / D2 — take the month's sequence lock
        // before reading the highest number, so two concurrent writers cannot
        // both choose the same next number.
        $this->payments->lockNumberSequence($month);
        $latest = $this->payments->latestPaymentNumberForMonth($month);
        $next = 1;

        if ($latest && preg_match('/^RMEPAY-'.$month.'-(\d{6})$/', $latest, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        return 'RMEPAY-'.$month.'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
