<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

/**
 * FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — the correction permissions.
 *
 * Three SEPARATE operational capabilities so the owner's authority decision (D1)
 * can be expressed exactly, without a role inheriting more than it was given:
 *
 *   request_rme_invoice_correction — file a correction, withdraw one's own
 *   approve_rme_invoice_correction — approve or reject someone else's correction
 *   apply_rme_invoice_correction   — apply an APPROVED correction to the invoice
 *
 * None of them is granted to any role by this sprint (only Super Admin holds
 * them, through RoleSeeder's '*'). Holding `approve` never lets an account decide
 * its own request: maker != checker is enforced inside the service, under the
 * invoice row lock, because Gate::before lets Super Admin pass every policy.
 *
 * `manage_rme_billing` (the cashier permission) grants NONE of these.
 */
final class RmeInvoiceCorrectionPermission
{
    public const REQUEST = 'request_rme_invoice_correction';

    public const APPROVE = 'approve_rme_invoice_correction';

    public const APPLY = 'apply_rme_invoice_correction';

    public const OPERATIONAL = [self::REQUEST, self::APPROVE, self::APPLY];
}
