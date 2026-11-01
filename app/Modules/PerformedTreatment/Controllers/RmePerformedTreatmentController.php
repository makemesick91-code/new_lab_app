<?php

declare(strict_types=1);

namespace App\Modules\PerformedTreatment\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\PerformedTreatment\Models\RmePerformedTreatment;
use App\Modules\PerformedTreatment\Models\RmeTreatmentDeclaration;
use App\Modules\PerformedTreatment\Requests\DeclareRmeTreatmentRequest;
use App\Modules\PerformedTreatment\Requests\StoreRmePerformedTreatmentRequest;
use App\Modules\PerformedTreatment\Requests\VoidRmePerformedTreatmentRequest;
use App\Modules\PerformedTreatment\Services\RmePerformedTreatmentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

/**
 * FEATURE-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219) — thin HTTP door to the
 * doctor's "Tindakan Dilakukan" record. Route middleware (examination authority +
 * room gate) → FormRequest (flag 404 + shape) → policy ability → service (treating
 * doctor, consented live encounter, visit row lock). The visit comes from the
 * route; nothing about it is ever read from the body.
 */
class RmePerformedTreatmentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly RmePerformedTreatmentService $service,
    ) {}

    public function declare(DeclareRmeTreatmentRequest $request, ClinicVisit $clinicVisit): RedirectResponse
    {
        $this->authorize(RmePerformedTreatmentService::ABILITY, $clinicVisit);

        $declaration = $this->service->declare($clinicVisit, $request->declarationType(), $request->user());

        return back()->with('status', $declaration->declaration_type === RmeTreatmentDeclaration::TYPE_NO_TREATMENT
            ? 'Dicatat: tidak ada tindakan medis pada kunjungan ini (kategori operasional KONTROL).'
            : 'Dicatat: ada tindakan pada kunjungan ini. Tambahkan tindakan yang dilakukan.');
    }

    public function store(StoreRmePerformedTreatmentRequest $request, ClinicVisit $clinicVisit): RedirectResponse
    {
        $this->authorize(RmePerformedTreatmentService::ABILITY, $clinicVisit);

        $this->service->record($clinicVisit, $request->payload(), $request->user());

        return back()->with('status', 'Tindakan yang dilakukan tercatat.');
    }

    public function void(VoidRmePerformedTreatmentRequest $request, ClinicVisit $clinicVisit, RmePerformedTreatment $performedTreatment): RedirectResponse
    {
        // Authorize first: checking membership first would answer 404 vs 403 and
        // tell an unauthorized caller which visit a row belongs to.
        $this->authorize(RmePerformedTreatmentService::ABILITY, $clinicVisit);
        abort_if((int) $performedTreatment->clinic_visit_id !== (int) $clinicVisit->id, 404);

        $this->service->void($clinicVisit, $performedTreatment, (string) $request->validated()['void_reason'], $request->user());

        return back()->with('status', 'Tindakan dibatalkan. Riwayatnya tetap tersimpan.');
    }
}
