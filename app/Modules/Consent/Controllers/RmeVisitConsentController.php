<?php

namespace App\Modules\Consent\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Requests\StoreRmeVisitConsentRequest;
use App\Modules\Consent\Services\RmeVisitConsentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-01 — thin controller.
 *
 * Every decision lives elsewhere: authorisation in RmeVisitConsentPolicy,
 * validation in StoreRmeVisitConsentRequest, and the signing rules (timing
 * gate, explicit clause-8 answer, signature evidence, snapshotting, numbering)
 * in RmeVisitConsentService.
 */
class RmeVisitConsentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly RmeVisitConsentService $consents,
    ) {}

    public function create(ClinicVisit $clinicVisit): View|RedirectResponse
    {
        $this->authorize('create', [RmeVisitConsent::class, $clinicVisit]);

        // Signing is only meaningful DURING the examination (AIR-RMC-06: the
        // visit is `in_progress`). Refuse early with the real rule rather than
        // presenting a form the server would reject on submit.
        // DMS-RME-REVISION-PROGRAM-5 (CW5-08) — the previous message told the
        // operator to wait until the doctor FINISHED, which is exactly the moment
        // consent stops being signable.
        if (! $this->consents->isSignable($clinicVisit)) {
            return redirect()
                ->route('rme.visits.show', $clinicVisit)
                ->with('error', 'Persetujuan tindakan medis hanya dapat ditandatangani selama pemeriksaan berlangsung: dokter menekan "Mulai Pemeriksaan" terlebih dahulu, dan setelah "Selesai Pemeriksaan" persetujuan tidak dapat lagi ditandatangani untuk kunjungan ini.');
        }

        $existing = $this->consents->validConsentFor($clinicVisit);

        if ($existing !== null) {
            return redirect()
                ->route('rme.consents.show', $existing)
                ->with('status', 'Persetujuan tindakan medis untuk kunjungan ini sudah ditandatangani.');
        }

        $clinicVisit->loadMissing(['patient', 'doctor', 'branch', 'initialTreatment']);

        return view('rme.consents.create', [
            'clinicVisit' => $clinicVisit,
            'templates' => $this->consents->availableTemplates(),
            'defaultTemplate' => config('rme_consent.default_template'),
            'relationships' => (array) config('rme_consent.relationships', []),
            'suggestedAction' => $this->suggestedAction($clinicVisit),
        ]);
    }

    public function store(StoreRmeVisitConsentRequest $request, ClinicVisit $clinicVisit): RedirectResponse
    {
        $this->authorize('create', [RmeVisitConsent::class, $clinicVisit]);

        $consent = $this->consents->sign($clinicVisit, $request->user(), $request->validated());

        return redirect()
            ->route('rme.consents.show', $consent)
            // CW5-08 — consent unlocks the examination record, not the payment
            // (AIR-RMC-11: the cashier never consults consent).
            ->with('status', 'Persetujuan tindakan medis berhasil ditandatangani. Rekam medis pemeriksaan ini kini dapat ditulis.');
    }

    public function show(RmeVisitConsent $consent): View
    {
        $this->authorize('view', $consent);

        return view('rme.consents.show', $this->documentData($consent));
    }

    public function print(RmeVisitConsent $consent): View
    {
        $this->authorize('view', $consent);

        return view('rme.consents.print', $this->documentData($consent));
    }

    /**
     * Signature images live on a private disk and have no public URL. They are
     * streamed only to a viewer the consent policy already allows.
     */
    public function signature(RmeVisitConsent $consent, string $kind): StreamedResponse
    {
        $this->authorize('view', $consent);

        abort_unless(in_array($kind, ['consenter', 'doctor'], true), 404);

        $path = $kind === 'consenter'
            ? $consent->consenter_signature_path
            : $consent->doctor_signature_path;

        abort_if($path === null || $path === '', 404);

        $disk = Storage::disk($this->consents->diskName());

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, basename($path), [
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            // DMS-RME-REVISION-PROGRAM-8C (OD-6-2, rule 228 §11) — a patient's
            // handwritten signature is never kept by a browser or shared cache,
            // as the prescription canvases already are not.
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function documentData(RmeVisitConsent $consent): array
    {
        $consent->loadMissing(['clinicVisit.branch', 'patient', 'doctor', 'signedBy', 'voidedBy']);

        return [
            'consent' => $consent,
            'branch' => $consent->clinicVisit?->branch,
            'relationships' => (array) config('rme_consent.relationships', []),
            'genderLabels' => (array) config('rme_consent.gender_labels', []),
            'consenterSignature' => $this->consents->signatureDataUri($consent->consenter_signature_path),
            'doctorSignature' => $this->consents->signatureDataUri($consent->doctor_signature_path),
        ];
    }

    /**
     * A starting point for "tindakan medis berupa ...", taken from the visit's
     * own initial treatment when there is one. It is only a prefill: the
     * operator remains responsible for what the document says, and nothing is
     * invented when the visit has no treatment recorded.
     */
    private function suggestedAction(ClinicVisit $clinicVisit): string
    {
        return (string) ($clinicVisit->initialTreatment?->name ?? '');
    }
}
