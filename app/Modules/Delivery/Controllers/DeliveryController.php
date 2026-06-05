<?php

namespace App\Modules\Delivery\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Clinic\Services\ClinicService;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Delivery\Requests\AssignCourierRequest;
use App\Modules\Delivery\Requests\CompleteDeliveryRequest;
use App\Modules\Delivery\Requests\CreateDeliveryRequest;
use App\Modules\Delivery\Requests\MarkDeliveredRequest;
use App\Modules\Delivery\Requests\ReassignCourierRequest;
use App\Modules\Delivery\Requests\StartDeliveryRequest;
use App\Modules\Delivery\Requests\UploadPodRequest;
use App\Modules\Delivery\Services\DeliveryService;
use App\Modules\Delivery\Services\DeliveryWorkflowService;
use App\Modules\Delivery\Services\PodService;
use App\Modules\LabOrder\Models\LabOrder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DeliveryController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly DeliveryService $deliveries,
        private readonly DeliveryWorkflowService $workflow,
        private readonly PodService $podService,
        private readonly ClinicService $clinics,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Delivery::class);

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'clinic_id' => $request->integer('clinic_id') ?: null,
            'doctor_id' => $request->integer('doctor_id') ?: null,
            'patient_id' => $request->integer('patient_id') ?: null,
            'courier_id' => $request->integer('courier_id') ?: null,
            'due_date' => $request->string('due_date')->toString() ?: null,
        ];

        if ($request->user()->hasRole('Courier') && ! $request->user()->can('manage_delivery')) {
            $filters['courier_id'] = $request->user()->id;
        }

        return view('deliveries.index', [
            'deliveries' => $this->deliveries->paginate($filters, 10),
            'readyOrders' => $this->deliveries->readyOrders($filters),
            'filters' => $filters,
            'statuses' => Delivery::STATUSES,
            'clinics' => $this->clinics->listAll(),
            'couriers' => $this->couriers(),
        ]);
    }

    public function show(Delivery $delivery): View
    {
        $this->authorize('view', $delivery);

        return view('deliveries.show', [
            'delivery' => $this->deliveries->find($delivery->id),
            'couriers' => $this->couriers(),
            'statuses' => Delivery::STATUSES,
        ]);
    }

    public function store(CreateDeliveryRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $order = LabOrder::findOrFail($data['lab_order_id']);
        Gate::authorize('create', [Delivery::class, $order]);

        $delivery = $this->deliveries->create(
            $order,
            $data['courier_id'] ?? null,
            $data['delivery_notes'] ?? null,
            $request->user(),
        );

        return redirect()->route('deliveries.show', $delivery)->with('status', 'Pengiriman berhasil dibuat.');
    }

    public function assignCourier(AssignCourierRequest $request, Delivery $delivery): RedirectResponse
    {
        $this->authorize('assignCourier', $delivery);
        $data = $request->validated();
        $this->deliveries->assignCourier($delivery, (int) $data['courier_id'], $data['notes'] ?? null, $request->user());

        return $this->back($delivery, 'Kurir berhasil ditugaskan.');
    }

    public function reassignCourier(ReassignCourierRequest $request, Delivery $delivery): RedirectResponse
    {
        $this->authorize('assignCourier', $delivery);
        $data = $request->validated();
        $this->deliveries->reassignCourier($delivery, (int) $data['courier_id'], $data['notes'], $request->user());

        return $this->back($delivery, 'Kurir berhasil diganti.');
    }

    public function startDelivery(StartDeliveryRequest $request, Delivery $delivery): RedirectResponse
    {
        $this->authorize('startDelivery', $delivery);
        $this->workflow->start($delivery, $request->validated()['notes'] ?? null, $request->user());

        return $this->back($delivery, 'Pengiriman berhasil dimulai.');
    }

    public function markDelivered(MarkDeliveredRequest $request, Delivery $delivery): RedirectResponse
    {
        $this->authorize('markDelivered', $delivery);
        $data = $request->validated();
        $data['signature'] = $request->file('signature');
        $data['receiver_photo'] = $request->file('receiver_photo');
        $this->workflow->markDelivered($delivery, $data, $request->user());

        return $this->back($delivery, 'Pengiriman berhasil ditandai terkirim.');
    }

    public function completeDelivery(CompleteDeliveryRequest $request, Delivery $delivery): RedirectResponse
    {
        $this->authorize('completeDelivery', $delivery);
        $this->workflow->complete($delivery, $request->validated()['notes'] ?? null, $request->user());

        return $this->back($delivery, 'Pengiriman berhasil diselesaikan.');
    }

    public function uploadPod(UploadPodRequest $request, Delivery $delivery): RedirectResponse
    {
        $this->authorize('uploadPod', $delivery);
        $data = $request->validated();

        $this->podService->uploadPod(
            $delivery,
            $data['receiver_name'],
            $request->file('signature'),
            $request->file('receiver_photo'),
            $data['received_at'],
            $data['delivery_notes'] ?? null,
            $request->user(),
        );

        return $this->back($delivery, 'POD berhasil diunggah.');
    }

    private function back(Delivery $delivery, string $message): RedirectResponse
    {
        return redirect()->route('deliveries.show', $delivery)->with('status', $message);
    }

    private function couriers()
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
