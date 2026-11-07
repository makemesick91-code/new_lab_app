<?php

namespace App\Modules\RmeInvoice\Services;

use App\Modules\RmeInvoice\Interfaces\RmePaymentRepositoryInterface;

class RmePaymentNumberGeneratorService
{
    public function __construct(
        private readonly RmePaymentRepositoryInterface $payments,
    ) {}

    public function generate(): string
    {
        $month = now()->format('Ym');

        // DMS-RME-REVISION-PROGRAM-8B / D2 — take the month's sequence lock
        // before reading the highest number, so two concurrent writers cannot
        // both choose the same next number. The month prefix itself is
        // unchanged (P8R-S6, D3).
        $this->payments->lockNumberSequence($month);
        $latest = $this->payments->latestPaymentNumberForMonth($month);
        $next = 1;

        if ($latest && preg_match('/^RMEPAY-'.$month.'-(\d{6})$/', $latest, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        return 'RMEPAY-'.$month.'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
