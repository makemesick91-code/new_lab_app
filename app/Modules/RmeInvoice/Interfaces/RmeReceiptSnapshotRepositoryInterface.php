<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Interfaces;

use App\Modules\RmeInvoice\Models\RmeReceiptSnapshot;
use Illuminate\Support\Collection;

/**
 * FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — persistence for immutable receipt
 * snapshots (owner decision PP-5). Insert-only; the model refuses update/delete.
 */
interface RmeReceiptSnapshotRepositoryInterface
{
    public function findForPayment(int $paymentId): ?RmeReceiptSnapshot;

    /** @return Collection<int, RmeReceiptSnapshot> ordered by payment id */
    public function forInvoice(int $invoiceId): Collection;

    /**
     * The receipt snapshot of the latest payment of an invoice (highest payment
     * id), or null.
     */
    public function latestForInvoice(int $invoiceId): ?RmeReceiptSnapshot;

    /**
     * Ids of the invoice's payments that have no receipt snapshot yet, ascending.
     * Read under the caller's invoice lock.
     *
     * @return list<int>
     */
    public function paymentIdsWithoutSnapshot(int $invoiceId): array;

    /** @param array<string, mixed> $data */
    public function create(array $data): RmeReceiptSnapshot;
}
