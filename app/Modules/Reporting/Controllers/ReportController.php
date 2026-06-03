<?php

namespace App\Modules\Reporting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Clinic\Services\ClinicService;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Doctor\Services\DoctorService;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Models\Payment;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabService\Services\LabServiceService;
use App\Modules\Production\Models\LabOrderAssignment;
use App\Modules\QualityControl\Models\QualityControl;
use App\Modules\Reporting\Requests\DeliveryReportRequest;
use App\Modules\Reporting\Requests\InvoiceReportRequest;
use App\Modules\Reporting\Requests\OrderReportRequest;
use App\Modules\Reporting\Requests\PaymentReportRequest;
use App\Modules\Reporting\Requests\ProductionReportRequest;
use App\Modules\Reporting\Requests\QcReportRequest;
use App\Modules\Reporting\Requests\RevenueReportRequest;
use App\Modules\Reporting\Services\DeliveryReportService;
use App\Modules\Reporting\Services\InvoiceReportService;
use App\Modules\Reporting\Services\OrderReportService;
use App\Modules\Reporting\Services\PaymentReportService;
use App\Modules\Reporting\Services\ProductionReportService;
use App\Modules\Reporting\Services\QcReportService;
use App\Modules\Reporting\Services\RevenueReportService;
use App\Modules\Technician\Services\TechnicianService;
use App\Modules\User\Services\UserService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;

class ReportController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly OrderReportService $orders,
        private readonly ProductionReportService $production,
        private readonly QcReportService $qc,
        private readonly DeliveryReportService $delivery,
        private readonly InvoiceReportService $invoices,
        private readonly PaymentReportService $payments,
        private readonly RevenueReportService $revenue,
        private readonly ClinicService $clinicService,
        private readonly DoctorService $doctorService,
        private readonly TechnicianService $technicianService,
        private readonly LabServiceService $labServiceService,
        private readonly UserService $userService,
    ) {}

    public function orders(OrderReportRequest $request): View
    {
        $this->authorize('reporting.orders');
        $f = $request->filters();

        return view('reports.orders', array_merge($this->baseOptions(), [
            'rows' => $this->orders->paginate($f),
            'summary' => $this->orders->summary($f),
            'filters' => $f,
            'doctors' => $this->doctorService->listAll(),
            'services' => $this->labServiceService->listAll(),
            'statuses' => LabOrder::STATUSES,
        ]));
    }

    public function production(ProductionReportRequest $request): View
    {
        $this->authorize('reporting.production');
        $f = $request->filters();

        return view('reports.production', array_merge($this->baseOptions(), [
            'rows' => $this->production->paginate($f),
            'summary' => $this->production->summary($f),
            'filters' => $f,
            'technicians' => $this->technicianService->listAll(),
            'statuses' => LabOrderAssignment::STATUSES,
        ]));
    }

    public function qualityControl(QcReportRequest $request): View
    {
        $this->authorize('reporting.qc');
        $f = $request->filters();

        return view('reports.qc', array_merge($this->baseOptions(), [
            'rows' => $this->qc->paginate($f),
            'summary' => $this->qc->summary($f),
            'filters' => $f,
            'technicians' => $this->technicianService->listAll(),
            'qcStatuses' => QualityControl::RESULTS,
        ]));
    }

    public function delivery(DeliveryReportRequest $request): View
    {
        $this->authorize('reporting.delivery');
        $f = $request->filters();

        return view('reports.delivery', array_merge($this->baseOptions(), [
            'rows' => $this->delivery->paginate($f),
            'summary' => $this->delivery->summary($f),
            'filters' => $f,
            'couriers' => $this->userService->listAll(),
            'deliveryStatuses' => Delivery::STATUSES,
        ]));
    }

    public function invoices(InvoiceReportRequest $request): View
    {
        $this->authorize('reporting.invoices');
        $f = $request->filters();

        return view('reports.invoices', array_merge($this->baseOptions(), [
            'rows' => $this->invoices->paginate($f),
            'summary' => $this->invoices->summary($f),
            'filters' => $f,
            'invoiceStatuses' => Invoice::STATUSES,
        ]));
    }

    public function payments(PaymentReportRequest $request): View
    {
        $this->authorize('reporting.payments');
        $f = $request->filters();

        return view('reports.payments', array_merge($this->baseOptions(), [
            'rows' => $this->payments->paginate($f),
            'summary' => $this->payments->summary($f),
            'filters' => $f,
            'methods' => Payment::METHODS,
            'users' => $this->userService->listAll(),
        ]));
    }

    public function outstanding(InvoiceReportRequest $request): View
    {
        $this->authorize('reporting.invoices');
        $f = $request->filters();

        return view('reports.outstanding', array_merge($this->baseOptions(), [
            'rows' => $this->invoices->outstandingPaginate($f),
            'summary' => $this->invoices->outstandingSummary($f),
            'filters' => $f,
        ]));
    }

    public function revenue(RevenueReportRequest $request): View
    {
        $this->authorize('reporting.invoices');
        $f = $request->filters();

        return view('reports.revenue', array_merge($this->baseOptions(), [
            'summary' => $this->revenue->summary($f),
            'filters' => $f,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function baseOptions(): array
    {
        return ['clinics' => $this->clinicService->listAll()];
    }
}
