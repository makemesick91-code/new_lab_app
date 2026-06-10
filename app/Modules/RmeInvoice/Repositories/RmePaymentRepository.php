<?php

namespace App\Modules\RmeInvoice\Repositories;

use App\Modules\RmeInvoice\Interfaces\RmePaymentRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use Illuminate\Support\Collection;

class RmePaymentRepository implements RmePaymentRepositoryInterface
{
    public function create(array $data): RmePayment
    {
        return RmePayment::create($data);
    }

    public function forInvoice(RmeInvoice $invoice): Collection
    {
        return RmePayment::query()
            ->with(['paymentMethod', 'cashier'])
            ->where('rme_invoice_id', $invoice->id)
            ->orderByDesc('paid_at')
            ->get();
    }

    public function latestPaymentNumberForMonth(string $month): ?string
    {
        return RmePayment::query()
            ->where('payment_number', 'like', 'RMEPAY-'.$month.'-%')
            ->max('payment_number');
    }
}
