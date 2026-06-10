<?php

namespace App\Modules\RmeInvoice\Interfaces;

use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use Illuminate\Support\Collection;

interface RmePaymentRepositoryInterface
{
    public function create(array $data): RmePayment;

    public function forInvoice(RmeInvoice $invoice): Collection;

    public function latestPaymentNumberForMonth(string $month): ?string;
}
