<?php

namespace App\Modules\Consent\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Requests\CountersignRmeVisitConsentRequest;
use App\Modules\Consent\Requests\StoreRmeVisitConsentRequest;
use App\Modules\Consent\Requests\VoidRmeVisitConsentRequest;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\Patient\Services\PatientIdentityPresenter;
use App\Modules\RME\Services\DoctorRoomScopeService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
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
        private readonly DoctorRoomScopeService $printDeny,
        // Rule 243 §3 (memo M3.1, PR C1) — what the consent PAGE shows of the
        // identity block: masked NIK and no address for a Doctor.
        private readonly PatientIdentityPresenter $identities,
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

        // Rule 241 §6 — every form offered, each in the version a new consent
        // is signed on, and the ONE form this page renders: the one the old
        // input chose when it is still offered, else the default. Its version is
        // what the page posts back.
        $templates = $this->consents->availableTemplates();
        $defaultTemplate = (string) config('rme_consent.default_template');
        $oldCode = request()->old('template_code');
        $templateCode = is_string($oldCode) && isset($templates[$oldCode])
            ? $oldCode
            : (isset($templates[$defaultTemplate]) ? $defaultTemplate : (string) array_key_first($templates));
        $template = $templates[$templateCode];
        $oldVersion = request()->old('template_version');

        return view('rme.consents.create', [
            'clinicVisit' => $clinicVisit,
            'templates' => $templates,
            'defaultTemplate' => $defaultTemplate,
            'templateCode' => $templateCode,
            'template' => $template,
            // Review MEDIUM-1 (rule 100 §5: no default) — a YA/TIDAK given on the
            // page of ANOTHER version (refused because the version in force
            // changed, either way) is never refilled: a v2026.2 clause-8 YA must
            // not come back as the 2026.1 combined documentation-AND-publication
            // YA. Refilled only when the old input answered this very version.
            'refillConsentAnswers' => is_string($oldVersion) && $oldVersion === (string) $template['version'],
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
            // Rule 241 §2 — the patient's signature alone does not open writing.
            ->with('status', 'Persetujuan tindakan medis berhasil ditandatangani pasien. Rekam medis dan odontogram dapat ditulis setelah dokter yang menangani menandatanganinya dari Ruang Perawatan.');
    }

    public function show(RmeVisitConsent $consent): View
    {
        $this->authorize('view', $consent);

        return view('rme.consents.show', $this->documentData($consent, $this->identities->forConsentViewer($consent, request()->user())) + [
            // DMS-RME-COMPREHENSIVE-CLOSURE-1 (OD-5-8) — decided here, never in
            // Blade; the policy, the route permission and the service decide again.
            // The state checks are explicit: Gate::before answers `void` for
            // Super Admin without ever reaching the policy (review F7).
            'canVoid' => (request()->user()?->can('void', $consent) ?? false)
                && (int) $consent->signed_by !== (int) request()->user()?->getKey()
                && ! $consent->isVoided()
                && $consent->clinicVisit?->status === ClinicVisit::STATUS_IN_PROGRESS,
            // Rule 241 §1 — decided by the service (identity binds Super Admin,
            // which Gate::before lets through every policy).
            'canCountersign' => $this->consents->canCountersign($consent, request()->user()),
            // Rule 241 §3 — the one obstacle a doctor can act on: they captured
            // the patient's signature themselves (a supervised void is needed).
            'countersignRefusal' => $this->consents->countersignRefusal($consent, request()->user()),
        ]);
    }

    /**
     * DMS memo M1.1–M1.3, PR1 (rule 241 §1) — the treating doctor's
     * countersignature form, reached from Ruang Perawatan ("Tanda Tangani PTM")
     * or the consent page. Renders the signed document, so it answers no-store.
     */
    public function countersignForm(RmeVisitConsent $consent): View|RedirectResponse
    {
        $this->authorize('countersign', $consent);

        if ($this->consents->doctorCountersignatureFor($consent) !== null) {
            return redirect()
                ->route('rme.consents.show', $consent)
                ->with('status', RmeVisitConsentService::MESSAGE_COUNTERSIGN_DUPLICATE);
        }

        try {
            // An AuthorizationException (identity, encounter scope) answers 403.
            $this->consents->assertCountersignable($consent, request()->user());
        } catch (ValidationException $refused) {
            return redirect()
                ->route('rme.consents.show', $consent)
                ->with('error', collect($refused->errors())->flatten()->first());
        }

        return view('rme.consents.countersign', $this->documentData($consent, $this->identities->forConsentViewer($consent, request()->user())));
    }

    public function countersign(CountersignRmeVisitConsentRequest $request, RmeVisitConsent $consent): RedirectResponse
    {
        $this->authorize('countersign', $consent);

        $this->consents->countersign($consent, $request->user(), $request->validated());

        return redirect()
            ->route('rme.consents.show', $consent)
            ->with('status', 'Persetujuan tindakan medis sudah ditandatangani dokter.');
    }

    /**
     * DMS-RME-COMPREHENSIVE-CLOSURE-1 (OD-5-8, owner decision 2026-11-16) — the
     * supervised void: dedicated permission, written reason, maker ≠ checker,
     * only while the examination runs; the row is kept and a new consent may be
     * signed afterwards. Authorization runs in the request before validation.
     */
    public function void(VoidRmeVisitConsentRequest $request, RmeVisitConsent $consent): RedirectResponse
    {
        $this->authorize('void', $consent);

        $this->consents->void($consent, $request->user(), (string) $request->validated('void_reason'));

        return redirect()
            ->route('rme.visits.show', $consent->clinic_visit_id)
            ->with('status', 'Persetujuan tindakan medis dibatalkan. Persetujuan baru dapat ditandatangani selama pemeriksaan berlangsung.');
    }

    /**
     * Rule 243 §1 (owner revision 2026-11-17, memo M2.4) — a Doctor reads the
     * signed PTM on screen but never prints it. A real doctor attempt on a
     * consent they may read is audited here (never in the policy, which also
     * gates `@can`); the policy refuses it; the print-deny authority is asked
     * once more so a bypassed policy still cannot serve a doctor.
     */
    public function print(RmeVisitConsent $consent): View
    {
        $this->authorize('view', $consent);

        $user = request()->user();

        if ($user !== null && $this->printDeny->deniesClinicalPrint($user)) {
            $this->printDeny->auditConsentPrintRejected($user, $consent);
        }

        $this->authorize('print', $consent);

        if ($user !== null) {
            $this->printDeny->assertClinicalPrintAllowed($user);
        }

        // The printed document is unchanged (AIR-RMC-13): the full identity
        // numbers and both addresses. A Doctor never reaches it (above).
        return view('rme.consents.print', $this->documentData($consent, $this->identities->forConsentDocument($consent)));
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

        // OD-5-9 — the clinical evidence disk (rule 121), as every reader does;
        // release 2 (rule 237 §14.5a) — read-only fallback to the pre-change disk
        // while the copy has not run. Authorization above already decided; the
        // key comes from the row, never from the request. A missing, unreadable
        // or non-PNG object answers 404, never an empty image or a 500.
        $disk = $this->consents->signatureReadDisk($path);

        abort_if($disk === null, 404);
        abort_if($this->consents->readSignaturePng($disk, $path) === null, 404);

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
     * `$consentIdentity` is the identity block as this surface renders it
     * (PatientIdentityPresenter, rule 243 §3).
     *
     * @param  array{addresses_visible: bool, consenter_address: ?string, consenter_identity_number: ?string, patient_address: ?string, patient_identity_number: ?string}  $consentIdentity
     * @return array<string, mixed>
     */
    private function documentData(RmeVisitConsent $consent, array $consentIdentity): array
    {
        $consent->loadMissing(['clinicVisit.branch', 'patient', 'doctor', 'signedBy', 'voidedBy', 'doctorCountersignature.doctor']);
        $countersignature = $consent->doctorCountersignature;

        return [
            'consent' => $consent,
            // Rule 243 §3 (memo M3.1) — the identity numbers and addresses the
            // partial renders: the document's own for print and every non-doctor
            // page, masked numbers and no address rows on a Doctor's page.
            'consentIdentity' => $consentIdentity,
            'branch' => $consent->clinicVisit?->branch,
            'relationships' => (array) config('rme_consent.relationships', []),
            'genderLabels' => (array) config('rme_consent.gender_labels', []),
            'consenterSignature' => $this->consents->signatureDataUri($consent->consenter_signature_path),
            'doctorSignature' => $this->consents->signatureDataUri($consent->doctor_signature_path),
            // Rule 241 §1 — the doctor's countersignature (null when none; the
            // image null when its object is absent or unreadable — rule 224 §7).
            'doctorCountersignature' => $countersignature,
            'doctorCountersignatureImage' => $countersignature !== null
                ? $this->consents->signatureDataUri($countersignature->signature_path)
                : null,
            // Review MEDIUM-1 — "Belum ditandatangani dokter" only while a
            // countersignature is still possible; a historical consent renders
            // exactly as it did before rule 241.
            'doctorCountersignaturePending' => $this->consents->countersignaturePending($consent),
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
