<?php

namespace Tests\Feature\Hardening;

use Tests\TestCase;

class ReportExportSecurityTest extends TestCase
{
    public function test_guest_cannot_export_order_report(): void
    {
        $this->get('/reports/orders/export')
            ->assertRedirect('/login');
    }

    public function test_guest_cannot_export_invoice_report(): void
    {
        $this->get('/reports/invoices/export')
            ->assertRedirect('/login');
    }

    public function test_guest_cannot_export_payment_report(): void
    {
        $this->get('/reports/payments/export')
            ->assertRedirect('/login');
    }

    public function test_guest_cannot_export_revenue_report(): void
    {
        $this->get('/reports/revenue/export')
            ->assertRedirect('/login');
    }
}
