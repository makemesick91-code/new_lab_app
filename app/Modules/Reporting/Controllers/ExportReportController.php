<?php

namespace App\Modules\Reporting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Requests\DeliveryReportRequest;
use App\Modules\Reporting\Requests\InvoiceReportRequest;
use App\Modules\Reporting\Requests\OrderReportRequest;
use App\Modules\Reporting\Requests\PaymentReportRequest;
use App\Modules\Reporting\Requests\ProductionReportRequest;
use App\Modules\Reporting\Requests\QcReportRequest;
use App\Modules\Reporting\Requests\RevenueReportRequest;
use App\Modules\Reporting\Services\DeliveryReportService;
use App\Modules\Reporting\Services\ExportReportService;
use App\Modules\Reporting\Services\InvoiceReportService;
use App\Modules\Reporting\Services\OrderReportService;
use App\Modules\Reporting\Services\PaymentReportService;
use App\Modules\Reporting\Services\ProductionReportService;
use App\Modules\Reporting\Services\QcReportService;
use App\Modules\Reporting\Services\RevenueReportService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams CSV exports. Every export requires both the report's view permission
 * and the export permission, and reuses the same filters as the screen report.
 */
class ExportReportController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ExportReportService $exporter,
        private readonly OrderReportService $orders,
        private readonly ProductionReportService $production,
        private readonly QcReportService $qc,
        private readonly DeliveryReportService $delivery,
        private readonly InvoiceReportService $invoices,
        private readonly PaymentReportService $payments,
        private readonly RevenueReportService $revenue,
    ) {}

    public function exportOrders(OrderReportRequest $request): StreamedResponse
    {
        $this->authorizeExport('reporting.orders');

        return $this->exporter->stream($this->orders->export($request->filters()));
    }

    public function exportProduction(ProductionReportRequest $request): StreamedResponse
    {
        $this->authorizeExport('reporting.production');

        return $this->exporter->stream($this->production->export($request->filters()));
    }

    public function exportQualityControl(QcReportRequest $request): StreamedResponse
    {
        $this->authorizeExport('reporting.qc');

        return $this->exporter->stream($this->qc->export($request->filters()));
    }

    public function exportDelivery(DeliveryReportRequest $request): StreamedResponse
    {
        $this->authorizeExport('reporting.delivery');

        return $this->exporter->stream($this->delivery->export($request->filters()));
    }

    public function exportInvoices(InvoiceReportRequest $request): StreamedResponse
    {
        $this->authorizeExport('reporting.invoices');

        return $this->exporter->stream($this->invoices->export($request->filters()));
    }

    public function exportPayments(PaymentReportRequest $request): StreamedResponse
    {
        $this->authorizeExport('reporting.payments');

        return $this->exporter->stream($this->payments->export($request->filters()));
    }

    public function exportOutstanding(InvoiceReportRequest $request): StreamedResponse
    {
        $this->authorizeExport('reporting.invoices');

        return $this->exporter->stream($this->invoices->outstandingExport($request->filters()));
    }

    public function exportRevenue(RevenueReportRequest $request): StreamedResponse
    {
        $this->authorizeExport('reporting.invoices');

        return $this->exporter->stream($this->revenue->export($request->filters()));
    }

    private function authorizeExport(string $reportGate): void
    {
        $this->authorize($reportGate);
        $this->authorize('reporting.export');
    }
}
