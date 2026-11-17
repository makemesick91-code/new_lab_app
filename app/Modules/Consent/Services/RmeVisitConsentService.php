<?php

namespace App\Modules\Consent\Services;

use App\Models\User;
use App\Modules\ClinicVisit\Interfaces\ClinicVisitRepositoryInterface;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ActiveEncounterResolver;
use App\Modules\Consent\Interfaces\RmeVisitConsentRepositoryInterface;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Models\RmeVisitConsentDoctorSignature;
use App\Modules\Doctor\Services\DoctorIdentityResolver;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentRefusal;
use App\Modules\Prescription\Services\PrescriptionCanvasDecoder;
use App\Modules\RME\Services\DoctorPatientScopeService;
use App\Modules\RME\Services\DoctorRoomScopeService;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Services\Foundation\FeatureFlagService;
use App\Support\AccessControl\FrontOfficeRole;
use App\Support\Clinical\ClinicalClock;
use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\UniqueConstraintViolationException;
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

    /*
     * DMS memo M1.1–M1.3, PR1 (rule 241 §1; owner decisions 2026-11-17) — the
     * treating doctor's countersignature on the PTM.
     */
    public const COUNTERSIGN_PERMISSION = 'countersign_rme_consents';

    public const AUDIT_DOCTOR_SIGNED = 'RME_CONSENT_DOCTOR_SIGNED';

    public const MESSAGE_COUNTERSIGN_PERMISSION = 'Akun ini tidak berwenang menandatangani persetujuan tindakan medis sebagai dokter.';

    // One message per guard, so a refusal names the guard that fired.
    public const MESSAGE_COUNTERSIGN_ENCOUNTER = 'Akun ini tidak dapat bekerja pada pemeriksaan kunjungan ini, sehingga tidak dapat menandatangani persetujuannya sebagai dokter.';

    public const MESSAGE_COUNTERSIGN_NOT_LINKED = 'Akun ini tidak terhubung ke data dokter, sehingga tidak dapat menandatangani persetujuan tindakan medis sebagai dokter.';

    public const MESSAGE_COUNTERSIGN_DOCTOR_INACTIVE = 'Data dokter yang terhubung ke akun ini tidak aktif.';

    public const MESSAGE_COUNTERSIGN_NOT_VISIT_DOCTOR = 'Hanya dokter yang menangani kunjungan ini yang dapat menandatangani persetujuan tindakan medisnya.';

    public const MESSAGE_COUNTERSIGN_NOT_CONSENT_DOCTOR = 'Persetujuan ini mencantumkan dokter lain. Bila dokter yang menangani berganti, persetujuan dibatalkan (void) dan ditandatangani ulang.';

    public const MESSAGE_COUNTERSIGN_WINDOW = 'Persetujuan tindakan medis hanya dapat ditandatangani dokter selama pemeriksaan kunjungan ini berlangsung.';

    public const MESSAGE_COUNTERSIGN_VOIDED = 'Persetujuan ini sudah dibatalkan dan tidak dapat ditandatangani dokter.';

    public const MESSAGE_COUNTERSIGN_SELF = 'Akun yang mencatat tanda tangan pasien tidak dapat sekaligus menandatangani sebagai dokter. Minta pengawas membatalkan (void) persetujuan ini, lalu tanda tangan pasien diambil ulang oleh petugas front office.';

    public const MESSAGE_COUNTERSIGN_DUPLICATE = 'Persetujuan tindakan medis ini sudah ditandatangani dokter.';

    /** Rule 241 §4 (review LOW-1) — the capture takes no doctor drawing any more. */
    public const MESSAGE_DOCTOR_SIGNATURE_ON_CAPTURE = 'Tanda tangan dokter tidak diambil pada formulir persetujuan. Dokter yang menangani menandatangani persetujuan ini dari Ruang Perawatan.';

    /*
     * DMS memo M1.1–M1.3, PR2 (rule 241 §2; owner decisions 2026-11-17) — the
     * PTM is EXECUTED only when the patient signed AND the treating doctor
     * countersigned. Every clinical write waits for both.
     */
    public const PTM_AWAITING_PATIENT = 'awaiting_patient';

    public const PTM_AWAITING_DOCTOR = 'awaiting_doctor';

    public const PTM_EXECUTED = 'executed';

    public const MESSAGE_RME_AWAITING_PATIENT = 'Rekam medis kunjungan ini belum dapat ditulis karena Persetujuan Tindakan Medis belum ditandatangani.';

    public const MESSAGE_RME_AWAITING_DOCTOR = 'Rekam medis kunjungan ini belum dapat ditulis karena Persetujuan Tindakan Medis belum ditandatangani dokter.';

    public const MESSAGE_ODONTOGRAM_AWAITING_PATIENT = 'Odontogram kunjungan ini belum dapat diubah karena Persetujuan Tindakan Medis belum ditandatangani.';

    public const MESSAGE_ODONTOGRAM_AWAITING_DOCTOR = 'Odontogram kunjungan ini belum dapat diubah karena Persetujuan Tindakan Medis belum ditandatangani dokter.';

    public const MESSAGE_FINISH_AWAITING_PATIENT = 'Pemeriksaan belum dapat diselesaikan karena Persetujuan Tindakan Medis belum ditandatangani. Lengkapi persetujuan terlebih dahulu.';

    public const MESSAGE_FINISH_AWAITING_DOCTOR = 'Pemeriksaan belum dapat diselesaikan karena Persetujuan Tindakan Medis belum ditandatangani dokter. Dokter yang menangani menandatanganinya dari Ruang Perawatan.';

    /*
     * DMS memo M4.4, PTM plan PR3 (rule 241 §6) — consent template v2026.2,
     * built INACTIVE. A new consent is signed on the candidate version only
     * while the risky flag is on AND its wording is approved (owner decision:
     * the final clause 8/9 wording is pending, so activation is blocked on it).
     */
    public const TEMPLATE_CANDIDATE_FLAG = 'rme.consent_template_v2026_2';

    public const WORDING_APPROVED = 'approved';

    public const TEMPLATE_REFUSAL_WORDING_NOT_APPROVED = 'wording_not_approved';

    public const TEMPLATE_REFUSAL_DRAFT_MARKER = 'draft_marker_present';

    public const TEMPLATE_REFUSAL_DEFINITION_INVALID = 'definition_invalid';

    public const MESSAGE_TEMPLATE_VERSION_CHANGED = 'Isi form persetujuan sudah berubah sejak halaman ini dibuka. Muat ulang halaman, bacakan kembali isi persetujuan kepada pasien, lalu ambil tanda tangannya.';

    /** The clause-8 message of every version without a separate clause 9 (unchanged). */
    public const MESSAGE_DOCUMENTATION_ANSWER = 'Persetujuan dokumentasi/publikasi harus dipilih YA atau TIDAK.';

    public function __construct(
        private readonly RmeVisitConsentRepositoryInterface $consents,
        private readonly RmeVisitConsentNumberGeneratorService $numbers,
        private readonly PrescriptionCanvasDecoder $canvas,
        private readonly ClinicalClock $clock,
        private readonly ClinicVisitRepositoryInterface $visits,
        private readonly AuditLogService $auditLog,
        private readonly DoctorIdentityResolver $doctorIdentity,
        private readonly FeatureFlagService $flags,
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
     * Rule 241 §2 — the gate predicate: the visit's live consent carries the
     * treating doctor's countersignature. Read from persisted evidence only
     * (AIR-RMC-10). Inside a write it is asked on the LOCKED visit row.
     */
    public function hasExecutedConsent(ClinicVisit $visit): bool
    {
        return $this->ptmStateFor($visit) === self::PTM_EXECUTED;
    }

    /**
     * Rule 241 §2–§3 — where the PTM of a visit stands: awaiting the patient
     * (no live consent), awaiting the doctor (live consent, no countersignature)
     * or executed. Null for no visit. Presentation reads it; the gates read
     * hasExecutedConsent().
     */
    public function ptmStateFor(?ClinicVisit $visit): ?string
    {
        if ($visit === null) {
            return null;
        }

        $consent = $this->validConsentFor($visit);

        if ($consent === null) {
            return self::PTM_AWAITING_PATIENT;
        }

        return $this->consents->doctorSignatureFor((int) $consent->getKey()) === null
            ? self::PTM_AWAITING_DOCTOR
            : self::PTM_EXECUTED;
    }

    /**
     * The PTM state of every IN-PROGRESS visit given, keyed by visit id, in one
     * query (the queue and Ruang Perawatan). Other statuses carry no state.
     *
     * @param  iterable<int, ClinicVisit>  $visits
     * @return array<int, string>
     */
    public function ptmStatesFor(iterable $visits): array
    {
        $ids = [];
        foreach ($visits as $visit) {
            if ($visit->status === ClinicVisit::STATUS_IN_PROGRESS) {
                $ids[] = (int) $visit->getKey();
            }
        }

        $consents = $this->consents->validForVisits($ids);
        $states = [];

        foreach ($ids as $id) {
            $consent = $consents->get($id);
            $states[$id] = $consent === null
                ? self::PTM_AWAITING_PATIENT
                : ($consent->doctorCountersignature === null ? self::PTM_AWAITING_DOCTOR : self::PTM_EXECUTED);
        }

        return $states;
    }

    /**
     * "Selesai Pemeriksaan" (rule 241 §2) — the finish transition needs the
     * EXECUTED consent. ClinicVisitService asks it before the lock and again on
     * the locked row.
     */
    public function assertExecutedConsentForFinish(ClinicVisit $visit): void
    {
        $this->assertExecutedConsent($visit, 'status', self::MESSAGE_FINISH_AWAITING_PATIENT, self::MESSAGE_FINISH_AWAITING_DOCTOR);
    }

    /**
     * Rule 241 §2 — performed treatments (rule 219) need the EXECUTED consent;
     * RmePerformedTreatmentService asks it again on the locked visit row.
     */
    public function assertExecutedConsentForPerformedTreatment(ClinicVisit $visit): void
    {
        $state = $this->ptmStateFor($visit);

        if ($state === self::PTM_EXECUTED) {
            return;
        }

        throw RmePerformedTreatmentRefusal::exception($state === self::PTM_AWAITING_DOCTOR
            ? RmePerformedTreatmentRefusal::DOCTOR_SIGNATURE_REQUIRED
            : RmePerformedTreatmentRefusal::CONSENT_REQUIRED);
    }

    private function assertExecutedConsent(ClinicVisit $visit, string $key, string $awaitingPatient, string $awaitingDoctor): void
    {
        $state = $this->ptmStateFor($visit);

        if ($state === self::PTM_EXECUTED) {
            return;
        }

        throw ValidationException::withMessages([
            $key => $state === self::PTM_AWAITING_DOCTOR ? $awaitingDoctor : $awaitingPatient,
        ]);
    }

    /**
     * @return Collection<int, RmeVisitConsent>
     */
    public function historyFor(ClinicVisit $visit): Collection
    {
        return $this->consents->historyForVisit($visit->id);
    }

    /**
     * The forms a NEW consent may be signed on, each in the version
     * templateForNewConsent() selects — what the capture page shows is what
     * sign() will snapshot.
     *
     * @return array<string, array<string, mixed>>
     */
    public function availableTemplates(): array
    {
        $templates = [];

        foreach (array_keys((array) config('rme_consent.templates', [])) as $code) {
            $templates[$code] = $this->templateForNewConsent((string) $code);
        }

        return $templates;
    }

    /**
     * The CURRENT definition of a form.
     *
     * @return array<string, mixed>
     */
    public function template(string $code): array
    {
        // Indexed directly: a code is a literal key, never a config path
        // (a dotted code must not reach inside a definition).
        $template = ((array) config('rme_consent.templates', []))[$code] ?? null;

        if (! is_array($template)) {
            throw ValidationException::withMessages([
                'template_code' => 'Form persetujuan tidak dikenal.',
            ]);
        }

        return $template;
    }

    /**
     * Rule 241 §6 — the version a NEW consent of this form is signed on.
     *
     * The candidate (v2026.2) is selected only when BOTH hold: the risky flag
     * `rme.consent_template_v2026_2` is on (FeatureFlagService, captured at
     * config build) AND the candidate is activatable — wording_status exactly
     * `approved`, no draft marker left in its text, a sound definition. Anything
     * else returns the current template, exactly as before. A refusal while the
     * flag is on is logged with the form code, the version and the reason only.
     * Historical consents never pass through here: they render their snapshot.
     *
     * @return array<string, mixed>
     */
    public function templateForNewConsent(string $code): array
    {
        $current = $this->template($code);
        $candidate = ((array) config('rme_consent.candidate_templates', []))[$code] ?? null;

        if (! is_array($candidate) || ! $this->flags->enabled(self::TEMPLATE_CANDIDATE_FLAG)) {
            return $current;
        }

        $refusal = $this->candidateActivationRefusal($code, $candidate, $current);

        if ($refusal !== null) {
            Log::warning('RME consent template v2026.2 activation refused while its flag is on; new consents keep the current template.', [
                'template_code' => $code,
                'candidate_version' => is_scalar($candidate['version'] ?? null) ? (string) $candidate['version'] : '',
                'reason' => $refusal,
            ]);

            return $current;
        }

        return $candidate;
    }

    /**
     * Why a candidate version may not be signed on (fail closed), or null.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $current
     */
    private function candidateActivationRefusal(string $code, array $candidate, array $current): ?string
    {
        // Exactly `approved`: the wording is a product decision (rule 100 §4).
        if (($candidate['wording_status'] ?? null) !== self::WORDING_APPROVED) {
            return self::TEMPLATE_REFUSAL_WORDING_NOT_APPROVED;
        }

        $markers = array_values(array_filter([(string) config('rme_consent.draft_marker', ''), '[DRAF']));
        $texts = array_merge(
            [$candidate['title'] ?? null, $candidate['consent_statement'] ?? null, $candidate['clauses_intro'] ?? null, $candidate['declaration'] ?? null, $candidate['location'] ?? null],
            is_array($candidate['clauses'] ?? null) ? array_values($candidate['clauses']) : [],
            is_array($candidate['signature_labels'] ?? null) ? array_values($candidate['signature_labels']) : [],
        );

        foreach ($texts as $text) {
            foreach ($markers as $marker) {
                if (is_string($text) && str_contains($text, $marker)) {
                    return self::TEMPLATE_REFUSAL_DRAFT_MARKER;
                }
            }
        }

        $clauses = $candidate['clauses'] ?? null;
        $documentation = $candidate['documentation_clause'] ?? null;
        $publication = $candidate['publication_clause'] ?? null;
        $version = $candidate['version'] ?? null;

        $sound = ($candidate['code'] ?? null) === $code
            && is_string($candidate['title'] ?? null)
            && is_string($version) && $version !== '' && $version !== (string) ($current['version'] ?? '')
            && is_array($clauses)
            && is_int($documentation) && is_int($publication) && $documentation !== $publication
            && is_string($clauses[$documentation] ?? null) && is_string($clauses[$publication] ?? null);

        return $sound ? null : self::TEMPLATE_REFUSAL_DEFINITION_INVALID;
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
     *   4. a valid signed consent exists for THAT SAME encounter and patient,
     *      and (rule 241 §2) it is EXECUTED: the treating doctor countersigned.
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
        // patient, since a consent carries its visit — and be EXECUTED: the
        // patient's signature and the treating doctor's (rule 241 §2).
        return $this->hasExecutedConsent($encounter) ? $encounter : null;
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

        // Rule 241 §2 — both signatures: the patient's and the doctor's.
        $this->assertExecutedConsent($encounter, 'consent', self::MESSAGE_RME_AWAITING_PATIENT, self::MESSAGE_RME_AWAITING_DOCTOR);

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

            // Re-decided on the locked row: a void or a countersignature
            // committed meanwhile is seen (rule 241 §2.3).
            $this->assertExecutedConsent($locked, 'consent', self::MESSAGE_RME_AWAITING_PATIENT, self::MESSAGE_RME_AWAITING_DOCTOR);

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
     * SUPERSEDED IN PART by rule 241 §2 (DMS memo M1.1–M1.3 PR2, owner decision
     * 2026-11-17): the owner made BOTH PTM signatures a condition of odontogram
     * WRITING, so condition 4 below (the executed consent, decided again on the
     * locked visit row inside a write) now applies here too. Pre-consent
     * charting ends; reading the chart and its history is unchanged. The text
     * that follows is the rule 134 rationale, kept as history.
     *
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
     *   3. the odontogram belongs to THAT encounter (else it is history);
     *   4. (rule 241 §2) the encounter's consent is EXECUTED — patient and
     *      treating doctor — re-decided under the visit lock inside a write.
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

        // 4. Rule 241 §2 (owner decision 2026-11-17) — the EXECUTED consent:
        // the patient's signature and the treating doctor's. Supersedes rule
        // 134's pre-consent charting for WRITING; reading stays open.
        $this->assertExecutedConsent($encounter, 'consent', self::MESSAGE_ODONTOGRAM_AWAITING_PATIENT, self::MESSAGE_ODONTOGRAM_AWAITING_DOCTOR);

        // Inside a write transaction the visit row is locked FIRST — before
        // OdontogramService locks or writes the chart — and the decision is
        // re-made on it (rule 223 §6), as every consent-gated RME write does.
        if (DB::transactionLevel() > 0) {
            $locked = $this->visits->lockForStatusDecision((int) $encounter->getKey());

            if ($locked === null || $locked->status !== ClinicVisit::STATUS_IN_PROGRESS) {
                throw ValidationException::withMessages([
                    'consent' => 'Odontogram hanya dapat diubah saat pasien sedang dalam pemeriksaan. Mulai pemeriksaan terlebih dahulu.',
                ]);
            }

            $this->assertExecutedConsent($locked, 'consent', self::MESSAGE_ODONTOGRAM_AWAITING_PATIENT, self::MESSAGE_ODONTOGRAM_AWAITING_DOCTOR);

            return $locked;
        }

        return $encounter;
    }

    /**
     * Rule 241 §3 — the odontogram page's honest state: when conditions 1–3 of
     * the odontogram authority hold for this actor and only the PTM is missing,
     * the PTM state (awaiting patient / doctor); null otherwise (allowed, or
     * refused for another reason). Presentation only; locks nothing.
     */
    public function odontogramPtmBlockFor(?ClinicVisit $visit, ?User $actor): ?string
    {
        if ($visit === null || ! $actor instanceof User) {
            return null;
        }

        $encounter = app(ActiveEncounterResolver::class)->currentFor($visit->patient_id);

        if ($encounter === null || (int) $encounter->id !== (int) $visit->id || ! $this->actorMayWorkOnEncounter($actor, $encounter)) {
            return null;
        }

        $state = $this->ptmStateFor($encounter);

        return $state === self::PTM_EXECUTED ? null : $state;
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
     * treatment itself, so it takes the FULL RME authority — including the EXECUTED
     * consent (patient + treating-doctor countersignature, rule 241 §2) — and, because a row belongs to ONE visit like an odontogram, the
     * exact chart-identity condition too:
     *
     *   1. the patient HAS a single current `in_progress` encounter;
     *   2. the actor may work on that encounter (branch + clinical scope);
     *   3. the visit being written IS that encounter (else it is history);
     *   4. an EXECUTED consent exists for THAT encounter (rule 241 §2).
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

        // Rule 241 §2 — the EXECUTED consent (both signatures); the refusal names
        // which signature is missing (review LOW-4).
        $this->assertExecutedConsentForPerformedTreatment($encounter);

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

        // Rule 241 §6 — the version is the server's choice (flag + approved
        // wording), never the request's.
        $template = $this->templateForNewConsent((string) ($data['template_code'] ?? ''));
        $publicationClause = $template['publication_clause'] ?? null;

        // The person must have read the version being signed. The capture page
        // posts the version it showed; a page rendered before the version in
        // force changed (or a crafted one) is refused, as is a clause-9 answer
        // filed on a version that has no clause 9.
        $shownVersion = $data['template_version'] ?? null;
        $answeredClause9 = array_key_exists('publication_consent', $data) && $data['publication_consent'] !== null && $data['publication_consent'] !== '';

        if (($shownVersion !== null && $shownVersion !== '' && (! is_scalar($shownVersion) || (string) $shownVersion !== (string) $template['version']))
            || ($publicationClause === null && $answeredClause9)) {
            throw ValidationException::withMessages([
                'template_code' => self::MESSAGE_TEMPLATE_VERSION_CHANGED,
            ]);
        }

        // Clause 8 must be an explicit choice. A missing value is not "no", and
        // it is certainly not "yes" — it is an incomplete consent. On v2026.2
        // clause 8 is clinical documentation only and clause 9 (publication)
        // is a second, equally explicit answer. Every missing or invalid answer
        // is reported in ONE refusal, each naming its own clause (review LOW-1;
        // the request only binds the values, so these messages reach HTTP).
        $requiredAnswers = ['documentation_consent' => $publicationClause === null
            ? self::MESSAGE_DOCUMENTATION_ANSWER
            : 'Persetujuan dokumentasi klinis (poin '.$template['documentation_clause'].') harus dipilih YA atau TIDAK.'];

        if ($publicationClause !== null) {
            $requiredAnswers['publication_consent'] = 'Persetujuan publikasi/promosi (poin '.$publicationClause.') harus dipilih YA atau TIDAK.';
        }

        $answers = $this->explicitAnswers($data, $requiredAnswers);
        $documentationConsent = $answers['documentation_consent'];
        $publicationConsent = $answers['publication_consent'] ?? null;

        // DMS memo M1.1–M1.3 PR2 (rule 241 §4; review LOW-1) — the capture takes
        // the patient's signature only. The legacy optional doctor drawing is
        // REFUSED (any value, before anything is decoded or written): the treating
        // doctor countersigns through countersign() instead. Historical consents
        // that carry one keep rendering it (rule 241 §1.6).
        if (array_key_exists('doctor_signature', $data) && $data['doctor_signature'] !== null && $data['doctor_signature'] !== '') {
            throw ValidationException::withMessages([
                'doctor_signature' => self::MESSAGE_DOCTOR_SIGNATURE_ON_CAPTURE,
            ]);
        }

        // The signature is the evidence. No signature, no consent.
        // DMS-RME-REVISION-PROGRAM-8D (rule 228 §10.3) — the signature carries
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
                $publicationConsent,
                $consenterSignature,
                $patient,
                $relationship,
                $isSelf,
                &$written,
                &$bodyDone,
            ): RmeVisitConsent {
                $consent = $this->signWithinTransaction($visit, $operator, $data, $template, $documentationConsent, $publicationConsent, $consenterSignature, $patient, $relationship, $isSelf, $written);
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
        ?bool $publicationConsent,
        string $consenterSignature,
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
            // Rule 241 §6 — clause 9 (v2026.2 only; null on a version without it).
            'publication_consent' => $publicationConsent,

            'consenter_signature_path' => $consenterPath,
            // Rule 241 §4 — no doctor drawing on a new capture (column kept for
            // historical consents, which keep rendering theirs).
            'doctor_signature_path' => null,
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
     * Since rule 241 §2 the odontogram authority does the same (AIR-RMC-14,
     * AIR-RMC-55), and every gate asks for the EXECUTED consent, so a void —
     * which leaves no live consent — closes all of them again.
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
    | Doctor countersignature (rule 241 §1)
    |--------------------------------------------------------------------------
    */

    /**
     * DMS memo M1.1–M1.3, PR1 (rule 241 §1; owner decisions 2026-11-17) — the
     * treating doctor countersigns the PTM the patient signed.
     *
     * Capture and storage only: no authoring gate reads it yet (the RME, the
     * odontogram and "Selesai Pemeriksaan" keep their patient-signature
     * conditions until PR2).
     *
     *  - The canvas is bounded and decoded BEFORE anything else is written.
     *  - The visit row is locked FIRST (`lockForStatusDecision`, rule 223 §6, the
     *    same lock in the same order as sign(), void() and transitionStatus()),
     *    then the consent is re-read under it, and every condition is decided on
     *    those two rows ({@see self::assertCountersignableUnderLock()}).
     *  - The object goes to ClinicalEvidenceStorage under a fresh random key; the
     *    row is inserted inside a savepoint, and the UNIQUE(consent) violation of a
     *    racing second countersignature becomes the same refusal. A failure while
     *    the body still runs removes only the object this call wrote (rule 224).
     *  - The consent row is never updated (AIR-RMC-12): the countersignature is its
     *    own insert-only row.
     *
     * @param  array<string, mixed>  $data
     */
    public function countersign(RmeVisitConsent $consent, User $actor, array $data): RmeVisitConsentDoctorSignature
    {
        // An account without the permission never has its payload parsed; the
        // whole decision is re-made under the lock below.
        if (! $actor->can(self::COUNTERSIGN_PERMISSION)) {
            throw new AuthorizationException(self::MESSAGE_COUNTERSIGN_PERMISSION);
        }

        // Rule 237 §5 / rule 228 §10.3 bounds, the same ones the patient's
        // signature carries, enforced here for a caller that skips the request.
        $signature = $this->canvas->decode(
            $data['doctor_signature'] ?? null,
            'doctor_signature',
            maxLength: PrescriptionCanvasDecoder::MAX_SIGNATURE_PAYLOAD_LENGTH,
        );

        if ($signature === null) {
            throw ValidationException::withMessages([
                'doctor_signature' => 'Tanda tangan dokter wajib diisi.',
            ]);
        }

        $written = [];
        $bodyDone = false;

        try {
            return DB::transaction(function () use ($consent, $actor, $signature, &$written, &$bodyDone): RmeVisitConsentDoctorSignature {
                $visit = $this->visits->lockForStatusDecision((int) $consent->clinic_visit_id);
                $consent->refresh();

                // INFO-5 — the doctor identity is read again under the lock, not
                // from the request's memo, so a deactivation or an unlink committed
                // after the policy ran is seen here.
                $this->doctorIdentity->forget((int) $actor->getKey());

                $doctorId = $this->assertCountersignableUnderLock($consent, $visit, $actor);

                $directory = trim((string) config('rme_consent.signature_directory', 'rme-consents'), '/');
                $path = $directory.'/'.$consent->consent_number.'/doctor-countersign-'.bin2hex(random_bytes(8)).'.png';
                $this->disk()->put($path, $signature);
                $written[] = $path;

                try {
                    // A savepoint, so a unique violation leaves the outer
                    // transaction usable for the refusal below.
                    $row = DB::transaction(fn (): RmeVisitConsentDoctorSignature => $this->consents->createDoctorSignature([
                        'rme_visit_consent_id' => (int) $consent->getKey(),
                        'doctor_id' => $doctorId,
                        'signed_by' => (int) $actor->getKey(),
                        'signature_path' => $path,
                        'signature_sha256' => hash('sha256', $signature),
                        'signed_at' => $this->clock->now(),
                    ]));
                } catch (UniqueConstraintViolationException) {
                    // The table's only unique key besides the id is the consent:
                    // a violation here means it already carries a countersignature.
                    throw ValidationException::withMessages([
                        'consent' => self::MESSAGE_COUNTERSIGN_DUPLICATE,
                    ]);
                }

                // Ids only — no name, no path, no hash in the audit payload.
                $this->auditLog->log(
                    self::AUDIT_TABLE,
                    (int) $consent->getKey(),
                    self::AUDIT_DOCTOR_SIGNED,
                    null,
                    [
                        'clinic_visit_id' => (int) $consent->clinic_visit_id,
                        'doctor_id' => $doctorId,
                        'doctor_signature_id' => (int) $row->getKey(),
                    ],
                    $actor,
                );

                $bodyDone = true;

                return $row;
            });
        } catch (Throwable $e) {
            if (! $bodyDone) {
                $this->discardUncommitted($written);
            }

            throw $e;
        }
    }

    /**
     * Presentation predicate: may this account countersign this consent right
     * now? Never the authorisation answer — countersign() decides again under
     * the visit lock. Read-only; locks nothing.
     */
    public function canCountersign(RmeVisitConsent $consent, ?User $actor): bool
    {
        return $this->countersignRefusal($consent, $actor) === null;
    }

    /**
     * Rule 241 §3 — why this account may not countersign right now (the
     * refusal message countersign() would give), or null when it may. Lets a
     * page name the one obstacle a doctor can act on — "you captured the
     * patient signature" (MESSAGE_COUNTERSIGN_SELF) needs a supervised void.
     */
    public function countersignRefusal(RmeVisitConsent $consent, ?User $actor): ?string
    {
        if (! $actor instanceof User) {
            return self::MESSAGE_COUNTERSIGN_PERMISSION;
        }

        try {
            $this->assertCountersignable($consent, $actor);
        } catch (AuthorizationException $refused) {
            return $refused->getMessage();
        } catch (ValidationException $refused) {
            return (string) collect($refused->errors())->flatten()->first();
        }

        return null;
    }

    /**
     * The same decision as countersign(), without a lock, for a page's
     * pre-check. Throws the refusal countersign() would throw.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function assertCountersignable(RmeVisitConsent $consent, User $actor): void
    {
        $visit = $consent->clinicVisit;

        $this->assertCountersignableUnderLock($consent, $visit, $actor);
    }

    /**
     * Ruang Perawatan (rule 241 §1) — for the given visits, the live consents
     * this account may countersign now, keyed by clinic_visit_id. Only the
     * account's own in-progress visits are considered, so the per-row decision
     * runs for a handful of rows at most.
     *
     * @param  iterable<int, ClinicVisit>  $visits
     * @return array<int, RmeVisitConsent>
     */
    public function countersignableConsentsFor(User $actor, iterable $visits): array
    {
        return array_map(
            fn (array $row): RmeVisitConsent => $row['consent'],
            array_filter($this->countersignOutlookFor($actor, $visits), fn (array $row): bool => $row['refusal'] === null),
        );
    }

    /**
     * Rule 241 §3 — for the account's OWN in-progress visits whose live consent
     * is not countersigned yet: the consent and why it may not be countersigned
     * now (null when it may). Keyed by clinic_visit_id.
     *
     * @param  iterable<int, ClinicVisit>  $visits
     * @return array<int, array{consent: RmeVisitConsent, refusal: ?string}>
     */
    public function countersignOutlookFor(User $actor, iterable $visits): array
    {
        if (! $actor->can(self::COUNTERSIGN_PERMISSION)) {
            return [];
        }

        $doctorId = $this->linkedActiveDoctorId($actor);

        if ($doctorId === null) {
            return [];
        }

        $candidates = [];
        foreach ($visits as $visit) {
            if ($visit->status === ClinicVisit::STATUS_IN_PROGRESS && (int) $visit->doctor_id === $doctorId) {
                $candidates[(int) $visit->getKey()] = $visit;
            }
        }

        $result = [];
        foreach ($this->consents->validForVisits(array_keys($candidates)) as $visitId => $consent) {
            if ($consent->doctorCountersignature !== null) {
                continue;
            }

            $consent->setRelation('clinicVisit', $candidates[(int) $visitId]);
            $result[(int) $visitId] = ['consent' => $consent, 'refusal' => $this->countersignRefusal($consent, $actor)];
        }

        return $result;
    }

    /**
     * Every countersign condition, decided on the rows given (the LOCKED visit
     * and the consent re-read under it, in countersign()). Returns the doctor id
     * that signs.
     *
     * Order (review LOW-2): every AUTHORIZATION guard — permission, scope,
     * encounter authority, doctor identity — runs before any STATE guard, and
     * refuses with an AuthorizationException (403) naming its own message. Only
     * an account that passed all of them can meet a state refusal (window, live
     * consent, maker ≠ checker, already countersigned: ValidationException on
     * `consent`), so the policy may treat a state refusal as "authorized, but
     * not now".
     *
     * @throws AuthorizationException|ValidationException
     */
    private function assertCountersignableUnderLock(RmeVisitConsent $consent, ?ClinicVisit $visit, User $actor): int
    {
        // CG-06 — the dedicated permission, re-asserted here (the route and the
        // policy ask first). Gate::before answers it for Super Admin; the
        // identity guards below are what bind Super Admin.
        if (! $actor->can(self::COUNTERSIGN_PERMISSION)) {
            throw new AuthorizationException(self::MESSAGE_COUNTERSIGN_PERMISSION);
        }

        // A consent without its visit has no encounter anyone may work on.
        if ($visit === null || (int) $consent->clinic_visit_id !== (int) $visit->getKey()) {
            throw new AuthorizationException(self::MESSAGE_COUNTERSIGN_ENCOUNTER);
        }

        // Branch and patient scope, as sign() and void() re-assert them.
        $this->assertOperatorWithinScope($actor, $visit);

        // The encounter authority every clinical writer uses: the front office
        // never, a clinical role, branch, doctor room and patient scope.
        if (! $this->actorMayWorkOnEncounter($actor, $visit)) {
            throw new AuthorizationException(self::MESSAGE_COUNTERSIGN_ENCOUNTER);
        }

        // Identity, never role (the PrescriptionAuthoringAuthority::maySign()
        // approach): the account linked to an ACTIVE doctor who is both the
        // locked visit's doctor and the consent's doctor. A Super Admin, Owner or
        // Supervisor RME that is not that doctor is refused; nothing in the
        // request names the doctor. One guard per condition.
        $doctor = $this->doctorIdentity->resolveForUser($actor);

        if ($doctor === null) {
            throw new AuthorizationException(self::MESSAGE_COUNTERSIGN_NOT_LINKED);
        }

        if (! $doctor->is_active) {
            throw new AuthorizationException(self::MESSAGE_COUNTERSIGN_DOCTOR_INACTIVE);
        }

        $doctorId = (int) $doctor->getKey();

        if ($doctorId !== (int) $visit->doctor_id) {
            throw new AuthorizationException(self::MESSAGE_COUNTERSIGN_NOT_VISIT_DOCTOR);
        }

        if ($doctorId !== (int) $consent->doctor_id) {
            throw new AuthorizationException(self::MESSAGE_COUNTERSIGN_NOT_CONSENT_DOCTOR);
        }

        if ($visit->status !== ClinicVisit::STATUS_IN_PROGRESS) {
            throw ValidationException::withMessages([
                'consent' => self::MESSAGE_COUNTERSIGN_WINDOW,
            ]);
        }

        // The consent must be THE live consent of this visit (one per visit, OD-5-8).
        if ($consent->isVoided() || $this->validConsentFor($visit)?->getKey() !== $consent->getKey()) {
            throw ValidationException::withMessages([
                'consent' => self::MESSAGE_COUNTERSIGN_VOIDED,
            ]);
        }

        // Maker ≠ checker (CG-07), identity-based so it binds Super Admin too:
        // the account that captured the patient's signature never countersigns.
        if ((int) $consent->signed_by === (int) $actor->getKey()) {
            throw ValidationException::withMessages([
                'consent' => self::MESSAGE_COUNTERSIGN_SELF,
            ]);
        }

        if ($this->consents->doctorSignatureFor((int) $consent->getKey()) !== null) {
            throw ValidationException::withMessages([
                'consent' => self::MESSAGE_COUNTERSIGN_DUPLICATE,
            ]);
        }

        return $doctorId;
    }

    /**
     * Rule 241 §1.6 — is a countersignature still POSSIBLE for this consent,
     * whoever the viewer is: the consent is the visit's live consent, the visit
     * is in progress, and none is recorded yet. Decides whether the document
     * says "Belum ditandatangani dokter"; a historical consent never does.
     */
    public function countersignaturePending(RmeVisitConsent $consent): bool
    {
        $visit = $consent->clinicVisit;

        return $visit !== null
            && $visit->status === ClinicVisit::STATUS_IN_PROGRESS
            && ! $consent->isVoided()
            && $this->validConsentFor($visit)?->getKey() === $consent->getKey()
            && $this->doctorCountersignatureFor($consent) === null;
    }

    private function linkedActiveDoctorId(User $actor): ?int
    {
        $doctor = $this->doctorIdentity->resolveForUser($actor);

        return $doctor !== null && $doctor->is_active ? (int) $doctor->getKey() : null;
    }

    /**
     * The countersignature of a consent, or null (its image stays private:
     * {@see self::signatureDataUri()}).
     */
    public function doctorCountersignatureFor(RmeVisitConsent $consent): ?RmeVisitConsentDoctorSignature
    {
        return $consent->relationLoaded('doctorCountersignature')
            ? $consent->doctorCountersignature
            : $this->consents->doctorSignatureFor((int) $consent->getKey());
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
        $snapshot = [
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

        // Rule 241 §6 — only a version with a separate clause 9 adds the key, so
        // a 2026.1 snapshot keeps exactly the shape it always had.
        if (isset($template['publication_clause'])) {
            $snapshot['publication_clause'] = $template['publication_clause'];
        }

        return $snapshot;
    }

    /**
     * Explicit YA/TIDAK answers: missing, null and blank are not "no" (and
     * certainly not "yes"), and anything that is not a boolean is refused.
     * Every answer that fails is reported together, in one refusal.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $required  field => its refusal message
     * @return array<string, bool>
     */
    private function explicitAnswers(array $data, array $required): array
    {
        $answers = [];
        $refused = [];

        foreach ($required as $key => $message) {
            $value = $data[$key] ?? null;
            $answer = $value === null || $value === ''
                ? null
                : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($answer === null) {
                $refused[$key] = $message;
            } else {
                $answers[$key] = $answer;
            }
        }

        if ($refused !== []) {
            throw ValidationException::withMessages($refused);
        }

        return $answers;
    }
}
