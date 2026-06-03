<?php

use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Models\Payment;
use App\Modules\Reporting\Services\DashboardService;
use App\Modules\Reporting\Services\InvoiceReportService;
use App\Modules\Reporting\Services\PaymentReportService;
use App\Modules\Reporting\Services\RevenueReportService;

beforeEach(function () {
    seedAccessControl();
});

it('excludes VOID invoices from revenue', function () {
    Invoice::factory()->create(['status' => 'ISSUED', 'total_amount' => 1000]);
    Invoice::factory()->create(['status' => 'VOID', 'total_amount' => 500]);

    expect(app(RevenueReportService::class)->summary([])['invoice_revenue'])->toBe(1000.0);
});

it('excludes VOID invoices from outstanding', function () {
    Invoice::factory()->create(['status' => 'ISSUED', 'total_amount' => 1000, 'paid_amount' => 0, 'outstanding_amount' => 1000]);
    Invoice::factory()->create(['status' => 'VOID', 'total_amount' => 800, 'paid_amount' => 0, 'outstanding_amount' => 800]);

    expect(app(InvoiceReportService::class)->outstandingSummary([])['total_outstanding'])->toBe(1000.0);
});

it('sums payments received correctly', function () {
    $invoice = Invoice::factory()->create(['status' => 'ISSUED', 'total_amount' => 1000]);
    Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 300, 'payment_method' => 'CASH']);
    Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 200, 'payment_method' => 'QRIS']);

    expect(app(PaymentReportService::class)->summary([])['total'])->toBe(500.0);
});

it('reports zero revenue on an empty dataset', function () {
    $cards = app(DashboardService::class)->cards([]);

    expect($cards['revenue'])->toBe(0.0);
    expect($cards['outstanding'])->toBe(0.0);
    expect($cards['total_orders'])->toBe(0);
});

it('groups revenue by month', function () {
    Invoice::factory()->create(['status' => 'ISSUED', 'total_amount' => 1000, 'invoice_date' => '2026-01-15']);
    Invoice::factory()->create(['status' => 'ISSUED', 'total_amount' => 500, 'invoice_date' => '2026-01-20']);
    Invoice::factory()->create(['status' => 'ISSUED', 'total_amount' => 700, 'invoice_date' => '2026-02-10']);

    $byMonth = app(RevenueReportService::class)->byMonth([])->keyBy('month');

    expect((float) $byMonth['2026-01']->amount)->toBe(1500.0);
    expect((float) $byMonth['2026-02']->amount)->toBe(700.0);
});
