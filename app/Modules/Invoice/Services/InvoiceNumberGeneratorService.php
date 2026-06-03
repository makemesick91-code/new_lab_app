<?php

namespace App\Modules\Invoice\Services;

use App\Modules\Invoice\Interfaces\InvoiceRepositoryInterface;

class InvoiceNumberGeneratorService
{
    public function __construct(
        private readonly InvoiceRepositoryInterface $invoices,
    ) {}

    public function generate(): string
    {
        $month = now()->format('Ym');
        $latest = $this->invoices->latestInvoiceNumberForMonth($month);
        $next = 1;

        if ($latest && preg_match('/^INV-'.$month.'-(\d{6})$/', $latest, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        return 'INV-'.$month.'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
