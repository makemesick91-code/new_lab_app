<?php

use App\Modules\Reporting\Services\DashboardService;
use App\Modules\Reporting\Services\OrderReportService;
use App\Modules\Reporting\Services\InvoiceReportService;
use App\Modules\Reporting\Services\RevenueReportService;
use App\Modules\Reporting\Services\PaymentReportService;
use App\Modules\Reporting\Services\DeliveryReportService;
use App\Modules\Reporting\Services\QcReportService;

$cards = app(DashboardService::class)->cards([]);
echo 'Cards: orders=' . $cards['total_orders'] . ' revenue=' . $cards['revenue'] . ' outstanding=' . $cards['outstanding'] . ' overdue=' . $cards['overdue_invoices'] . ' remakes=' . $cards['remake_count'] . PHP_EOL;

$charts = app(DashboardService::class)->charts([]);
echo 'Charts: status=' . $charts['orders_by_status']->count() . ' revByMonth=' . $charts['revenue_by_month']->count() . ' payByMethod=' . $charts['payments_by_method']->count() . PHP_EOL;

echo 'Orders page total=' . app(OrderReportService::class)->paginate([])->total() . PHP_EOL;
echo 'Invoices summary count=' . app(InvoiceReportService::class)->summary([])['count'] . PHP_EOL;
echo 'Outstanding total=' . app(InvoiceReportService::class)->outstandingSummary([])['total_outstanding'] . PHP_EOL;
echo 'Revenue invoice=' . app(RevenueReportService::class)->summary([])['invoice_revenue'] . PHP_EOL;
echo 'Payments total=' . app(PaymentReportService::class)->summary([])['total'] . PHP_EOL;
echo 'Delivery summary total=' . app(DeliveryReportService::class)->summary([])['total'] . PHP_EOL;
echo 'QC summary total=' . app(QcReportService::class)->summary([])['total'] . PHP_EOL;

// Filtered (date range) smoke
$f = ['date_from' => '2000-01-01', 'date_to' => '2100-12-31'];
echo 'Filtered orders=' . app(OrderReportService::class)->paginate($f)->total() . PHP_EOL;
echo 'Export rows=' . app(OrderReportService::class)->export([])['rows']->count() . PHP_EOL;
