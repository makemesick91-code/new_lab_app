<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Repositories;

use App\Modules\RmeInvoice\Interfaces\RmeReceiptSnapshotRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Models\RmeReceiptSnapshot;
use Illuminate\Support\Collection;

final class RmeReceiptSnapshotRepository implements RmeReceiptSnapshotRepositoryInterface
{
    public function findForPayment(int $paymentId): ?RmeReceiptSnapshot
    {
        return RmeReceiptSnapshot::query()->where('rme_payment_id', $paymentId)->first();
    }

    public function forInvoice(int $invoiceId): Collection
    {
        return RmeReceiptSnapshot::query()
            ->where('rme_invoice_id', $invoiceId)
            ->orderBy('rme_payment_id')
            ->get();
    }

    public function latestForInvoice(int $invoiceId): ?RmeReceiptSnapshot
    {
        return RmeReceiptSnapshot::query()
            ->where('rme_invoice_id', $invoiceId)
            ->orderByDesc('rme_payment_id')
            ->first();
    }

    public function paymentIdsWithoutSnapshot(int $invoiceId): array
    {
        return RmePayment::query()
            ->where('rme_invoice_id', $invoiceId)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('trx_rme_receipt_snapshots')
                    ->whereColumn('trx_rme_receipt_snapshots.rme_payment_id', 'trx_rme_payments.id');
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function create(array $data): RmeReceiptSnapshot
    {
        return RmeReceiptSnapshot::create($data);
    }
}
