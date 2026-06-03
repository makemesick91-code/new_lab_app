<?php

namespace App\Modules\Invoice\Repositories;

use App\Modules\Invoice\Interfaces\PaymentRepositoryInterface;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Models\Payment;
use Illuminate\Support\Collection;

class PaymentRepository implements PaymentRepositoryInterface
{
    public function create(array $data): Payment
    {
        return Payment::create($data);
    }

    public function forInvoice(Invoice $invoice): Collection
    {
        return Payment::query()
            ->with(['receiver', 'creator'])
            ->where('invoice_id', $invoice->id)
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->get();
    }

    public function sumForInvoice(Invoice $invoice): string
    {
        return (string) Payment::query()
            ->where('invoice_id', $invoice->id)
            ->sum('amount');
    }

    public function latestPaymentNumberForMonth(string $month): ?string
    {
        return Payment::query()
            ->where('payment_number', 'like', "PAY-{$month}-%")
            ->orderByDesc('payment_number')
            ->value('payment_number');
    }
}
