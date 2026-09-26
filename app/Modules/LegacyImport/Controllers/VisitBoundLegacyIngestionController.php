<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LegacyImport\Requests\StoreVisitBoundLegacyOdontogramRequest;
use App\Modules\LegacyImport\Requests\StoreVisitBoundLegacyRmeRequest;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramImportService;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Support\Legacy\LegacyVisitAttestation;
use App\Support\Legacy\LegacyVisitBindingRefusal;
use App\Support\Legacy\LegacyVisitBindingService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * REVISION-LEGACY-VISIT-BOUND-PREVERIFIED-INGESTION-1 — Arsip Legacy, opened
 * from a REAL VISIT.
 *
 * THE WHOLE POINT, in one sentence: the operator verifies the historical dates
 * ONCE, here, at the visit, and the downstream checker never re-enters them.
 *
 * WHAT THIS CONTROLLER IS NOT. It is not a second importer. Every rule —
 * source-RM binding, date rules, branch derivation, duplicate detection, file
 * safety, storage, rendering — still belongs to the canonical
 * LegacyRme/LegacyOdontogram intake services, and this class calls exactly the
 * same methods the backlog screen calls. The ONLY thing it adds is a resolved
 * visit attestation.
 *
 * IT CANNOT CREATE A VISIT. Point-of-visit migration means the encounter
 * already happened. The route is model-bound to an existing visit and
 * LegacyVisitBindingService only ever reads; there is no code path here that
 * writes to trx_clinic_visits.
 *
 * THREE INDEPENDENT AUTHORIZATION LAYERS, none of which is the sidebar:
 *   1. route middleware — the clinic-visit permission group plus the narrow
 *      `verify_legacy_dates_at_ingestion`;
 *   2. the FormRequest — `create` on the archive model AND the same narrow
 *      permission (so holding one without the other opens nothing);
 *   3. LegacyVisitBindingService — the visit must be visible to this actor
 *      under ClinicVisitPolicy AND inside their working branch scope, and it
 *      fails closed when they have no valid working context.
 *
 * NO PUBLISH, NO VOID. There is deliberately no finalize action on this
 * controller. LEGACY-RME-SOD-1 stays armed: the account that files a document
 * still cannot certify it, and nothing here grants publish or void.
 */
class VisitBoundLegacyIngestionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly LegacyVisitBindingService $visitBinding,
        private readonly LegacyRmeImportService $rmeImports,
        private readonly LegacyOdontogramImportService $odontogramImports,
    ) {}

    /**
     * The visit-bound legacy RME upload form.
     */
    public function createRme(Request $request, ClinicVisit $clinicVisit): View
    {
        // NOTE: the visit is authorized inside bindOrFail(), NOT here. An
        // explicit authorize('view', $visit) would answer 403 for a visit in
        // another branch, which tells the caller the visit EXISTS — an
        // enumeration oracle the rest of the legacy module deliberately avoids
        // by answering 404. The binding service runs the identical
        // ClinicVisitPolicy check and gives the non-revealing answer.
        $this->authorize('create', LegacyRmeImport::class);

        // Resolve through the SAME authority the write path uses, so the screen
        // can never offer a binding the store() would refuse. The attestation
        // flag is passed as true only to reuse one resolver; nothing is
        // persisted on a GET.
        $attestation = $this->bindOrFail($clinicVisit, $request, previewOnly: true);

        return view('rme.visits.legacy-archive.rme-create', [
            'clinicVisit' => $clinicVisit,
            'patient' => $attestation->patient,
            'visitDate' => $attestation->visitDate,
        ]);
    }

    public function storeRme(StoreVisitBoundLegacyRmeRequest $request, ClinicVisit $clinicVisit): RedirectResponse
    {
        // THE BOUNDARY. Re-resolved from the database on the write path — the
        // GET above is presentation and is never trusted. The visit policy and
        // the working branch scope are both enforced inside bindOrFail().
        $attestation = $this->bindOrFail(
            $clinicVisit,
            $request,
            previewOnly: false,
            attested: $request->boolean('date_attestation'),
        );

        $latest = $request->string('latest_rme_date')->toString();

        $import = $this->rmeImports->createFromUpload(
            $attestation->patient,
            $request->string('selected_rme_date')->toString(),
            $request->string('source_rm_raw')->toString(),
            // The branch stays RM-DERIVED (FIX-ROLL2-1). Passing null means
            // "nothing submitted to contradict", never "use the visit branch".
            null,
            $request->file('document'),
            $attestation->verifiedBy,
            $latest !== '' ? $latest : null,
            $attestation,
        );

        return redirect()
            ->route('settings.rme.legacy-imports.show', $import)
            ->with('status', 'Arsip RME lama diunggah dengan verifikasi tanggal. '
                .'Dokumen menunggu pemeriksaan oleh petugas lain sebelum diterbitkan.');
    }

    public function createOdontogram(Request $request, ClinicVisit $clinicVisit): View
    {
        // See createRme(): the visit is authorized inside bindOrFail() so an
        // out-of-scope visit answers 404 rather than 403.
        $this->authorize('create', LegacyOdontogramImport::class);

        $attestation = $this->bindOrFail($clinicVisit, $request, previewOnly: true);

        return view('rme.visits.legacy-archive.odontogram-create', [
            'clinicVisit' => $clinicVisit,
            'patient' => $attestation->patient,
            'visitDate' => $attestation->visitDate,
        ]);
    }

    public function storeOdontogram(
        StoreVisitBoundLegacyOdontogramRequest $request,
        ClinicVisit $clinicVisit,
    ): RedirectResponse {
        // Visit policy + working branch scope are enforced inside bindOrFail().
        $attestation = $this->bindOrFail(
            $clinicVisit,
            $request,
            previewOnly: false,
            attested: $request->boolean('date_attestation'),
        );

        $import = $this->odontogramImports->createFromUpload(
            $attestation->patient,
            $request->string('selected_odontogram_date')->toString(),
            $request->file('document'),
            $attestation->verifiedBy,
            $attestation,
        );

        return redirect()
            ->route('settings.rme.legacy-odontograms.show', $import)
            ->with('status', 'Arsip odontogram lama diunggah dengan verifikasi tanggal. '
                .'Dokumen menunggu pemeriksaan oleh petugas lain sebelum diterbitkan.');
    }

    /**
     * Resolve the binding, turning a refusal into the field error the operator
     * is looking at.
     *
     * `$previewOnly` exists so the GET can render the form without demanding
     * an attestation that has not been made yet. It relaxes NOTHING else: the
     * visit must still exist, be live, be this patient's and be inside the
     * actor's scope, so an operator can never reach a form for a visit whose
     * upload would be refused.
     */
    private function bindOrFail(
        ClinicVisit $clinicVisit,
        Request $request,
        bool $previewOnly,
        bool $attested = false,
    ): LegacyVisitAttestation {
        try {
            return $this->visitBinding->resolve(
                (int) $clinicVisit->getKey(),
                $request->user(),
                dateAttested: $previewOnly ? true : $attested,
            );
        } catch (LegacyVisitBindingRefusal $refusal) {
            // On a POST the refusal belongs on the form the operator is
            // looking at, exactly like every other legacy input rule.
            if (! $previewOnly) {
                throw $refusal->toValidationException();
            }

            // On a GET there is no form to return to yet, so a validation
            // exception would redirect "back" to nowhere. An inaccessible
            // visit answers 404 — the same answer the rest of the legacy
            // module gives for an out-of-scope row, and it leaks nothing
            // about whether the visit exists. Anything else sends the
            // operator to the visit with a plain reason.
            if ($refusal->refusalCode === LegacyVisitBindingRefusal::VISIT_NOT_ACCESSIBLE) {
                abort(404);
            }

            throw new HttpResponseException(
                redirect()
                    ->route('rme.visits.show', $clinicVisit)
                    ->with('error', $refusal->getMessage())
            );
        }
    }
}
