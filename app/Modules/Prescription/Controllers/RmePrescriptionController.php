<?php

namespace App\Modules\Prescription\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Prescription\Exceptions\WhatsAppDeliveryException;
use App\Modules\Prescription\Models\RmePrescription;
use App\Modules\Prescription\Requests\SendPrescriptionWhatsAppRequest;
use App\Modules\Prescription\Requests\StoreRmePrescriptionRequest;
use App\Modules\Prescription\Requests\UpdateRmePrescriptionRequest;
use App\Modules\Prescription\Services\PrescriptionWhatsAppDeliveryService;
use App\Modules\Prescription\Services\RmePrescriptionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RmePrescriptionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly RmePrescriptionService $service,
    ) {}

    public function show(ClinicVisit $clinicVisit): View
    {
        $this->authorize('viewForVisit', [RmePrescription::class, $clinicVisit]);

        $data = $this->service->showDataForVisit($clinicVisit);
        $adjacentVisits = app(ClinicVisitService::class)->adjacentVisits($clinicVisit);
        $canManage = auth()->user()?->can('create', [RmePrescription::class, $clinicVisit]) ?? false;
        $prescription = $data['prescription'];
        // FIX-02 — the WhatsApp hand-off is offered only for a saved prescription
        // the operator is actually authorised to transmit.
        $canSendWhatsApp = $prescription !== null
            && (auth()->user()?->can('sendWhatsApp', $prescription) ?? false);

        return view('rme.visits.prescription.show', [
            'canSendWhatsApp' => $canSendWhatsApp,
            'whatsAppEnabled' => app(PrescriptionWhatsAppDeliveryService::class)->isEnabled(),
            'lastWhatsAppDelivery' => $prescription?->whatsAppDeliveries()->latest('id')->first(),
            'clinicVisit' => $clinicVisit,
            'prescription' => $data['prescription'],
            'defaults' => $data['defaults'],
            'history' => $data['history'],
            'adjacentVisits' => $adjacentVisits,
            'canManage' => $canManage,
            'editMode' => $canManage && (request()->boolean('edit') || $data['prescription'] === null),
        ]);
    }

    /**
     * FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-02) — send this prescription to
     * the patient through the official WhatsApp Business Platform.
     *
     * Server-to-server: no wa.me link, no WhatsApp Web, no browser redirect.
     * A failure never mutates the prescription — the operator gets an
     * actionable message and the clinical record is untouched.
     */
    public function sendWhatsApp(
        SendPrescriptionWhatsAppRequest $request,
        RmePrescription $rmePrescription,
        PrescriptionWhatsAppDeliveryService $delivery,
    ): RedirectResponse {
        $this->authorize('sendWhatsApp', $rmePrescription);

        try {
            $sent = $delivery->send(
                $rmePrescription,
                $request->user(),
                (bool) $request->boolean('resend'),
            );
        } catch (WhatsAppDeliveryException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            'status',
            'Resep berhasil dikirim ke WhatsApp pasien ('.$sent->maskedRecipient().').'
        );
    }

    public function store(StoreRmePrescriptionRequest $request, ClinicVisit $clinicVisit): RedirectResponse
    {
        $this->authorize('create', [RmePrescription::class, $clinicVisit]);

        $prescription = $this->service->create(
            $clinicVisit,
            $request->validated(),
            $request->user(),
        );

        return redirect()
            ->route('rme.visits.prescription.show', $clinicVisit)
            ->with('status', 'Resep dokter berhasil disimpan.')
            ->with('focus_prescription_id', $prescription->id);
    }

    public function update(UpdateRmePrescriptionRequest $request, RmePrescription $rmePrescription): RedirectResponse
    {
        $this->authorize('update', $rmePrescription);

        $this->service->update(
            $rmePrescription,
            $request->validated(),
            $request->user(),
        );

        return redirect()
            ->route('rme.visits.prescription.show', $rmePrescription->clinic_visit_id)
            ->with('status', 'Resep dokter berhasil diperbarui.');
    }

    public function print(RmePrescription $rmePrescription): View
    {
        $this->authorize('print', $rmePrescription);

        $rmePrescription->load(['clinicVisit.patient', 'clinicVisit.doctor', 'clinicVisit.branch', 'doctor']);
        $clinicVisit = $rmePrescription->clinicVisit;

        $this->service->markPrinted($rmePrescription);

        return view('rme.visits.prescription.print', [
            'prescription' => $rmePrescription,
            'clinicVisit' => $clinicVisit,
        ]);
    }
}
