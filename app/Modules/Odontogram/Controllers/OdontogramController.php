<?php

namespace App\Modules\Odontogram\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Odontogram\Requests\UpdateOdontogramPlaceholderRequest;
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

    public function show(ClinicVisit $clinicVisit): View
    {
        $this->authorize('create', [Odontogram::class, $clinicVisit]);

        $odontogram = $this->service->getOrCreateForVisit($clinicVisit, auth()->user());

        $clinicVisit->loadMissing(['followUpOf.odontogram', 'patient', 'doctor']);

        $parentOdontogram = $clinicVisit->followUpOf?->odontogram;

        return view('rme.visits.odontogram.show', compact('clinicVisit', 'odontogram', 'parentOdontogram'));
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

    public function print(Odontogram $odontogram): View
    {
        $this->authorize('print', $odontogram);

        $odontogram->load(['clinicVisit.patient', 'clinicVisit.doctor', 'finalizer']);
        $clinicVisit = $odontogram->clinicVisit;

        return view('rme.visits.odontogram.print', compact('odontogram', 'clinicVisit'));
    }
}
