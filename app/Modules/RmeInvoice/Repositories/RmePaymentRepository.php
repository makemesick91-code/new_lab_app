<?php

namespace App\Modules\RmeInvoice\Repositories;

use App\Modules\RmeInvoice\Interfaces\RmePaymentRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Support\Database\DocumentNumberSequenceLock;
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

    /**
     * DMS-RME-REVISION-PROGRAM-8B / D2 — serialise the month's payment numbering
     * (rule 225 pattern). Must be called inside the transaction that inserts the
     * payment, before {@see latestPaymentNumberForMonth()}.
     */
    public function lockNumberSequence(string $month): void
    {
        DocumentNumberSequenceLock::acquire(DocumentNumberSequenceLock::RME_PAYMENT, $month);
    }

    /**
     * The highest payment number issued this month, soft-deleted rows included
     * (UNIQUE(payment_number) still holds a trashed row's number — P8-N4).
     */
    public function latestPaymentNumberForMonth(string $month): ?string
    {
        return RmePayment::withTrashed()
            ->where('payment_number', 'like', 'RMEPAY-'.$month.'-%')
            ->max('payment_number');
    }

    public function countForInvoice(int $invoiceId): int
    {
        return RmePayment::withTrashed()->where('rme_invoice_id', $invoiceId)->count();
    }
}
