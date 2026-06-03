<?php

namespace App\Modules\Invoice\Services;

use App\Modules\Invoice\Interfaces\PaymentRepositoryInterface;

class PaymentNumberGeneratorService
{
    public function __construct(
        private readonly PaymentRepositoryInterface $payments,
    ) {}

    public function generate(): string
    {
        $month = now()->format('Ym');
        $latest = $this->payments->latestPaymentNumberForMonth($month);
        $next = 1;

        if ($latest && preg_match('/^PAY-'.$month.'-(\d{6})$/', $latest, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        return 'PAY-'.$month.'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
