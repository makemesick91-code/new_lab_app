<?php

namespace App\Modules\RmeInvoice\Services;

use App\Modules\RmeInvoice\Interfaces\RmeInvoiceRepositoryInterface;

class RmeInvoiceNumberGeneratorService
{
    public function __construct(
        private readonly RmeInvoiceRepositoryInterface $invoices,
    ) {}

    public function generate(): string
    {
        $month = now()->format('Ym');
        $latest = $this->invoices->latestInvoiceNumberForMonth($month);
        $next = 1;

        if ($latest && preg_match('/^RME-'.$month.'-(\d{6})$/', $latest, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        return 'RME-'.$month.'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
