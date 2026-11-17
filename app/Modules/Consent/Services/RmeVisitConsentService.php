<?php

namespace App\Modules\Consent\Services;

use App\Models\User;
use App\Modules\ClinicVisit\Interfaces\ClinicVisitRepositoryInterface;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ActiveEncounterResolver;
use App\Modules\Consent\Interfaces\RmeVisitConsentRepositoryInterface;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentRefusal;
use App\Modules\Prescription\Services\PrescriptionCanvasDecoder;
use App\Modules\RME\Services\DoctorPatientScopeService;
use App\Modules\RME\Services\DoctorRoomScopeService;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Support\AccessControl\FrontOfficeRole;
use App\Support\Clinical\ClinicalClock;
use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

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
    /** DMS-RME-COMPREHENSIVE-CLOSURE-1 (OD-5-8) — one valid consent per visit. */
    public const MESSAGE_DUPLICATE = 'Kunjungan ini sudah memiliki persetujuan tindakan medis yang sah. Batalkan (void) persetujuan itu terlebih dahulu bila perlu ditandatangani ulang.';

    public const MESSAGE_VOID_SELF = 'Persetujuan tidak dapat dibatalkan oleh akun yang mencatatnya. Pembatalan harus dilakukan oleh pengawas lain.';

    public const MESSAGE_VOID_WINDOW = 'Persetujuan hanya dapat dibatalkan selama pemeriksaan kunjungan ini masih berlangsung.';

    public const MESSAGE_VOID_PERMISSION = 'Akun ini tidak berwenang membatalkan persetujuan tindakan medis.';

    public const VOID_PERMISSION = 'void_rme_consents';

    public const MESSAGE_OUT_OF_SCOPE = 'Anda tidak memiliki akses ke persetujuan kunjungan ini.';

    public const VOID_REASON_MIN = 10;

    public const VOID_REASON_MAX = 1000;

    public const AUDIT_TABLE = 'trx_rme_visit_consents';

    public const AUDIT_VOIDED = 'RME_CONSENT_VOIDED';

    public function __construct(
        private readonly RmeVisitConsentRepositoryInterface $consents,
        private readonly RmeVisitConsentNumberGeneratorService $numbers,
        private readonly PrescriptionCanvasDecoder $canvas,
        private readonly ClinicalClock $clock,
        private readonly ClinicVisitRepositoryInterface $visits,
        private readonly AuditLogService $auditLog,
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
     * The ACTIVE ODONTOGRAM has its own, deliberately weaker authority in
     * {@see self::assertOdontogramAuthoringAllowed()}: same encounter, actor and
     * chart-identity conditions, but no signature, because charting is
     * observation rather than the treatment decision this record states.
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

        // DMS-RME-COMPREHENSIVE-CLOSURE-1 (OD-5-8; rule 219 §12a, rule 223 §6) —
        // inside a write transaction the encounter's visit row is locked FIRST and
        // the decision re-made on it: a consent void (visit FOR UPDATE) or a finish
        // committed meanwhile is seen, and one that comes later waits for this
        // write. Every consent-gated RME write calls this at the top of its
        // transaction, so the visit stays the first clinical row it locks. Outside a
        // transaction (a page's pre-check) nothing is locked.
        if (DB::transactionLevel() > 0) {
            $locked = $this->visits->lockForStatusDecision((int) $encounter->getKey());

            if ($locked === null || $locked->status !== ClinicVisit::STATUS_IN_PROGRESS) {
                throw ValidationException::withMessages([
                    'consent' => 'Rekam medis hanya dapat ditulis saat pasien sedang dalam pemeriksaan. Mulai pemeriksaan terlebih dahulu.',
                ]);
            }

            if (! $this->hasValidConsent($locked)) {
                throw ValidationException::withMessages([
                    'consent' => 'Rekam medis kunjungan ini belum dapat ditulis karena Persetujuan Tindakan Medis belum ditandatangani.',
                ]);
            }

            return $locked;
        }

        return $encounter;
    }

    /*
    |--------------------------------------------------------------------------
    | Active odontogram authoring authority
    |--------------------------------------------------------------------------
    */

    /**
     * REVISION-RME-CONSENT-ODONTOGRAM-PRECONSENT-EDIT-1 — the same positive
     * authority as the RME gate, MINUS the signature.
     *
     * This supersedes the CORRECTIVE-03 clause that made a signed consent the
     * fourth condition here. CORRECTIVE-03 reasoned that an odontogram is a
     * clinical finding recorded during a treatment decision, "exactly like the
     * note beside it". In the clinic it is not: charting teeth is OBSERVATION.
     * The doctor looks in the mouth and records what is already there, and that
     * observation is precisely what the patient is then asked to consent to. A
     * PERSETUJUAN TINDAKAN MEDIS names the treatment, so demanding the signature
     * before anyone may write down what they saw made the workflow circular —
     * the form could not be completed until the finding existed, and the finding
     * could not be recorded until the form was signed.
     *
     * What consent still gates is unchanged and deliberate:
     *
     *   - the ACTIVE RME    ({@see self::assertRmeAuthoringAllowedForPatient()}),
     *     because the record states the treatment DECISION, not the observation;
     *   - FINISHING the examination (ClinicVisitService::transitionStatus),
     *     because that hands the visit to the cashier as a bill.
     *
     * So charting early can never become a way around either: it writes only
     * `trx_odontograms`, it signs nothing, and it advances no status.
     *
     * The other three conditions are load-bearing and are NOT relaxed. An
     * odontogram row is bound to ONE visit — unlike handwriting, which Sprint
     * 64.0.2 stores on the patient's canonical record — so authority here stays
     * exact: the chart being written must BE the live encounter's chart. That is
     * what keeps history permanently read-only. A previous visit's chart is
     * evidence of what was found then, and no event today reopens it.
     *
     * Checked in this order, so the refusal names the real reason:
     *
     *   1. the patient HAS a single current `in_progress` encounter — i.e. the
     *      doctor has explicitly clicked "Mulai Pemeriksaan". Charting before an
     *      examination has started is still refused; the absence of a consent is
     *      never on its own an authorisation.
     *   2. the actor may work on that encounter (branch + clinical scope);
     *   3. the odontogram belongs to THAT encounter (else it is history).
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

        // NO consent check. See the docblock: the signature gates the record and
        // the finish transition, not the observation. Deliberately absent rather
        // than commented out, so nothing here reads as an oversight.
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

    /*
    |--------------------------------------------------------------------------
    | Performed-treatment authoring authority (rule 219)
    |--------------------------------------------------------------------------
    */

    /**
     * FEATURE-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219) — the doctor's
     * structured record of what was PERFORMED ("Tindakan Dilakukan") states the
     * treatment itself, so it takes the FULL RME authority — including the signed
     * consent — and, because a row belongs to ONE visit like an odontogram, the
     * exact chart-identity condition too:
     *
     *   1. the patient HAS a single current `in_progress` encounter;
     *   2. the actor may work on that encounter (branch + clinical scope);
     *   3. the visit being written IS that encounter (else it is history);
     *   4. a valid signed consent exists for THAT encounter.
     *
     * Anything else fails closed. The treating-doctor identity and the visit row
     * lock are asserted by RmePerformedTreatmentService on top of this. Reads are
     * never gated here.
     */
    public function assertPerformedTreatmentAuthoringAllowed(?ClinicVisit $visit, ?User $actor = null): ClinicVisit
    {
        if ($visit === null) {
            throw RmePerformedTreatmentRefusal::exception(RmePerformedTreatmentRefusal::ENCOUNTER_NOT_ACTIVE);
        }

        $encounter = app(ActiveEncounterResolver::class)->currentFor($visit->patient_id);

        if ($encounter === null) {
            throw RmePerformedTreatmentRefusal::exception(RmePerformedTreatmentRefusal::ENCOUNTER_NOT_ACTIVE);
        }

        $actor ??= Auth::user();

        if (! $actor instanceof User || ! $this->actorMayWorkOnEncounter($actor, $encounter)) {
            throw RmePerformedTreatmentRefusal::exception(RmePerformedTreatmentRefusal::ACTOR_NOT_AUTHORIZED);
        }

        if ((int) $encounter->id !== (int) $visit->id) {
            throw RmePerformedTreatmentRefusal::exception(RmePerformedTreatmentRefusal::ENCOUNTER_NOT_ACTIVE);
        }

        if (! $this->hasValidConsent($encounter)) {
            throw RmePerformedTreatmentRefusal::exception(RmePerformedTreatmentRefusal::CONSENT_REQUIRED);
        }

        return $encounter;
    }

    /**
     * May this actor work on this encounter? Branch scope plus the same clinical
     * patient scope every single-record ability already applies.
     */
    private function actorMayWorkOnEncounter(User $actor, ClinicVisit $encounter): bool
    {
        // DMS-RME-REVISION-PROGRAM-5 (CW5-05) — the front office never authors the
        // clinical record. `manage_clinic_visits` is the registration and queue
        // permission it needs for its own work, and holding it was enough to chart
        // the active odontogram and write the RME on a consented encounter. Every
        // authoring assertion and every UI capability predicate reads this method,
        // so the refusal and the hidden control are one decision.
        if (FrontOfficeRole::isVisitFrontDeskOnly($actor)) {
            return false;
        }

        // DMS-RME-REVISION-PROGRAM-8B / D7 (P8R-S1) — the clinical write
        // permission is re-asserted here too, so this predicate no longer relies
        // on the route alone: an account without it (Kasir, which is
        // context-bound but not front-desk) is refused by the service and the
        // UI capability, not just by the route middleware.
        if (! $actor->can('manage_clinic_visits')) {
            return false;
        }

        // DMS-RME-REVISION-PROGRAM-8C (D3-NEW-1, rule 228) — a clinical-role
        // ALLOWLIST, the same one prescriptions use (rule 227 §3). The front-desk
        // refusal above is a denylist of two named roles, so any other role ever
        // given manage_clinic_visits (through RoleSeeder or the role screen at
        // runtime) would otherwise inherit RME, odontogram and performed-treatment
        // authoring without anyone deciding it.
        if (! $actor->hasAnyRole(FrontOfficeRole::CLINICAL_ROLES)) {
            return false;
        }

        if (! app(RmeWorkingBranchScope::class)->allows($actor, $encounter->branch_id)) {
            return false;
        }

        // DMS-RME-REVISION-PROGRAM-8C (OD-5-7, rule 228) — the doctor ROOM scope
        // of rule 138. The current-RME write is keyed on the patient's canonical
        // record book, so it never reached the visit's own room guard; the live
        // encounter resolved here is what the room rule protects.
        if (app(DoctorRoomScopeService::class)->deniesActiveVisitOutsideRoom($actor, $encounter)) {
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
        // DMS-RME-REVISION-PROGRAM-8D (rule 228 §10.3) — both signatures carry
        // the signature payload bound here too, so a caller that never passes
        // through StoreRmeVisitConsentRequest is bounded the same way (CG-06).
        $consenterSignature = $this->canvas->decode(
            $data['consenter_signature'] ?? null,
            'consenter_signature',
            maxLength: PrescriptionCanvasDecoder::MAX_SIGNATURE_PAYLOAD_LENGTH,
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
            maxLength: PrescriptionCanvasDecoder::MAX_SIGNATURE_PAYLOAD_LENGTH,
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

        // DMS-RME-COMPREHENSIVE-CLOSURE-1 (8D finding 1, rule 224 pattern) — every
        // signature object this call writes, and whether the transaction body
        // finished. A failure before that (second write, insert) removes them; a
        // failure after it (the COMMIT outcome is unknown) keeps them.
        $written = [];
        $bodyDone = false;

        try {
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
                &$written,
                &$bodyDone,
            ): RmeVisitConsent {
                $consent = $this->signWithinTransaction($visit, $operator, $data, $template, $documentationConsent, $consenterSignature, $doctorSignature, $patient, $relationship, $isSelf, $written);
                $bodyDone = true;

                return $consent;
            });
        } catch (Throwable $e) {
            if (! $bodyDone) {
                $this->discardUncommitted($written);
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $template
     * @param  list<string>  $written  receives every signature key this call wrote
     */
    private function signWithinTransaction(
        ClinicVisit $visit,
        User $operator,
        array $data,
        array $template,
        bool $documentationConsent,
        string $consenterSignature,
        ?string $doctorSignature,
        $patient,
        string $relationship,
        bool $isSelf,
        array &$written,
    ): RmeVisitConsent {
        // DMS-RME-REVISION-PROGRAM-5 (CW5-07) — assertSignable() at the top
        // read the copy the request loaded. Take the visit row lock (the same
        // lock, in the same order, as void() and transitionStatus()) and decide
        // the window again on the persisted row, BEFORE any signature file is
        // written, so a cancel or a finish that committed in the meantime
        // leaves neither a consent nor an orphaned image behind.
        $current = $this->visits->lockForStatusDecision((int) $visit->getKey());

        if ($current === null) {
            throw ValidationException::withMessages([
                'clinic_visit_id' => 'Kunjungan tidak ditemukan.',
            ]);
        }

        // Before any signature object is written.
        $this->assertOperatorWithinScope($operator, $current);

        $this->assertSignable($current);

        // DMS-RME-COMPREHENSIVE-CLOSURE-1 (OD-5-8, owner decision 2026-11-16) —
        // one valid consent per visit, decided on the LOCKED visit row, so a
        // double submit or two operators cannot both sign. A new signature is
        // possible only after the valid one was voided (void(), supervised).
        if ($this->validConsentFor($current) !== null) {
            throw ValidationException::withMessages([
                'consent' => self::MESSAGE_DUPLICATE,
            ]);
        }

        $consentNumber = $this->numbers->generate();
        $directory = trim((string) config('rme_consent.signature_directory', 'rme-consents'), '/');
        $folder = $directory.'/'.$consentNumber;

        $consenterPath = $folder.'/consenter-'.Str::random(12).'.png';
        $this->disk()->put($consenterPath, $consenterSignature);
        $written[] = $consenterPath;

        $doctorPath = null;
        if ($doctorSignature !== null) {
            $doctorPath = $folder.'/doctor-'.Str::random(12).'.png';
            $this->disk()->put($doctorPath, $doctorSignature);
            $written[] = $doctorPath;
        }

        // The treating doctor comes from the visit, never from the request.
        // Whoever operates the tablet cannot nominate a different doctor.
        // CW5-07 — read from the LOCKED row, so a room re-assignment that
        // committed before the lock cannot leave the consent naming the
        // previous doctor.
        $doctor = $current->doctor;

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
    }

    /**
     * Correction path. A signed consent is never edited or deleted; it is
     * voided, with a reason, and a fresh one is signed in its place.
     *
     * The void takes the VISIT row lock first (rule 223 §6) and re-reads the
     * consent under it, so two voids of one consent cannot both succeed.
     * DMS-RME-COMPREHENSIVE-CLOSURE-1 (OD-5-8) gave it its first production
     * caller (`rme.consents.void`, supervised) and closed rule 219 §12a with it:
     * every consent-gated RME write (sheet, page, diagnosis, override,
     * finalize) now re-decides the consent under that same visit lock
     * (assertRmeAuthoringAllowedForPatient() inside a transaction), so it either
     * commits under a still-valid consent or waits and sees it voided.
     * Odontogram charting does not depend on consent (AIR-RMC-14) and is
     * unaffected.
     */
    public function void(RmeVisitConsent $consent, User $operator, string $reason): RmeVisitConsent
    {
        $reason = trim($reason);

        if ($consent->isVoided()) {
            throw ValidationException::withMessages([
                'consent' => 'Persetujuan ini sudah dibatalkan.',
            ]);
        }

        // DMS-RME-COMPREHENSIVE-CLOSURE-1 (OD-5-8, owner decision 2026-11-16) — the
        // supervised void. A written reason of real length, the dedicated
        // permission re-asserted here (CG-06; the route and the policy ask first).
        if (mb_strlen($reason) < self::VOID_REASON_MIN || mb_strlen($reason) > self::VOID_REASON_MAX) {
            throw ValidationException::withMessages([
                'void_reason' => 'Alasan pembatalan wajib diisi, '.self::VOID_REASON_MIN.'–'.self::VOID_REASON_MAX.' karakter.',
            ]);
        }

        if (! $operator->can(self::VOID_PERMISSION)) {
            throw ValidationException::withMessages([
                'consent' => self::MESSAGE_VOID_PERMISSION,
            ]);
        }

        return DB::transaction(function () use ($consent, $operator, $reason): RmeVisitConsent {
            // The same lock, in the same order, as signWithinTransaction() and
            // transitionStatus(): FOR NO KEY UPDATE (rule 223 §1a), visit first.
            $visit = $this->visits->lockForStatusDecision((int) $consent->clinic_visit_id);
            $consent->refresh();

            if ($consent->isVoided()) {
                throw ValidationException::withMessages([
                    'consent' => 'Persetujuan ini sudah dibatalkan.',
                ]);
            }

            if ($visit !== null) {
                $this->assertOperatorWithinScope($operator, $visit);
            }

            // Maker ≠ checker (CG-07), inside the transaction and identity-based,
            // so it binds Super Admin too: the account that recorded the consent
            // never voids it.
            if ((int) $consent->signed_by === (int) $operator->getKey()) {
                throw ValidationException::withMessages([
                    'consent' => self::MESSAGE_VOID_SELF,
                ]);
            }

            // Only while the examination is running: a void after "Selesai
            // Pemeriksaan" would leave a finished record without a consent and no
            // way to sign again (signing is only possible in_progress).
            if ($visit === null || $visit->status !== ClinicVisit::STATUS_IN_PROGRESS) {
                throw ValidationException::withMessages([
                    'consent' => self::MESSAGE_VOID_WINDOW,
                ]);
            }

            $consent->forceFill([
                'voided_at' => $this->clock->now(),
                'voided_by' => $operator->id,
                'void_reason' => $reason,
            ])->save();

            // Ids and the reason's length only — the reason text stays on the
            // consent row, never in the audit payload.
            $this->auditLog->log(
                self::AUDIT_TABLE,
                (int) $consent->getKey(),
                self::AUDIT_VOIDED,
                null,
                [
                    'clinic_visit_id' => (int) $consent->clinic_visit_id,
                    'signed_by' => (int) $consent->signed_by,
                    'reason_length' => mb_strlen($reason),
                ],
                $operator,
            );

            return $consent->refresh();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Signature files
    |--------------------------------------------------------------------------
    */

    /**
     * DMS-RME-COMPREHENSIVE-CLOSURE-1 (OD-5-9, rule 121 §1) — consent signatures
     * are clinical evidence: they live where ClinicalEvidenceStorage says, never
     * on a disk named here, and every new signature is written there. Objects
     * written before this change are copied there, key for key, by
     * `rme:consent-signatures-migrate` (dry run, then --apply).
     *
     * Release 2 (owner decision 2026-11-17, rule 237 §14.5a) — until that copy is
     * applied and verified, the READERS keep a temporary, read-only fallback to
     * the pre-change disk; see signatureReadDisk(). Writers never use it.
     */
    public function diskName(): string
    {
        return ClinicalEvidenceStorage::diskName();
    }

    public function disk(): Filesystem
    {
        return ClinicalEvidenceStorage::disk();
    }

    /**
     * Read a stored signature back as a data URI so a print/PDF template can
     * embed it without the file ever gaining a public URL. Null when the object
     * is absent, unreadable or not a PNG (an honest "not available").
     */
    public function signatureDataUri(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (ClinicalEvidenceStorage::isInlineDataUri($path)) {
            return $path;
        }

        $disk = $this->signatureReadDisk($path);
        $bytes = $disk === null ? null : $this->readSignaturePng($disk, $path);

        return $bytes === null ? null : 'data:image/png;base64,'.base64_encode($bytes);
    }

    /**
     * DMS-RME-COMPREHENSIVE-CLOSURE-1 release 2 (owner decision 2026-11-17,
     * rule 237 §14.5a) — the disk a stored consent signature is READ from.
     *
     * 1. The clinical evidence disk, whenever it holds the object — even when that
     *    object turns out unreadable or not a PNG: a damaged copy is reported as
     *    "not available", never silently replaced by the old one.
     * 2. Only when the clinical evidence disk does not hold the object yet, and
     *    only while `rme_consent.legacy_read_fallback` is on, the pre-change disk
     *    (`rme_consent.legacy_signature_disk`) — never the public disk, never the
     *    clinical evidence disk again.
     * 3. Otherwise null.
     *
     * Read-only: nothing is written, copied, moved or deleted here. The key comes
     * from the consent row, never from the request; authorization is the
     * caller's and happens before this is asked. Removing the fallback is a
     * separate, owner-approved step after the copy is applied and verified.
     */
    public function signatureReadDisk(?string $path): ?Filesystem
    {
        if ($path === null || $path === '' || ClinicalEvidenceStorage::isInlineDataUri($path)) {
            return null;
        }

        // Security review (2026-11-17) — defence in depth: only the shape sign()
        // has always written (`<signature_directory>/<consent number>/<name>.png`)
        // is ever looked up, on either disk. A row can never be pointed at some
        // other object of the same disk.
        if (! $this->isSignatureKeyShape($path)) {
            Log::warning('A consent signature key has an unexpected shape.');

            return null;
        }

        try {
            $primary = ClinicalEvidenceStorage::disk();

            if ($primary->exists($path)) {
                return $primary;
            }

            $legacy = $this->legacySignatureReadDisk();

            if ($legacy !== null && $legacy->exists($path)) {
                return $legacy;
            }
        } catch (Throwable $e) {
            Log::warning('A consent signature object could not be located.', [
                'type' => $e::class,
            ]);
        }

        return null;
    }

    /**
     * The signature's bytes when they are a PNG; null (logged without the key)
     * when they cannot be read or are not a PNG, as ClinicalEvidenceStorage does.
     */
    public function readSignaturePng(Filesystem $disk, string $path): ?string
    {
        try {
            $bytes = (string) $disk->get($path);
        } catch (Throwable $e) {
            Log::warning('A stored consent signature could not be read.', [
                'type' => $e::class,
            ]);

            return null;
        }

        if (! str_starts_with($bytes, "\x89PNG")) {
            Log::warning('A stored consent signature is not a PNG image.');

            return null;
        }

        return $bytes;
    }

    /**
     * The only disks the temporary read fallback may ever open (an allowlist:
     * the private disk consent signatures were written to before OD-5-9).
     *
     * @var list<string>
     */
    public const LEGACY_SIGNATURE_READ_DISKS = ['local'];

    private function legacySignatureReadDisk(): ?Filesystem
    {
        if (! (bool) config('rme_consent.legacy_read_fallback', false)) {
            return null;
        }

        $name = (string) config('rme_consent.legacy_signature_disk', '');

        if (! in_array($name, self::LEGACY_SIGNATURE_READ_DISKS, true)
            || $name === ClinicalEvidenceStorage::diskName()
            || in_array($name, ClinicalEvidenceStorage::FORBIDDEN_DISKS, true)) {
            return null;
        }

        return Storage::disk($name);
    }

    /**
     * `<signature_directory>/<one segment>/<one segment>.png`, relative, with no
     * `.`/`..` segment, no backslash and no control character.
     */
    private function isSignatureKeyShape(string $path): bool
    {
        $directory = trim((string) config('rme_consent.signature_directory', 'rme-consents'), '/');

        if ($directory === '' || preg_match('/[\\\\\x00-\x1F\x7F]/', $path) === 1) {
            return false;
        }

        $segments = explode('/', $path);

        if (count($segments) !== 3 || $segments[0] !== $directory || ! str_ends_with($segments[2], '.png')) {
            return false;
        }

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * DMS-RME-COMPREHENSIVE-CLOSURE-1 (review F6, CG-06 third layer) — the
     * branch and patient scope the policy asks (RmeWorkingBranchScope, then
     * DoctorPatientScopeService), re-asserted on the locked visit so a caller
     * that never passes through the policy (a command, a job, a script) is held
     * to it too.
     */
    private function assertOperatorWithinScope(User $operator, ClinicVisit $visit): void
    {
        $patientScope = app(DoctorPatientScopeService::class)->authorizeVisitAccess($operator, $visit);

        if (! app(RmeWorkingBranchScope::class)->allows($operator, $visit->branch_id)
            || $patientScope === false
            || ($patientScope instanceof Response && $patientScope->denied())) {
            throw new AuthorizationException(self::MESSAGE_OUT_OF_SCOPE);
        }
    }

    /** @param  list<string>  $keys */
    private function discardUncommitted(array $keys): void
    {
        foreach ($keys as $key) {
            try {
                $this->disk()->delete($key);
            } catch (Throwable $e) {
                // A private orphan is the safe failure; never mask the original error.
                Log::warning('An uncommitted consent signature object could not be removed.', ['type' => $e::class]);
            }
        }
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
