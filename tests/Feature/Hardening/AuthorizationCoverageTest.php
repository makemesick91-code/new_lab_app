<?php

namespace Tests\Feature\Hardening;

use Tests\TestCase;

class AuthorizationCoverageTest extends TestCase
{
    public function test_guest_cannot_access_reports_dashboard(): void
    {
        $this->get('/reports/dashboard')
            ->assertRedirect('/login');
    }

    public function test_guest_cannot_access_invoice_list(): void
    {
        $this->get('/invoices')
            ->assertRedirect('/login');
    }

    public function test_guest_cannot_access_payment_report(): void
    {
        $this->get('/reports/payments')
            ->assertRedirect('/login');
    }

    public function test_guest_cannot_access_invoice_report(): void
    {
        $this->get('/reports/invoices')
            ->assertRedirect('/login');
    }

    public function test_guest_cannot_access_revenue_report(): void
    {
        $this->get('/reports/revenue')
            ->assertRedirect('/login');
    }

    public function test_guest_cannot_access_order_report_export(): void
    {
        $this->get('/reports/orders/export')
            ->assertRedirect('/login');
    }
}
