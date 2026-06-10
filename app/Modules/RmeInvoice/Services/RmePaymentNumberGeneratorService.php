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
        $latest = $this->payments->latestPaymentNumberForMonth($month);
        $next = 1;

        if ($latest && preg_match('/^RMEPAY-'.$month.'-(\d{6})$/', $latest, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        return 'RMEPAY-'.$month.'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
