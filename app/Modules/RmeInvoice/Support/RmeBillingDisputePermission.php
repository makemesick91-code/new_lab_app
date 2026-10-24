<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use App\Modules\RmeInvoice\Services\RmeBillingActorGuard;

/**
 * Rule 203 — who may do what with a billing dispute.
 *
 * REPORT reuses the cashier capability on purpose (owner OPS-OQ-1: "prefer the
 * existing Front Office / Cashier / Clinic Admin capabilities"): the accounts
 * that could collect a known-wrong amount are exactly the accounts that must be
 * able to stop it being collected. Always branch-scoped (RmeWorkingBranchScope).
 *
 * REVIEW is a NEW capability granted to NO operational role (Super Admin holds
 * it through RoleSeeder's '*'). It covers taking a dispute into review,
 * escalating it to the Owner and resolving it. The conservative boundary is
 * deliberate: the D1 branch authority (Admin Klinik) cannot be granted it
 * without also granting every Front Office account through the D1b union, which
 * would let front-desk peers clear each other's disputes. Who reviews disputes at
 * branch level is an open owner question (OPS-OQ-1a). REVIEW never grants any
 * correction, price, cancellation, refund or payment power.
 *
 * VIEW is read-only visibility of the dispute queue (Owner oversight).
 */
final class RmeBillingDisputePermission
{
    public const REPORT = RmeBillingActorGuard::BILLING_PERMISSION;

    public const REVIEW = 'review_rme_billing_disputes';

    public const VIEW = 'view_rme_billing_disputes';

    /** Any of these lets an account read disputes (always within its branch scope). */
    public const READ = [self::REPORT, self::REVIEW, self::VIEW];

    /** Any of these lets an account add a note to an active dispute. */
    public const NOTE = [self::REPORT, self::REVIEW];
}
