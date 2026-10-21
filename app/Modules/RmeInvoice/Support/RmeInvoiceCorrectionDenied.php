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

    /**
     * ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-2 (rule 195) — the invoice's
     * branch is not on the source-controlled pilot allowlist.
     */
    public const BRANCH_NOT_IN_PILOT = 'BRANCH_NOT_IN_PILOT';

    /**
     * Rule 195 / owner D1a — the CHECKER applies. The account that requested a
     * correction may never be the account that applies it, on any path.
     */
    public const SELF_APPLICATION = 'SELF_APPLICATION_FORBIDDEN';

    /** Rule 197 (owner D1e) — the account is not designated for this action in the pilot. */
    public const PILOT_IDENTITY_NOT_AUTHORIZED = 'PILOT_IDENTITY_NOT_AUTHORIZED';

    /** Rule 197 — the actor is not a persisted, non-deleted, active account. */
    public const PILOT_IDENTITY_ACTOR_UNUSABLE = 'PILOT_IDENTITY_ACTOR_UNUSABLE';

    public function __construct(
        public readonly string $reasonCode,
        string $message,
        public readonly ?int $correctionId = null,
        public readonly ?int $invoiceId = null,
    ) {
        parent::__construct($message);
    }
}
