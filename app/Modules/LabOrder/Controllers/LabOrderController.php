<?php

namespace App\Modules\LabOrder\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Clinic\Services\ClinicService;
use App\Modules\Doctor\Services\DoctorService;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Requests\CancelLabOrderRequest;
use App\Modules\LabOrder\Requests\StoreLabOrderRequest;
use App\Modules\LabOrder\Requests\UpdateLabOrderRequest;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\LabOrder\Services\LabOrderService;
use App\Modules\LabService\Services\LabServiceService;
use App\Modules\Patient\Services\PatientService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LabOrderController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly LabOrderService $labOrderService,
        private readonly AuditLogService $auditLogService,
        private readonly ClinicService $clinicService,
        private readonly DoctorService $doctorService,
        private readonly PatientService $patientService,
        private readonly LabServiceService $labServiceService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', LabOrder::class);

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'priority' => $request->string('priority')->toString() ?: null,
            'clinic_id' => $request->integer('clinic_id') ?: null,
            'doctor_id' => $request->integer('doctor_id') ?: null,
            'patient_id' => $request->integer('patient_id') ?: null,
        ];

        return view('lab-orders.index', [
            'orders' => $this->labOrderService->list($filters, 10),
            'filters' => $filters,
            'clinics' => $this->clinicService->listAll(),
            'doctors' => $this->doctorService->listAll(),
            'patients' => $this->patientService->listAll(),
            'statuses' => LabOrder::STATUSES,
            'priorities' => LabOrder::PRIORITIES,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', LabOrder::class);

        return view('lab-orders.create', $this->formOptions());
    }

    public function store(StoreLabOrderRequest $request): RedirectResponse
    {
        $this->authorize('create', LabOrder::class);

        $order = $this->labOrderService->create($request->validated());

        return redirect()
            ->route('lab-orders.show', $order)
            ->with('status', "Order lab {$order->order_number} berhasil ditambahkan.");
    }

    public function show(LabOrder $labOrder): View
    {
        $this->authorize('view', $labOrder);

        $order = $this->labOrderService->findDetail($labOrder->id);
        $rmeSourceCandidate = $order->rmeLabCaseCandidate()
            ->with(['rmeInvoice', 'clinicVisit', 'patient', 'doctor', 'treatment', 'reviewedBy'])
            ->first();

        return view('lab-orders.show', [
            'order' => $order,
            'rmeSourceCandidate' => $rmeSourceCandidate,
            'auditLogs' => $this->auditLogService->paginateForEntity(LabOrder::ENTITY_TYPE, $labOrder->id, 15),
        ]);
    }

    public function edit(LabOrder $labOrder): View
    {
        $this->authorize('update', $labOrder);

        return view('lab-orders.edit', array_merge($this->formOptions(), [
            'order' => $this->labOrderService->findDetail($labOrder->id),
        ]));
    }

    public function update(UpdateLabOrderRequest $request, LabOrder $labOrder): RedirectResponse
    {
        $this->authorize('update', $labOrder);

        $this->labOrderService->update($labOrder, $request->validated());

        return redirect()
            ->route('lab-orders.show', $labOrder)
            ->with('status', 'Order lab berhasil diperbarui.');
    }

    public function cancel(CancelLabOrderRequest $request, LabOrder $labOrder): RedirectResponse
    {
        $this->authorize('cancel', $labOrder);

        $this->labOrderService->cancel($labOrder, $request->validated()['reason']);

        return redirect()
            ->route('lab-orders.show', $labOrder)
            ->with('status', 'Order lab berhasil dibatalkan.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'clinics' => $this->clinicService->listAll(),
            'doctors' => $this->doctorService->listAll(),
            'patients' => $this->patientService->listAll(),
            'labServices' => $this->labServiceService->listAll(),
            'priorities' => LabOrder::PRIORITIES,
        ];
    }
}
