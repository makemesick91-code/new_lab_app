<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use App\Modules\RmeInvoice\Models\RmePaymentSubmission;
use RuntimeException;

/**
 * FIX-RME-PAYMENT-SUBMISSION-IDEMPOTENCY-1 (rule 205) — the submission was
 * ALREADY recorded: the same intent, the same account, the same payload.
 *
 * Not a failure. It is the signal RmePaymentService raises (under the invoice
 * lock, before any new write) instead of recording the payment a second time, so
 * the caller can answer with the ORIGINAL result. The payment controller turns it
 * into the same redirect the original submission produced, naming the original
 * receipt number; a non-HTTP caller receives the committed submission it carries.
 */
final class RmePaymentSubmissionReplayed extends RuntimeException
{
    public function __construct(public readonly RmePaymentSubmission $submission)
    {
        parent::__construct('Payment submission #'.(int) $submission->id.' was already recorded.');
    }
}
