<?php

namespace App\Modules\Odontogram\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Odontogram\Requests\UpdateOdontogramPlaceholderRequest;
use App\Modules\Odontogram\Services\OdontogramPrintFormatter;
use App\Modules\Odontogram\Services\OdontogramService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class OdontogramController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly OdontogramService $service,
    ) {}

    public function show(ClinicVisit $clinicVisit, OdontogramPrintFormatter $formatter): View
    {
        $this->authorize('create', [Odontogram::class, $clinicVisit]);

        $odontogram = $this->service->getOrCreateForVisit($clinicVisit, auth()->user());

        $clinicVisit->loadMissing(['followUpOf.odontogram', 'patient', 'doctor']);

        $parentOdontogram = $clinicVisit->followUpOf?->odontogram;

        // Prev/next arrow navigation across the patient's visits. The odontogram
        // show route auto-creates a placeholder per visit, so no medical-record
        // requirement is needed here (Sprint 59).
        $adjacentVisits = app(ClinicVisitService::class)->adjacentVisits($clinicVisit);

        // Sprint 63.1.1 — read-only saved-result table rendered below the visual
        // preview. Reuses the Sprint 63.1 print formatter row-building logic so the
        // screen read-back and the print/PDF table stay in lockstep (no duplication,
        // no mutation, presentation-only).
        $structured = $formatter->format($odontogram);

        return view('rme.visits.odontogram.show', compact('clinicVisit', 'odontogram', 'parentOdontogram', 'adjacentVisits', 'structured'));
    }

    public function update(UpdateOdontogramPlaceholderRequest $request, Odontogram $odontogram): RedirectResponse
    {
        $this->authorize('update', $odontogram);

        $this->service->updatePlaceholder($odontogram, $request->validated(), auth()->user());

        return redirect()
            ->route('rme.visits.odontogram.show', $odontogram->clinicVisit)
            ->with('status', 'Odontogram berhasil disimpan.');
    }

    public function finalize(Odontogram $odontogram): RedirectResponse
    {
        $this->authorize('finalize', $odontogram);

        $this->service->finalize($odontogram, auth()->user());

        return redirect()
            ->route('rme.visits.odontogram.show', $odontogram->clinicVisit)
            ->with('status', 'Odontogram berhasil difinalisasi.');
    }

    public function print(Odontogram $odontogram, OdontogramPrintFormatter $formatter): View
    {
        $this->authorize('print', $odontogram);

        $odontogram->load(['clinicVisit.patient', 'clinicVisit.doctor', 'clinicVisit.branch', 'finalizer']);
        $clinicVisit = $odontogram->clinicVisit;

        $structured = $formatter->format($odontogram);

        return view('rme.visits.odontogram.print', compact('odontogram', 'clinicVisit', 'structured'));
    }
}
