<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * Rule 203 — an AUTHORIZATION refusal raised inside RmeBillingDisputeService
 * (unusable actor, missing permission, branch out of scope, self-resolution).
 * Renders as a plain HTTP 403; the stable `reasonCode` goes to the audit row
 * written AFTER the transaction rolled back (an attempt is evidence even though
 * it changed nothing), never into the operator's page.
 */
final class RmeBillingDisputeDenied extends AuthorizationException
{
    public const ACTOR_UNUSABLE = 'ACTOR_UNUSABLE';

    public const PERMISSION_MISSING = 'PERMISSION_MISSING';

    public const BRANCH_OUT_OF_SCOPE = 'BRANCH_OUT_OF_SCOPE';

    /** The reporter may not certify, alone, that the invoice they disputed is right. */
    public const SELF_RESOLUTION = 'SELF_RESOLUTION_FORBIDDEN';

    /** Rule 204 — nobody designates themself as a branch reviewer, Super Admin included. */
    public const SELF_DESIGNATION = 'SELF_DESIGNATION_FORBIDDEN';

    /** Rule 204 §15 — a designee who issued or collected on the invoice confirms nothing about it. */
    public const RESOLVER_NOT_INDEPENDENT = 'RESOLVER_NOT_INDEPENDENT';

    public function __construct(
        public readonly string $reasonCode,
        string $message,
        public readonly ?int $disputeId = null,
        public readonly ?int $invoiceId = null,
    ) {
        parent::__construct($message);
    }
}
