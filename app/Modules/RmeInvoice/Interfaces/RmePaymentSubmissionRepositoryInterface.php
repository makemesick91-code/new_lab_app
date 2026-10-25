<?php

namespace App\Modules\RmeInvoice\Interfaces;

use App\Modules\RmeInvoice\Models\RmePaymentSubmission;

/**
 * Rule 205 — persistence of committed payment submissions. A lookup by intent
 * key only: there is deliberately no listing or search, because nothing in the
 * payment flow needs to enumerate other cashiers' submissions.
 */
interface RmePaymentSubmissionRepositoryInterface
{
    public function findByIntentKey(string $intentKey): ?RmePaymentSubmission;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): RmePaymentSubmission;
}
