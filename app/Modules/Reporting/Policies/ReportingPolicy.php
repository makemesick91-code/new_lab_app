<?php

namespace App\Modules\Reporting\Policies;

use App\Models\User;

/**
 * Reporting authorization. Each ability passes when the user holds the specific
 * report permission OR the broad manage_report permission. Super Admin bypasses
 * via Gate::before.
 */
class ReportingPolicy
{
    public function viewDashboard(User $user): bool
    {
        return $user->canAny(['view_dashboard', 'manage_report']);
    }

    public function viewOrderReport(User $user): bool
    {
        return $user->canAny(['view_order_report', 'manage_report']);
    }

    public function viewProductionReport(User $user): bool
    {
        return $user->canAny(['view_production_report', 'manage_report']);
    }

    public function viewQcReport(User $user): bool
    {
        return $user->canAny(['view_qc_report', 'manage_report']);
    }

    public function viewDeliveryReport(User $user): bool
    {
        return $user->canAny(['view_delivery_report', 'manage_report']);
    }

    public function viewInvoiceReport(User $user): bool
    {
        return $user->canAny(['view_invoice_report', 'manage_report']);
    }

    public function viewPaymentReport(User $user): bool
    {
        return $user->canAny(['view_payment_report', 'manage_report']);
    }

    public function exportReport(User $user): bool
    {
        return $user->canAny(['export_report', 'manage_report']);
    }
}
