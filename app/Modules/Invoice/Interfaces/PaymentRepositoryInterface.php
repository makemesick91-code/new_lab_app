<?php

namespace App\Modules\Invoice\Interfaces;

use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Models\Payment;
use Illuminate\Support\Collection;

interface PaymentRepositoryInterface
{
    public function create(array $data): Payment;

    public function forInvoice(Invoice $invoice): Collection;

    public function sumForInvoice(Invoice $invoice): string;

    public function latestPaymentNumberForMonth(string $month): ?string;
}
