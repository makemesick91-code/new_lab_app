<?php

namespace App\Modules\RmeInvoice\Interfaces;

use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use Illuminate\Support\Collection;

interface RmePaymentRepositoryInterface
{
    public function create(array $data): RmePayment;

    public function forInvoice(RmeInvoice $invoice): Collection;

    /** DMS-RME-REVISION-PROGRAM-8B / D2 — the month's number sequence lock (inside a transaction). */
    public function lockNumberSequence(string $month): void;

    public function latestPaymentNumberForMonth(string $month): ?string;

    /**
     * FEATURE-RME-RP0-CONTROL-CONSULTATION-1 (rule 217) — every payment row ever
     * recorded against the invoice, soft-deleted ones INCLUDED: an invoice that
     * ever held money is never "free".
     */
    public function countForInvoice(int $invoiceId): int;
}
