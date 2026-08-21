<?php

namespace App\Modules\Consent\Services;

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ActiveEncounterResolver;
use App\Modules\Consent\Interfaces\RmeVisitConsentRepositoryInterface;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Prescription\Services\PrescriptionCanvasDecoder;
use App\Modules\RME\Services\DoctorPatientScopeService;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Support\Clinical\ClinicalClock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-01.
 *
 * The single place a PERSETUJUAN TINDAKAN MEDIS is created, and the single
 * source of truth for whether a visit has one.
 *
 * The rule this service exists to enforce: a consent is evidence that a person
 * signed something, so it may only be written from an actual signature, never
 * from a caller asserting that a signature happened. Before this sprint the
 * payment request supplied two booleans and the payment service wrote them to
 * the visit and then asserted against what it had just written — the gate
 * authored its own evidence. Nothing here accepts a "signed = true" input.
 */
class RmeVisitConsentService
{
    public function __construct(
        private readonly RmeVisitConsentRepositoryInterface $consents,
        private readonly RmeVisitConsentNumberGeneratorService $numbers,
        private readonly PrescriptionCanvasDecoder $canvas,
        private readonly ClinicalClock $clock,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Reads
    |--------------------------------------------------------------------------
    */

    /**
     * The one predicate the payment gate depends on.
     */
    public function hasValidConsent(ClinicVisit $visit): bool
    {
        return $this->validConsentFor($visit) !== null;
    }

    public function validConsentFor(ClinicVisit $visit): ?RmeVisitConsent
    {
        return $this->consents->validForVisit($visit->id);
    }

    /**
     * @return Collection<int, RmeVisitConsent>
     */
    public function historyFor(ClinicVisit $visit): Collection
    {
        return $this->consents->historyForVisit($visit->id);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function availableTemplates(): array
    {
        return (array) config('rme_consent.templates', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function template(string $code): array
    {
        $template = config('rme_consent.templates.'.$code);

        if (! is_array($template)) {
            throw ValidationException::withMessages([
                'template_code' => 'Form persetujuan tidak dikenal.',
            ]);
        }

        return $template;
    }

    /*
    |--------------------------------------------------------------------------
    | Timing gate
    |--------------------------------------------------------------------------
    */

    /**
     * FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / CORRECTIVE-01 — the consent window.
     *
     * Consent is signable during the EXAMINATION and at no other time:
     * `in_progress`, i.e. after the authorized doctor has explicitly clicked
     * "Mulai Pemeriksaan", and before they finish it.
     *
     *   - `registered` / `waiting`: the doctor has not started. There is no
     *     treatment decided yet, so a signature there would be consent to nothing
     *     in particular — exactly the hollow consent this gate exists to prevent.
     *   - `cashier_pending`: the examination is over and the treatment already
     *     happened. Signing now would be recording agreement after the fact.
     *   - `completed` / `cancelled`: terminal, so signing would be back-dating
     *     evidence onto a closed encounter.
     *
     * Fails closed: anything that is not `in_progress` is refused.
     */
    public function isWithinConsentWindow(ClinicVisit $visit): bool
    {
        return $visit->status === ClinicVisit::STATUS_IN_PROGRESS;
    }

    public function isSignable(ClinicVisit $visit): bool
    {
        return $this->isWithinConsentWindow($visit);
    }

    public function assertSignable(ClinicVisit $visit): void
    {
        if ($this->isSignable($visit)) {
            return;
        }

        throw ValidationException::withMessages([
            'clinic_visit_id' => 'Persetujuan tindakan medis hanya dapat ditandatangani selama pemeriksaan dokter berlangsung. Mulai pemeriksaan terlebih dahulu; setelah pemeriksaan diselesaikan, persetujuan tidak dapat lagi ditandatangani untuk kunjungan ini.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | RME authoring authority
    |--------------------------------------------------------------------------
    */

    /**
     * CORRECTIVE-02 — POSITIVE authority. The service must establish that a
     * legitimate, authorized, consented examination is happening; it must never
     * infer permission from the mere absence of a blocker.
     *
     * The earlier shape asked "does this patient have an open UNCONSENTED
     * encounter?" and allowed the write when the answer was no. That is an
     * absence-of-blocker rule, and it had a reproducer: CANCEL the live visit and
     * the patient suddenly has no open unconsented encounter, so their canonical
     * record — which for a returning patient is an old terminal visit's record —
     * became writable again. Completing the visit did the same thing.
     *
     * So the question is inverted. A current-RME write is allowed only when ALL of
     * these hold, established from server state:
     *
     *   1. the patient HAS a current encounter (ActiveEncounterResolver);
     *   2. that encounter is `in_progress`;
     *   3. the actor may work on that encounter (branch scope + clinical scope);
     *   4. a valid signed consent exists for THAT SAME encounter and patient.
     *
     * Anything else fails closed, including "no encounter at all".
     *
     * READS ARE NEVER GATED by this. Historical native RME, the Legacy RME
     * archive, previous odontograms and the Legacy Odontogram archive stay
     * readable regardless: the doctor deciding today's treatment needs the
     * history, and reading harms nobody.
     *
     * The ACTIVE ODONTOGRAM is deliberately outside this gate; its workflow is
     * unchanged by this sprint.
     */
    public function resolveAuthorizedEncounter(?int $patientId, ?User $actor = null): ?ClinicVisit
    {
        $encounter = app(ActiveEncounterResolver::class)->currentFor($patientId);

        if ($encounter === null) {
            return null;
        }

        $actor ??= Auth::user();

        // Fail closed on an unresolvable actor: skipping the scope check would
        // silently drop one of the four conditions.
        if (! $actor instanceof User || ! $this->actorMayWorkOnEncounter($actor, $encounter)) {
            return null;
        }

        // The consent must belong to this encounter — and therefore to this
        // patient, since a consent carries its visit.
        return $this->hasValidConsent($encounter) ? $encounter : null;
    }

    public function canAuthorRmeForPatient(?int $patientId, ?User $actor = null): bool
    {
        return $this->resolveAuthorizedEncounter($patientId, $actor) !== null;
    }

    /**
     * The single assertion every current-RME write funnels through.
     *
     * Returns the encounter it authorised, so a caller that needs to know which
     * visit the work belongs to does not have to ask again (and cannot ask the
     * request).
     */
    public function assertRmeAuthoringAllowedForPatient(?int $patientId, ?User $actor = null): ClinicVisit
    {
        $encounter = app(ActiveEncounterResolver::class)->currentFor($patientId);

        if ($encounter === null) {
            // Distinguish "none" from "ambiguous" so the operator knows what to do.
            $active = app(ActiveEncounterResolver::class)->activeFor($patientId);

            throw ValidationException::withMessages([
                'consent' => $active->count() > 1
                    ? 'Pasien ini memiliki lebih dari satu pemeriksaan yang sedang berjalan. Selesaikan atau batalkan kunjungan yang tidak dipakai sebelum menulis rekam medis.'
                    : 'Rekam medis hanya dapat ditulis saat pasien sedang dalam pemeriksaan. Mulai pemeriksaan terlebih dahulu.',
            ]);
        }

        $actor ??= Auth::user();

        // Fail closed on an unresolvable actor rather than skipping condition 3.
        if (! $actor instanceof User || ! $this->actorMayWorkOnEncounter($actor, $encounter)) {
            throw ValidationException::withMessages([
                'consent' => 'Anda tidak berwenang menulis rekam medis untuk pemeriksaan pasien ini.',
            ]);
        }

        if (! $this->hasValidConsent($encounter)) {
            throw ValidationException::withMessages([
                'consent' => 'Rekam medis kunjungan ini belum dapat ditulis karena Persetujuan Tindakan Medis belum ditandatangani.',
            ]);
        }

        return $encounter;
    }

    /*
    |--------------------------------------------------------------------------
    | Active odontogram authoring authority
    |--------------------------------------------------------------------------
    */

    /**
     * CORRECTIVE-03 — the SAME positive authority, now covering the ACTIVE
     * odontogram.
     *
     * This supersedes the earlier contract, under which consent gated only RME
     * authoring and the odontogram workflow was left unchanged. That split was
     * indefensible: an odontogram is a clinical finding recorded on a patient
     * during a treatment decision, exactly like the note beside it, so consenting
     * to one and not the other consents to nothing coherent.
     *
     * An odontogram row is bound to ONE visit — unlike handwriting, which Sprint
     * 64.0.2 stores on the patient's canonical record — so authority here is
     * stricter than the RME gate and can be exact: the chart being written must
     * BE the live encounter's chart. That is what keeps history permanently
     * read-only. A previous visit's chart is evidence of what was found then;
     * signing a consent today authorises recording today's findings, never
     * rewriting an earlier encounter's.
     *
     * All four RME conditions still hold, in this order, so the refusal names the
     * real reason:
     *
     *   1. the patient HAS a single current `in_progress` encounter;
     *   2. the actor may work on that encounter (branch + clinical scope);
     *   3. the odontogram belongs to THAT encounter (else it is history);
     *   4. a valid signed consent exists for that encounter.
     */
    public function assertOdontogramAuthoringAllowed(?ClinicVisit $visit, ?User $actor = null): ClinicVisit
    {
        if ($visit === null) {
            // A chart with no resolvable visit has no encounter to authorise it.
            throw ValidationException::withMessages([
                'consent' => 'Odontogram ini tidak terhubung ke kunjungan mana pun sehingga tidak dapat diubah.',
            ]);
        }

        $encounter = app(ActiveEncounterResolver::class)->currentFor($visit->patient_id);

        if ($encounter === null) {
            $active = app(ActiveEncounterResolver::class)->activeFor($visit->patient_id);

            throw ValidationException::withMessages([
                'consent' => $active->count() > 1
                    ? 'Pasien ini memiliki lebih dari satu pemeriksaan yang sedang berjalan. Selesaikan atau batalkan kunjungan yang tidak dipakai sebelum mengubah odontogram.'
                    : 'Odontogram hanya dapat diubah saat pasien sedang dalam pemeriksaan. Mulai pemeriksaan terlebih dahulu.',
            ]);
        }

        $actor ??= Auth::user();

        if (! $actor instanceof User || ! $this->actorMayWorkOnEncounter($actor, $encounter)) {
            throw ValidationException::withMessages([
                'consent' => 'Anda tidak berwenang mengubah odontogram untuk pemeriksaan pasien ini.',
            ]);
        }

        if ($encounter->id !== $visit->id) {
            throw ValidationException::withMessages([
                'consent' => 'Odontogram kunjungan sebelumnya adalah riwayat dan bersifat hanya-baca. Catat temuan pada odontogram kunjungan yang sedang berjalan.',
            ]);
        }

        if (! $this->hasValidConsent($encounter)) {
            throw ValidationException::withMessages([
                'consent' => 'Odontogram kunjungan ini belum dapat diubah karena Persetujuan Tindakan Medis belum ditandatangani.',
            ]);
        }

        return $encounter;
    }

    /**
     * Presentation predicate for the odontogram page: may this chart be edited
     * right now? Never the authorisation answer — assertOdontogramAuthoringAllowed()
     * is. The page stays viewable either way.
     */
    public function canAuthorOdontogramFor(?ClinicVisit $visit, ?User $actor = null): bool
    {
        if ($visit === null) {
            return false;
        }

        try {
            $this->assertOdontogramAuthoringAllowed($visit, $actor);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    /**
     * May this actor work on this encounter? Branch scope plus the same clinical
     * patient scope every single-record ability already applies.
     */
    private function actorMayWorkOnEncounter(User $actor, ClinicVisit $encounter): bool
    {
        if (! app(RmeWorkingBranchScope::class)->allows($actor, $encounter->branch_id)) {
            return false;
        }

        return app(DoctorPatientScopeService::class)->doctorCanAccessVisit($actor, $encounter);
    }

    /**
     * Presentation predicate for the visit detail page: does THIS visit still need
     * a signature before its examination can be recorded? Never an authorisation
     * answer — assertRmeAuthoringAllowedForPatient() is.
     */
    public function requiresConsentBeforeRmeAuthoring(ClinicVisit $visit): bool
    {
        return $this->isWithinConsentWindow($visit) && ! $this->hasValidConsent($visit);
    }

    /*
    |--------------------------------------------------------------------------
    | Writes
    |--------------------------------------------------------------------------
    */

    /**
     * Record a signed consent for a visit.
     *
     * @param  array<string, mixed>  $data
     */
    public function sign(ClinicVisit $visit, User $operator, array $data): RmeVisitConsent
    {
        $this->assertSignable($visit);

        $template = $this->template((string) ($data['template_code'] ?? ''));

        // Clause 8 must be an explicit choice. A missing value is not "no", and
        // it is certainly not "yes" — it is an incomplete consent.
        if (! array_key_exists('documentation_consent', $data) || $data['documentation_consent'] === null || $data['documentation_consent'] === '') {
            throw ValidationException::withMessages([
                'documentation_consent' => 'Persetujuan dokumentasi/publikasi harus dipilih YA atau TIDAK.',
            ]);
        }

        $documentationConsent = filter_var($data['documentation_consent'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($documentationConsent === null) {
            throw ValidationException::withMessages([
                'documentation_consent' => 'Persetujuan dokumentasi/publikasi harus dipilih YA atau TIDAK.',
            ]);
        }

        // The signature is the evidence. No signature, no consent.
        $consenterSignature = $this->canvas->decode(
            $data['consenter_signature'] ?? null,
            'consenter_signature',
        );

        if ($consenterSignature === null) {
            throw ValidationException::withMessages([
                'consenter_signature' => 'Tanda tangan pemberi persetujuan wajib diisi.',
            ]);
        }

        // The doctor's signature area is supported but optional: by this point
        // the doctor has finished the examination and may not be at the cashier.
        // The doctor's IDENTITY, however, is always recorded — see below.
        $doctorSignature = $this->canvas->decode(
            $data['doctor_signature'] ?? null,
            'doctor_signature',
        );

        $visit->loadMissing(['patient', 'doctor', 'branch']);
        $patient = $visit->patient;

        if ($patient === null) {
            throw ValidationException::withMessages([
                'clinic_visit_id' => 'Kunjungan ini tidak memiliki data pasien.',
            ]);
        }

        $relationship = (string) ($data['consenter_relationship'] ?? '');
        $isSelf = $relationship === 'self';

        return DB::transaction(function () use (
            $visit,
            $operator,
            $data,
            $template,
            $documentationConsent,
            $consenterSignature,
            $doctorSignature,
            $patient,
            $relationship,
            $isSelf,
        ): RmeVisitConsent {
            $consentNumber = $this->numbers->generate();
            $directory = trim((string) config('rme_consent.signature_directory', 'rme-consents'), '/');
            $folder = $directory.'/'.$consentNumber;

            $consenterPath = $folder.'/consenter-'.Str::random(12).'.png';
            $this->disk()->put($consenterPath, $consenterSignature);

            $doctorPath = null;
            if ($doctorSignature !== null) {
                $doctorPath = $folder.'/doctor-'.Str::random(12).'.png';
                $this->disk()->put($doctorPath, $doctorSignature);
            }

            // The treating doctor comes from the visit, never from the request.
            // Whoever operates the tablet cannot nominate a different doctor.
            $doctor = $visit->doctor;

            return $this->consents->create([
                'consent_number' => $consentNumber,
                'branch_id' => $visit->branch_id,
                'clinic_visit_id' => $visit->id,
                'patient_id' => $patient->id,
                'doctor_id' => $doctor?->id,

                'template_code' => $template['code'],
                'template_version' => $template['version'],
                'content_snapshot' => $this->snapshot($template),

                'consenter_relationship' => $relationship,
                'consenter_name' => $isSelf
                    ? $patient->name
                    : (string) ($data['consenter_name'] ?? ''),
                'consenter_age' => $isSelf
                    ? (string) ($patient->age() ?? '')
                    : (string) ($data['consenter_age'] ?? ''),
                'consenter_gender' => $isSelf
                    ? $patient->gender
                    : ($data['consenter_gender'] ?? null),
                'consenter_address' => $isSelf
                    ? $patient->address
                    : ($data['consenter_address'] ?? null),
                'consenter_identity_number' => $isSelf
                    ? $patient->ktp_number
                    : ($data['consenter_identity_number'] ?? null),

                'patient_name_snapshot' => $patient->name,
                'patient_age_snapshot' => (string) ($patient->age() ?? ''),
                'patient_gender_snapshot' => $patient->gender,
                'patient_address_snapshot' => $patient->address,
                'patient_identity_number_snapshot' => $patient->ktp_number,
                'medical_record_number_snapshot' => $patient->medical_record_number,

                'medical_action' => (string) ($data['medical_action'] ?? ''),
                'treatment_summary' => $data['treatment_summary'] ?? null,

                'documentation_consent' => $documentationConsent,

                'consenter_signature_path' => $consenterPath,
                'doctor_signature_path' => $doctorPath,
                'doctor_name_snapshot' => $doctor?->name,
                'signed_location' => $template['location'] ?? null,
                'signed_at' => $this->clock->now(),
                'signed_by' => $operator->id,
            ]);
        });
    }

    /**
     * Correction path. A signed consent is never edited or deleted; it is
     * voided, with a reason, and a fresh one is signed in its place.
     */
    public function void(RmeVisitConsent $consent, User $operator, string $reason): RmeVisitConsent
    {
        if ($consent->isVoided()) {
            throw ValidationException::withMessages([
                'consent' => 'Persetujuan ini sudah dibatalkan.',
            ]);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'void_reason' => 'Alasan pembatalan wajib diisi.',
            ]);
        }

        return DB::transaction(function () use ($consent, $operator, $reason): RmeVisitConsent {
            $consent->forceFill([
                'voided_at' => $this->clock->now(),
                'voided_by' => $operator->id,
                'void_reason' => $reason,
            ])->save();

            return $consent->refresh();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Signature files
    |--------------------------------------------------------------------------
    */

    public function diskName(): string
    {
        return (string) config('rme_consent.signature_disk', 'local');
    }

    public function disk()
    {
        return Storage::disk($this->diskName());
    }

    /**
     * Read a stored signature back as a data URI so a print/PDF template can
     * embed it without the file ever gaining a public URL.
     */
    public function signatureDataUri(?string $path): ?string
    {
        if ($path === null || $path === '' || ! $this->disk()->exists($path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode((string) $this->disk()->get($path));
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Freeze the wording that was actually agreed to. Editing the template
     * afterwards must never change what a patient already signed.
     *
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    private function snapshot(array $template): array
    {
        return [
            'code' => $template['code'],
            'version' => $template['version'],
            'title' => $template['title'],
            'consent_statement' => $template['consent_statement'] ?? null,
            'clauses_intro' => $template['clauses_intro'] ?? null,
            'clauses' => $template['clauses'] ?? [],
            'documentation_clause' => $template['documentation_clause'] ?? null,
            'declaration' => $template['declaration'] ?? null,
            'signature_labels' => $template['signature_labels'] ?? [],
            'location' => $template['location'] ?? null,
        ];
    }
}
