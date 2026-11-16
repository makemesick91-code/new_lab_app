<?php

namespace App\Modules\MedicalRecord\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicOversight\Support\ClinicalDayWindow;
use App\Modules\ClinicVisit\Interfaces\ClinicVisitRepositoryInterface;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ActiveEncounterResolver;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\MedicalRecord\Interfaces\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Support\Clinical\ClinicalClock;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MedicalRecordService
{
    /** OD-5-6 — the refusal for a new sheet named on any visit but the authorized encounter. */
    public const MESSAGE_SHEET_ONLY_ON_ENCOUNTER = 'Lembar RM baru hanya dapat dibuat pada kunjungan yang sedang diperiksa dengan persetujuan tindakan yang sah.';

    /** Rule 240 §1 (memo M3.3) — the refusal for any write on a final sheet. */
    public const MESSAGE_FINAL_SHEET_LOCKED = 'Rekam medis ini sudah final dan terkunci. Koreksi hanya dapat dilakukan melalui amandemen yang disetujui.';

    public function __construct(
        private readonly MedicalRecordRepositoryInterface $medicalRecords,
        private readonly BranchService $branches,
        // FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-01 — ClinicVisitService
        // is deliberately NOT a dependency of this service. Authoring or
        // finalizing a clinical document must never move the visit through the
        // workflow, so this class does not even hold the ability to do it.
        private readonly PatientRmWorkspaceResolver $workspace,
        private readonly DiagnosisRolloutService $diagnosisRollout,
        // FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-02 — every write path
        // in this service asserts the consent gate before it touches a record.
        private readonly RmeVisitConsentService $consents,
        private readonly ClinicalClock $clock,
        // R7-1 — the canonical visit row lock only; never a status transition.
        private readonly ClinicVisitRepositoryInterface $visits,
    ) {}

    /**
     * Whether a branch belongs to the operational "Cabang RME" set (active
     * RME-enabled branches). Replaces the single BranchContext/MAIN fallback so
     * the doctor RME workflow works for any RME-branch visit (Sprint 23 Phase 23.10).
     */
    private function isActiveRmeBranch(?int $branchId): bool
    {
        return $branchId !== null && in_array($branchId, $this->branches->rmeEnabledIds(), true);
    }

    /**
     * AUDIT-RME-CLINIC-VISIT-BRANCH-AUTHORITY-1 — the caller passes the branch
     * set it is allowed to aggregate over (ClinicVisitService::scopedBranchIdsFor()
     * for the visit list and the RME dashboard). These counts used to hard-wire
     * every active RME branch, so a context-bound operator's widgets mixed its
     * own branch's visit counts with the whole estate's medical-record counts.
     *
     * @param  array<int, int>  $branchIds
     */
    public function draftCount(array $branchIds): int
    {
        return $this->medicalRecords->countByBranchesStatus(
            $branchIds,
            MedicalRecord::STATUS_DRAFT,
        );
    }

    /**
     * @param  array<int, int>  $branchIds
     */
    public function finalizedTodayCount(array $branchIds): int
    {
        // SECURITY-F4A: the clinic day as UTC instants — finalized_at is a UTC
        // timestamp, so a date comparison would count the UTC day.
        $today = ClinicalDayWindow::today($this->clock);

        return $this->medicalRecords->countFinalizedInRangeByBranches(
            $branchIds,
            $today->startUtcString(),
            $today->endUtcString(),
        );
    }

    public function createDraft(ClinicVisit $clinicVisit, ?int $recordedBy = null, array $data = []): MedicalRecord
    {
        return $this->createDraftOn($clinicVisit, $recordedBy, $data, adoptExisting: false);
    }

    /**
     * DMS-RME-REVISION-PROGRAM-7 / R6-1 — one draft per visit, concurrency-safe.
     *
     * `$adoptExisting` states what the CALLER means by a draft that already
     * exists, so a concurrent request gets exactly the answer a sequential one
     * would have:
     *
     *  - false (createDraft — "add a sheet to this visit"): a second sheet for
     *    the same visit is refused with the existing duplicate message.
     *  - true (getOrCreateCanonicalMedicalRecord — "the patient's book"): the
     *    book a concurrent first save just created IS this patient's book, so
     *    the page is written into it.
     *
     * The existence check below locks nothing when there is no row, so two
     * concurrent first saves both used to find "none". The loser then either
     * found the winner's draft at this check (and was refused, losing the
     * doctor's page) or raced UNIQUE(clinic_visit_id) on the insert and answered
     * HTTP 500. The insert now runs in its own savepoint: Laravel rolls back to
     * it BEFORE rethrowing, so the transaction is usable when the catch runs and
     * the committed winner can be read. This adds no lock, so the lock order of
     * rule 223 §1 / rule 224 is unchanged.
     */
    private function createDraftOn(ClinicVisit $clinicVisit, ?int $recordedBy, array $data, bool $adoptExisting, ?User $actor = null): MedicalRecord
    {
        return DB::transaction(function () use ($clinicVisit, $recordedBy, $data, $adoptExisting, $actor) {
            if (! $this->isActiveRmeBranch($clinicVisit->branch_id)) {
                throw ValidationException::withMessages([
                    'clinic_visit_id' => 'Kunjungan tidak berada di cabang RME aktif.',
                ]);
            }

            $existing = $this->medicalRecords->findByVisitId($clinicVisit->id);

            if ($existing !== null) {
                if ($adoptExisting) {
                    return $existing;
                }

                throw $this->draftAlreadyExists();
            }

            /*
             * FIX-02 / CORRECTIVE-02 — POSITIVE authority.
             *
             * A sheet is created only when the patient HAS an authorized, consented
             * examination in progress. Which visit the caller names is client input
             * and is never the authority; the encounter is resolved from server
             * state. No active encounter => no write, full stop.
             */
            $encounter = $this->consents->assertRmeAuthoringAllowedForPatient($clinicVisit->patient_id, $actor);

            /*
             * DMS-RME-COMPREHENSIVE-CLOSURE-1 (OD-5-6, owner decision 2026-11-16:
             * "current encounter only"). Once the patient has an RM book, a NEW
             * sheet ("Tambah Lembar RM") is created only on the authorized,
             * consented encounter itself — never on another visit of the same
             * patient named in the URL, which the visit page never offered and the
             * server used to accept. The patient's first sheet still starts on the
             * canonical visit the controller derives (Sprint 64.0.2), and the
             * canonical-book path ($adoptExisting) is not a new sheet.
             */
            if (! $adoptExisting
                && (int) $clinicVisit->getKey() !== (int) $encounter->getKey()
                && $this->workspace->sheetsForPatient((int) $clinicVisit->patient_id)->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'clinic_visit_id' => self::MESSAGE_SHEET_ONLY_ON_ENCOUNTER,
                ]);
            }

            $safe = array_intersect_key($data, array_flip([
                'subjective', 'objective', 'assessment', 'plan', 'notes',
            ]));

            // Sprint 64.0 — stamp the patient-workspace audit columns at create
            // time. canonical = the true first visit (existing anchor or self);
            // source = this visit (one sheet per visit); sheet_number = next
            // per-patient ordinal.
            $sourceVisitId = isset($data['source_visit_id'])
                ? (int) $data['source_visit_id']
                : $clinicVisit->id;

            $attributes = array_merge($safe, [
                'clinic_visit_id' => $clinicVisit->id,
                'canonical_visit_id' => $this->workspace->pickCanonicalVisitId($clinicVisit),
                'source_visit_id' => $sourceVisitId,
                'sheet_number' => $this->workspace->nextSheetNumber($clinicVisit->patient_id),
                'branch_id' => $clinicVisit->branch_id,
                'patient_id' => $clinicVisit->patient_id,
                'doctor_id' => $clinicVisit->doctor_id,
                'status' => MedicalRecord::STATUS_DRAFT,
                'recorded_by' => $recordedBy,
            ]);

            try {
                return DB::transaction(fn (): MedicalRecord => $this->medicalRecords->create($attributes));
            } catch (UniqueConstraintViolationException $lostRace) {
                // Only the one-draft-per-visit race is answered here, and only
                // when the winner's row is really there; anything else surfaces.
                $winner = $this->medicalRecords->findByVisitId($clinicVisit->id) ?? throw $lostRace;

                if ($adoptExisting) {
                    return $winner;
                }

                throw $this->draftAlreadyExists();
            }
        });
    }

    private function draftAlreadyExists(): ValidationException
    {
        return ValidationException::withMessages([
            'clinic_visit_id' => 'Rekam medis sudah ada untuk kunjungan ini.',
        ]);
    }

    public function finalize(MedicalRecord $medicalRecord): MedicalRecord
    {
        return DB::transaction(function () use ($medicalRecord) {
            if (! $this->isActiveRmeBranch($medicalRecord->branch_id)) {
                throw ValidationException::withMessages([
                    'medical_record_id' => 'Rekam medis tidak berada di cabang RME aktif.',
                ]);
            }

            if ($medicalRecord->status === MedicalRecord::STATUS_FINAL) {
                return $medicalRecord;
            }

            // FIX-02 / CORRECTIVE-02 — finalization is a current-RME write, so it
            // needs the same authorized, consented, in-progress encounter. Inside
            // this transaction it locks the encounter's visit row first.
            $this->consents->assertRmeAuthoringAllowedForPatient($medicalRecord->patient_id);

            // Rule 240 §1 (memo M3.3) — re-decided on the sheet row, locked after
            // the visit (rule 223 §1 item 6): a finalize that committed after the caller
            // loaded its copy is returned as it is, never rewritten.
            $locked = $this->lockSheet($medicalRecord);

            if ($locked->status === MedicalRecord::STATUS_FINAL) {
                $medicalRecord->refresh();

                return $locked;
            }

            if (! $this->hasRequiredHandwriting($locked)) {
                throw ValidationException::withMessages([
                    'handwriting' => 'RME belum dapat difinalkan karena catatan tulis tangan dokter belum tersedia.',
                ]);
            }

            // SATUSEHAT-4B — branch-scoped structured diagnosis rollout gate.
            // Blocks ONLY on an explicitly configured pilot_enforced branch
            // without an active primary diagnosis or a reasoned override;
            // informational/warning branches are never blocked here.
            $this->diagnosisRollout->assertFinalizationAllowed($locked);

            $finalized = $this->medicalRecords->update($locked, [
                'status' => MedicalRecord::STATUS_FINAL,
                'finalized_at' => now(),
                'finalized_by' => Auth::id(),
            ]);
            $medicalRecord->refresh();

            // Sprint 64.0 — lazily backfill workspace audit columns (no-op once
            // stamped).
            $this->workspace->stampWorkspaceFields($finalized);

            /*
             * FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-01 — finalizing a
             * medical record NO LONGER ends the examination.
             *
             * This method used to transition an in_progress visit to
             * cashier_pending, so completing a clinical DOCUMENT silently
             * completed the doctor's EXAMINATION and handed the patient to the
             * cashier. Those are two different facts. A doctor who finalizes one
             * RM sheet may still have an odontogram to record, a second sheet to
             * write, or a treatment decision to make — and "final" closes one
             * sheet, not the examination (since rule 240 a final sheet is locked
             * and corrected only by a supervised amendment).
             *
             * Document finalization is now purely a clinical-document state.
             * The workflow transition in_progress -> cashier_pending happens ONLY
             * when an authorized doctor explicitly performs "Selesai Pemeriksaan"
             * (ClinicVisitController::transitionStatus, gated by
             * ClinicVisitPolicy::completeExamination / complete_rme_examination).
             *
             * Nothing here may re-introduce an automatic transition: not this
             * method, not odontogram saving, not handwriting completion, and not
             * any combination of them.
             */
            return $finalized;
        });
    }

    /**
     * Rule 240 §1 (memo M3.3, owner decision 2026-11-17) — only a DRAFT sheet is
     * edited. A final sheet is locked and corrected only through a supervised
     * amendment; this supersedes the Sprint 59 "revisable after finalization"
     * behaviour. The lock reads `status` alone, so a sheet finalized before this
     * rule is locked too (no backfill). Decided here, on the locked row, so it
     * binds Super Admin, whom Gate::before lets past MedicalRecordPolicy::editSheet.
     *
     * Only keys actually present in the submitted payload are written, so a
     * partial save (e.g. only `notes`) never blanks out previously stored
     * fields the doctor did not touch.
     */
    public function updateDraft(MedicalRecord $medicalRecord, array $data): MedicalRecord
    {
        return DB::transaction(function () use ($medicalRecord, $data) {
            if (! $this->isActiveRmeBranch($medicalRecord->branch_id)) {
                throw ValidationException::withMessages([
                    'medical_record_id' => 'Rekam medis tidak berada di cabang RME aktif.',
                ]);
            }

            // FIX-02 / CORRECTIVE-02 — editing is a current-RME write. Inside this
            // transaction it locks the encounter's visit row first; rule 240 §1 —
            // then the sheet row (rule 223 §1 item 6), a final sheet answering with its
            // lock before a missing PTM (§1.8).
            $locked = $this->assertSheetAuthoringAllowed($medicalRecord);

            $safe = array_intersect_key($data, array_flip([
                'subjective', 'objective', 'assessment', 'plan', 'notes',
            ]));

            $updated = $this->medicalRecords->update($locked, $safe);
            $medicalRecord->refresh();

            // Sprint 64.0 — lazily backfill workspace audit columns for legacy
            // sheets created before this sprint (no-op once stamped).
            $this->workspace->stampWorkspaceFields($updated);

            return $updated;
        });
    }

    // --- Phase 1.8 alignment helpers (used by Phase 1.9 finalization enforcement) ---

    /**
     * Sprint 64.0.2 — ensure the canonical visit owns the patient's handwriting
     * RM book. Creates a draft on the canonical visit when missing; never creates
     * a duplicate on a later visit.
     */
    public function getOrCreateCanonicalMedicalRecord(
        ClinicVisit $contextVisit,
        ?int $recordedBy = null,
        ?int $sourceVisitId = null,
    ): MedicalRecord {
        $patientId = (int) $contextVisit->patient_id;

        /*
         * FIX-02 / CORRECTIVE-02 — the storage target is irrelevant to authority.
         *
         * Sprint 64.0.2 writes the patient's handwriting book onto the CANONICAL
         * visit, usually an older terminal one. Authority therefore cannot come
         * from $contextVisit or $canonicalVisit — both are reachable from the
         * request — but from whether this PATIENT has an authorized, consented
         * examination in progress right now.
         */
        $this->consents->assertRmeAuthoringAllowedForPatient($patientId);

        return DB::transaction(function () use ($contextVisit, $patientId, $recordedBy, $sourceVisitId): MedicalRecord {
            /*
             * DMS-RME-COMPREHENSIVE-CLOSURE-1 (R7-1) — one book per patient. The
             * canonical visit is decided on its LOCKED row (rule 223 §6: the visit
             * first), the lock the cancel path takes too, so a cancel of that visit
             * and a first save serialise, and two first saves lock the same row.
             * The book is then chosen by the resolver's selector against that
             * locked visit: the patient's self-anchored sheet first (it may sit on a
             * visit cancelled since, or behind an earlier merged visit), else the
             * record on the locked visit.
             */
            $canonicalVisit = $this->lockCanonicalVisit($patientId) ?? $contextVisit;

            $existing = $this->workspace->bookOn($patientId, $canonicalVisit, $this->recordOnVisit(...));

            if ($existing !== null) {
                return $existing;
            }

            // R6-1 — a concurrent first save may create the book between the lookup
            // above and the insert; that book is this patient's book, so adopt it.
            return $this->createDraftOn($canonicalVisit, $recordedBy, array_filter([
                'source_visit_id' => $sourceVisitId ?? $contextVisit->id,
            ], fn ($v) => $v !== null), adoptExisting: true);
        });
    }

    /**
     * DMS-RME-COMPREHENSIVE-CLOSURE-1 (R7-1-FS, owner decision 2026-11-17) — the
     * patient's FIRST sheet ("Buat Rekam Medis" with no sheet yet) starts the
     * book under the same canonical-visit lock and with the same selector as the
     * first handwriting save, so a cancel of the canonical visit and a concurrent
     * first save converge on one book instead of one on each visit.
     *
     * The authoring gate runs first, as on the handwriting path, so both paths
     * take the visit rows in the same order. The service itself re-asserts the
     * `create` policy for $actor on the LOCKED target (CG-06), which may differ
     * from the visit the caller saw before the lock; no caller can skip it. An
     * existing book is adopted (returned with false); otherwise the sheet is
     * created on the locked canonical visit (true).
     *
     * @return array{0: MedicalRecord, 1: bool}
     *
     * @throws AuthorizationException when the policy refuses the locked target
     */
    public function startBook(ClinicVisit $contextVisit, User $actor, array $data): array
    {
        $patientId = (int) $contextVisit->patient_id;

        return DB::transaction(function () use ($contextVisit, $patientId, $actor, $data): array {
            $this->consents->assertRmeAuthoringAllowedForPatient($patientId, $actor);

            $target = $this->lockCanonicalVisit($patientId) ?? $contextVisit;

            Gate::forUser($actor)->authorize('create', [MedicalRecord::class, $target]);

            $book = $this->workspace->bookOn($patientId, $target, $this->recordOnVisit(...));

            if ($book !== null) {
                return [$book, false];
            }

            $sheet = $this->createDraftOn($target, (int) $actor->getKey(), $data, adoptExisting: true, actor: $actor);

            return [$sheet, $sheet->wasRecentlyCreated];
        });
    }

    /** R7-1 — step 2 of the book selector, read through this service's repository. */
    private function recordOnVisit(int $clinicVisitId): ?MedicalRecord
    {
        return $this->medicalRecords->findByVisitId($clinicVisitId);
    }

    /**
     * DMS-RME-COMPREHENSIVE-CLOSURE-1 (R7-1) — the patient's canonical visit,
     * locked FOR NO KEY UPDATE and re-checked on the locked row. A cancel (or a
     * merge re-pointing the visit) that committed between the lookup and the
     * lock moves the canonical visit, so the next candidate is locked instead.
     * Each candidate is tried once, so the loop ends.
     */
    private function lockCanonicalVisit(int $patientId): ?ClinicVisit
    {
        $tried = [];

        while (($candidate = $this->workspace->resolveCanonicalWorkspaceVisit($patientId)) !== null
            && ! in_array((int) $candidate->getKey(), $tried, true)) {
            $tried[] = (int) $candidate->getKey();
            $locked = $this->visits->lockForStatusDecision((int) $candidate->getKey());

            if ($locked !== null
                && (int) $locked->patient_id === $patientId
                && $locked->status !== ClinicVisit::STATUS_CANCELLED) {
                return $locked;
            }
        }

        return null;
    }

    /**
     * Rule 240 §1 — the one refusal every write on a final sheet throws: the sheet
     * service, the structured-diagnosis writes and the emergency override. Pass
     * the row the caller LOCKED inside its transaction, never a copy it loaded
     * before, so a finalize that committed meanwhile is seen.
     *
     * @throws ValidationException
     */
    public static function assertSheetUnlocked(MedicalRecord $lockedSheet): void
    {
        if ($lockedSheet->status === MedicalRecord::STATUS_FINAL) {
            throw ValidationException::withMessages([
                'medical_record' => self::MESSAGE_FINAL_SHEET_LOCKED,
            ]);
        }
    }

    /**
     * Rule 240 §1.8 — the refusal order of a write on an EXISTING sheet (notes,
     * structured diagnoses, the override): the RME authoring authority (rule 241
     * §2) and the final lock together, shared by the three sheet services.
     *
     * The authority decides the encounter and the actor first and the EXECUTED
     * consent last. A FINAL sheet answers with its lock BEFORE that consent:
     * signing the PTM can never unlock it, so a missing signature is never the
     * reason given for it. When the authority's ONLY refusal is the PTM (its two
     * awaiting messages), the encounter's visit row is locked and then the sheet
     * row through $lockSheet (rule 223 §1 item 6), and a final sheet is refused
     * with the lock; otherwise the refusal stands and nothing is written.
     *
     * Inside the write transaction it returns the sheet row locked after the
     * visit row and found draft. Outside one (a service's pre-check before
     * validation) it locks nothing and reads the sheet fresh; the transaction
     * decides again.
     *
     * @param  (Closure(): MedicalRecord)|null  $lockSheet  the caller's own sheet-row lock (default FOR NO KEY UPDATE)
     *
     * @throws ValidationException
     */
    public function assertSheetAuthoringAllowed(MedicalRecord $sheet, ?User $actor = null, ?Closure $lockSheet = null): MedicalRecord
    {
        $inWrite = DB::transactionLevel() > 0;
        $current = function () use ($sheet, $lockSheet, $inWrite): MedicalRecord {
            if (! $inWrite) {
                return $sheet->fresh()
                    ?? throw (new ModelNotFoundException)->setModel(MedicalRecord::class, [$sheet->getKey()]);
            }

            return $lockSheet !== null ? $lockSheet() : $this->lockSheet($sheet);
        };

        try {
            $this->consents->assertRmeAuthoringAllowedForPatient($sheet->patient_id, $actor);
        } catch (ValidationException $refusal) {
            $onlyThePtm = in_array($refusal->errors()['consent'][0] ?? null, [
                RmeVisitConsentService::MESSAGE_RME_AWAITING_PATIENT,
                RmeVisitConsentService::MESSAGE_RME_AWAITING_DOCTOR,
            ], true);

            if ($onlyThePtm) {
                $encounter = $inWrite ? app(ActiveEncounterResolver::class)->currentFor($sheet->patient_id) : null;

                if ($encounter !== null) {
                    $this->visits->lockForStatusDecision((int) $encounter->getKey());
                }

                self::assertSheetUnlocked($current());
            }

            throw $refusal;
        }

        $row = $current();
        self::assertSheetUnlocked($row);

        return $row;
    }

    /**
     * The sheet row under FOR NO KEY UPDATE — the record lock the handwriting save
     * takes too (MedicalRecordRepositoryInterface::lockForHandwriting), so an edit,
     * a finalize and a page save of one record serialise. Callers take it AFTER
     * the encounter's visit row (rule 223 §1 item 6).
     */
    private function lockSheet(MedicalRecord $medicalRecord): MedicalRecord
    {
        return $this->medicalRecords->lockForHandwriting((int) $medicalRecord->getKey())
            ?? throw (new ModelNotFoundException)->setModel(MedicalRecord::class, [$medicalRecord->getKey()]);
    }

    public function requiresHandwritingBeforeFinal(): bool
    {
        return true;
    }

    /**
     * Sprint 64.0.2 — one patient = one handwriting RM book on the canonical
     * visit. Finalize may proceed when the active sheet OR the canonical book
     * has at least one saved handwriting page.
     */
    public function hasHandwritingForVisitOrCanonicalBook(MedicalRecord $medicalRecord): bool
    {
        if ($this->recordHasHandwriting($medicalRecord)) {
            return true;
        }

        $canonical = $this->workspace->canonicalMedicalRecord($medicalRecord->patient_id);

        if ($canonical === null || $canonical->is($medicalRecord)) {
            return false;
        }

        return $this->recordHasHandwriting($canonical);
    }

    public function hasRequiredHandwriting(MedicalRecord $medicalRecord): bool
    {
        return $this->hasHandwritingForVisitOrCanonicalBook($medicalRecord);
    }

    private function recordHasHandwriting(MedicalRecord $medicalRecord): bool
    {
        if ($medicalRecord->hasHandwriting()) {
            return true;
        }

        return $medicalRecord->handwritingPages()->exists();
    }

    public function canFinalizeRme(MedicalRecord $medicalRecord): bool
    {
        if ($medicalRecord->status === MedicalRecord::STATUS_FINAL) {
            return false;
        }

        return $this->hasRequiredHandwriting($medicalRecord);
    }
}
