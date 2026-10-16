<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — an AUTHORIZATION refusal
 * raised inside the correction service (self-approval, missing permission, branch
 * out of scope, not the maker). Renders as HTTP 403.
 *
 * It carries a stable `reasonCode` so the service can write the
 * RME_INVOICE_CORRECTION_REFUSED audit row AFTER the transaction rolled back —
 * an attempt is evidence even though it changed nothing.
 */
final class RmeInvoiceCorrectionDenied extends AuthorizationException
{
    public const SELF_APPROVAL = 'SELF_APPROVAL_FORBIDDEN';

    public const PERMISSION_MISSING = 'PERMISSION_MISSING';

    public const BRANCH_OUT_OF_SCOPE = 'BRANCH_OUT_OF_SCOPE';

    public const NOT_THE_MAKER = 'NOT_THE_MAKER';

    public function __construct(
        public readonly string $reasonCode,
        string $message,
        public readonly ?int $correctionId = null,
        public readonly ?int $invoiceId = null,
    ) {
        parent::__construct($message);
    }
}
