<?php

namespace App\Modules\Odontogram\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Interfaces\ClinicVisitRepositoryInterface;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\Odontogram\Interfaces\OdontogramRepositoryInterface;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\RME\Services\DoctorPatientScopeService;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OdontogramService
{
    /** Rule 240 §1 (memo M3.3) — the refusal for any write on a finalized chart. */
    public const MESSAGE_FINALIZED_CHART_LOCKED = 'Odontogram ini sudah final dan terkunci. Koreksi hanya dapat dilakukan melalui amandemen yang disetujui.';

    public function __construct(
        private readonly OdontogramRepositoryInterface $odontograms,
        private readonly BranchService $branches,
        // FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-04 — the patient
        // odontogram history is the first patient-wide read in this module, so it
        // must carry the same doctor clinical scope every single-record ability
        // already applies.
        private readonly DoctorPatientScopeService $doctorScope,
        private readonly OdontogramPrintFormatter $printFormatter,
        // The canonical working-branch authority. The doctor scope below narrows
        // by treating relationship but is a NO-OP for Kasir / Admin Klinik /
        // Perawat, so without this a context-bound operator would read a patient's
        // odontogram findings from every branch in the RME estate.
        private readonly RmeWorkingBranchScope $workingBranchScope,
        // Rule 134 — the active odontogram's single authoring authority is
        // assertOdontogramAuthoringAllowed(): a live in_progress encounter, an
        // actor who may work on it, and the chart belonging to it. Rule 241 §2
        // (memo M1.1–M1.3 PR2, owner decision 2026-11-17) added the EXECUTED
        // consent (patient + treating doctor), decided on the visit row it locks
        // FIRST inside the write transaction — before this service locks or
        // writes the chart. Pre-consent charting ended; reading is unchanged.
        private readonly RmeVisitConsentService $consents,
        // Rule 240 §1 / rule 223 §1 item 6 — the visit row is locked before the chart row.
        private readonly ClinicVisitRepositoryInterface $visits,
    ) {}

    /**
     * FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-04 — the patient's PREVIOUS
     * odontograms, as a read-only view model.
     *
     * READ-ONLY BY CONSTRUCTION. This returns presentation rows, not models bound
     * to a form: the history card renders them and offers no edit, save, finalize
     * or delete control, and there is no mutation endpoint that accepts a history
     * row. A previous visit's chart is read-only (rule 109 §4), and a finalized
     * chart is corrected only through a supervised amendment (rule 240).
     *
     * Consent is deliberately NOT consulted. Reading clinical history is never
     * gated: the doctor deciding today's treatment must be able to see what was
     * charted before, and an unsigned consent on the current visit says nothing
     * about a previous encounter.
     *
     * Scoping, all server-side:
     *   - active RME branch set (MAIN and non-RME branches excluded)
     *   - the doctor's clinical patient scope, identical to the single-record abilities
     *   - the CURRENT visit excluded, so the chart being edited is never also
     *     listed as its own history
     *   - odontograms with no recorded teeth excluded. Since FIX-01 the page
     *     no longer auto-creates such a draft; the exclusion stays for the
     *     empty rows already present in production
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function patientHistoryForVisit(ClinicVisit $currentVisit, User $user, int $limit = 50): Collection
    {
        $patientId = $currentVisit->patient_id;

        if ($patientId === null) {
            return collect();
        }

        /*
         * Branch scope comes from the canonical working-branch authority
         * intersected with the active RME estate — not from rmeEnabledIds() alone.
         *
         * Found by adversarial review: this is the module's first patient-wide
         * (rather than single-visit) read, and every single-record ability already
         * goes through RmeWorkingBranchScope::allows(). Using the whole RME estate
         * here would let a Kasir / Admin Klinik / Perawat who is pinned to one
         * branch read a patient's clinical odontogram findings from every other
         * branch, in bulk — a boundary the per-record abilities hold.
         *
         * An empty intersection fails CLOSED in the repository.
         */
        $branchIds = array_values(array_intersect(
            $this->workingBranchScope->branchIdsFor($user),
            $this->branches->rmeEnabledIds(),
        ));

        $records = $this->odontograms->patientHistoryForBranches(
            $branchIds,
            (int) $patientId,
            $currentVisit->id,
            fn ($query) => $this->doctorScope->applyVisitScopeForUser($user, $query),
            $limit,
        );

        /*
         * An odontogram counts as history only once a tooth has actually been
         * recorded: an empty draft is not a clinical finding.
         *
         * POST-RME-ODONTOGRAM-STABILIZATION-1 / FIX-01 — opening the page no
         * longer creates such a draft. This filter is retained as
         * belt-and-braces for the empty rows that already exist in production,
         * which are not deleted by that fix.
         *
         * LEGACY-ODONTOGRAM-NATIVE-REFERENCE-CUTOFF-1 moved the predicate onto
         * the model so the legacy odontogram archive's native-reference cutoff
         * applies the SAME test. It was private here, so the cutoff could not
         * reach it and counted bare row existence instead. Behaviour here is
         * unchanged — only the predicate's home moved.
         */
        return $records
            ->filter(fn (Odontogram $o) => $o->hasRecordedTeeth())
            ->map(fn (Odontogram $o) => $this->toHistoryRow($o))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function toHistoryRow(Odontogram $odontogram): array
    {
        $visit = $odontogram->clinicVisit;

        return [
            'source' => 'native',
            'source_label' => 'Native',
            'odontogram_id' => $odontogram->id,
            'visit_id' => $visit?->id,
            'visit_number' => $visit?->visit_number,
            // The clinical date of the encounter, never the row's timestamps.
            'date' => $visit?->visit_date,
            'branch_code' => $visit?->branch?->code,
            'branch_name' => $visit?->branch?->name,
            // Never fabricated: a missing doctor stays null and the view shows an em dash.
            'doctor_name' => $visit?->doctor?->name,
            'status' => $odontogram->status,
            'dmft' => $odontogram->dmftCounts(),
            'structured' => $this->printFormatter->format($odontogram),
            'view_url' => null,
        ];
    }

    /**
     * Whether a branch belongs to the operational "Cabang RME" set (active
     * RME-enabled branches). Replaces the single BranchContext/MAIN fallback so
     * the doctor odontogram workflow works for any RME-branch visit in the pilot
     * (Sprint 23 Phase 23.10).
     */
    private function isActiveRmeBranch(?int $branchId): bool
    {
        return $branchId !== null && in_array($branchId, $this->branches->rmeEnabledIds(), true);
    }

    /**
     * POST-RME-ODONTOGRAM-STABILIZATION-1 / FIX-01 — the visit's saved chart,
     * or NULL when nothing has been charted yet.
     *
     * A PURE READ. It is the only resolver the show page may use, and it exists
     * so that opening the odontogram can never leave a clinical row behind.
     */
    public function findForVisit(ClinicVisit $clinicVisit): ?Odontogram
    {
        return $this->odontograms->findByClinicVisit($clinicVisit->id);
    }

    /**
     * FIX-01 — the chart to RENDER for this visit.
     *
     * Returns the saved odontogram when one exists, otherwise an UNSAVED,
     * in-memory instance carrying the visit's identity. Nothing is written.
     *
     * Why an unsaved model rather than null: every consumer of a chart —
     * {@see OdontogramPrintFormatter::format()}, `dmftCounts()`, the show view —
     * is typed against a non-nullable Odontogram. Handing them a transient
     * instance keeps all of them unchanged while removing the write, instead of
     * pushing null-handling into a formatter, a view and a print template.
     *
     * The caller MUST branch on `$odontogram->exists` before generating any URL
     * that embeds the model key: an unsaved model has no route key.
     */
    public function draftForVisit(ClinicVisit $clinicVisit): Odontogram
    {
        $existing = $this->findForVisit($clinicVisit);

        if ($existing !== null) {
            return $existing;
        }

        $draft = new Odontogram([
            'clinic_visit_id' => $clinicVisit->id,
            'branch_id' => $clinicVisit->branch_id,
            'medical_record_id' => $clinicVisit->medicalRecord?->id,
            'status' => Odontogram::STATUS_DRAFT,
            'summary_notes' => null,
            'additional_conditions' => null,
            'tooth_map_payload' => null,
        ]);

        // So the policy and the view can reach the owning visit without the
        // relation firing a query against a row that does not exist.
        $draft->setRelation('clinicVisit', $clinicVisit);

        return $draft;
    }

    /**
     * FIX-01 — the FIRST clinical write for a visit that has no chart yet.
     *
     * This is the create-on-mutation entry point that replaces creation-on-view.
     * The authoring authority (rule 134) is asserted BEFORE the row is created,
     * not after: otherwise a refused submission would still persist an empty
     * chart and then fail,
     * reintroducing through the write path exactly the side effect this fix
     * removes from the read path.
     *
     * Idempotent, and safe against a concurrent first save. The create-or-update
     * decision is taken under a row lock rather than as a bare check-then-act:
     * creation moved from the page GET to the Save button, so two saves racing
     * on the same uncharted visit is now a realistic clinical scenario (a
     * double-click, or a doctor and a nurse on one live encounter). `trx_odontograms`
     * holds a UNIQUE on clinic_visit_id, so without the lock the loser would
     * hit a constraint violation and lose its charted teeth to a rolled-back
     * transaction. With it, the second save simply updates the first one's row.
     */
    public function saveForVisit(ClinicVisit $clinicVisit, array $payload, User $user): Odontogram
    {
        return DB::transaction(function () use ($clinicVisit, $payload, $user) {
            if (! $this->isActiveRmeBranch($clinicVisit->branch_id)) {
                throw ValidationException::withMessages([
                    'clinic_visit_id' => 'Kunjungan tidak berada di cabang RME aktif.',
                ]);
            }

            // BEFORE the insert. A refused encounter leaves no trace. A finalized
            // chart answers with its lock before a missing PTM (rule 240 §1.8).
            $this->assertChartAuthoringAllowed($clinicVisit, $user);

            // Rule 223 §1 item 6 — the visit row before the chart row, the order
            // updatePlaceholder() below takes them in.
            $this->lockOpenVisit((int) $clinicVisit->getKey());

            $odontogram = $this->odontograms->findByClinicVisitForUpdate($clinicVisit->id)
                ?? $this->odontograms->createForClinicVisit($clinicVisit, [
                    'created_by' => $user->id,
                ]);

            return $this->updatePlaceholder($odontogram, $payload, $user);
        });
    }

    /**
     * The bare create primitive — NOT a clinical write entry point.
     *
     * FIX-01 — it has ZERO production callers and must keep having none. It
     * checks the RME branch but deliberately does NOT assert the authoring
     * authority, so calling it from application code would recreate exactly the
     * defect this sprint removed: a `trx_odontograms` row inserted for a visit
     * nobody may chart.
     * Use {@see self::saveForVisit()} for any real write.
     *
     * It survives only because three tests pin its own behaviour and several
     * others use it as a fixture primitive. That "no production caller" claim
     * is not left to this docblock: it is enforced by
     * `tests/Feature/Architecture/RepositoryArtifactHygieneTest.php`, which
     * fails if any file under `app/` outside this class references it.
     *
     * @internal
     */
    public function getOrCreateForVisit(ClinicVisit $clinicVisit, User $user): Odontogram
    {
        return DB::transaction(function () use ($clinicVisit, $user) {
            if (! $this->isActiveRmeBranch($clinicVisit->branch_id)) {
                throw ValidationException::withMessages([
                    'clinic_visit_id' => 'Kunjungan tidak berada di cabang RME aktif.',
                ]);
            }

            return $this->odontograms->createForClinicVisit($clinicVisit, [
                'created_by' => $user->id,
            ]);
        });
    }

    public function updatePlaceholder(Odontogram $odontogram, array $payload, User $user): Odontogram
    {
        return DB::transaction(function () use ($odontogram, $payload, $user) {
            if (! $this->isActiveRmeBranch($odontogram->branch_id)) {
                throw ValidationException::withMessages([
                    'odontogram_id' => 'Odontogram tidak berada di cabang RME aktif.',
                ]);
            }

            /*
             * CORRECTIVE-03 — POSITIVE authority, resolved from server state.
             *
             * Supersedes the Sprint 59 rule that any odontogram is revisable at
             * any time: a write may never reach back into a previous encounter's
             * chart. The chart being written must be the live encounter's own
             * chart. Rule 241 §2: charting waits for the EXECUTED consent (both
             * signatures); the authority locks the visit row before the chart.
             */
            $this->assertChartAuthoringAllowed($odontogram->clinicVisit, $user);

            /*
             * Rule 240 §1 (memo M3.3, owner decision 2026-11-17) — a FINALIZED
             * chart is locked; it is corrected only through a supervised
             * amendment. This supersedes the Sprint 59 / rule 134 §5 allowance
             * that a finalized chart on the live encounter stays revisable. The
             * lock reads `status` alone (a chart finalized before this rule is
             * locked too) and is decided on the chart row locked after the visit
             * row, so it binds Super Admin, whom Gate::before lets past
             * OdontogramPolicy::update.
             */
            $locked = $this->lockChart($odontogram);
            self::refuseFinalizedChart($locked);

            $safe = array_intersect_key($payload, array_flip(['summary_notes', 'additional_conditions', 'tooth_map_payload']));
            $safe['updated_by'] = $user->id;

            if (isset($safe['tooth_map_payload']['teeth']) && is_array($safe['tooth_map_payload']['teeth'])) {
                foreach ($safe['tooth_map_payload']['teeth'] as $num => $data) {
                    if (isset($data['conditions']) && is_array($data['conditions'])) {
                        $safe['tooth_map_payload']['teeth'][$num]['conditions'] = array_values(
                            array_unique(array_filter($data['conditions'], fn ($c) => $c !== null))
                        );
                    }
                }
            }

            $updated = $this->odontograms->updatePlaceholder($locked, $safe);
            $odontogram->refresh();

            return $updated;
        });
    }

    public function finalize(Odontogram $odontogram, User $user): Odontogram
    {
        return DB::transaction(function () use ($odontogram, $user) {
            if (! $this->isActiveRmeBranch($odontogram->branch_id)) {
                throw ValidationException::withMessages([
                    'odontogram_id' => 'Odontogram tidak berada di cabang RME aktif.',
                ]);
            }

            // Finalizing a chart is a clinical write, so it needs the same
            // authorized, live encounter and executed consent (rule 241 §2).
            // Asserted BEFORE the already-finalized short circuit, so a refused
            // caller cannot use the idempotent path to probe which charts exist.
            $this->consents->assertOdontogramAuthoringAllowed($odontogram->clinicVisit, $user);

            // Rule 240 §1 — re-decided on the locked chart row: a finalize that
            // committed after the caller loaded its copy is returned as it is,
            // never rewritten.
            $locked = $this->lockChart($odontogram);

            if ($locked->isFinalized()) {
                $odontogram->refresh();

                return $locked;
            }

            $finalized = $this->odontograms->finalize($locked, [
                'status' => Odontogram::STATUS_FINALIZED,
                'finalized_at' => now(),
                'finalized_by' => $user->id,
                'updated_by' => $user->id,
            ]);
            $odontogram->refresh();

            return $finalized;
        });
    }

    /**
     * Rule 240 §1.8 — the refusal order of a chart write, the odontogram
     * authoring authority (rule 134, rule 241 §2) and the final lock together.
     *
     * The authority decides, in its own order, the live encounter, the actor, the
     * chart's identity (a previous visit's chart answers as history) and only
     * then the EXECUTED consent. A FINALIZED chart answers with its lock BEFORE
     * that consent: signing the PTM can never unlock it, so a missing signature is
     * never the reason given for it. When the authority's ONLY refusal is the PTM
     * (its two awaiting messages), the chart's visit row — the authorized
     * encounter — is locked and re-checked, then the chart row (rule 223 §1 item
     * 6), and a finalized chart is refused with the lock; otherwise the consent
     * refusal stands. Both decisions are made on rows this transaction holds and
     * nothing is written. Callers re-decide the lock on the chart row after this.
     */
    private function assertChartAuthoringAllowed(?ClinicVisit $visit, User $user): void
    {
        try {
            $this->consents->assertOdontogramAuthoringAllowed($visit, $user);
        } catch (ValidationException $refusal) {
            $onlyThePtm = in_array($refusal->errors()['consent'][0] ?? null, [
                RmeVisitConsentService::MESSAGE_ODONTOGRAM_AWAITING_PATIENT,
                RmeVisitConsentService::MESSAGE_ODONTOGRAM_AWAITING_DOCTOR,
            ], true);

            if ($onlyThePtm && $visit !== null) {
                $this->lockOpenVisit((int) $visit->getKey());
                self::refuseFinalizedChart($this->odontograms->findByClinicVisitForUpdate((int) $visit->getKey()));
            }

            throw $refusal;
        }
    }

    /** Rule 240 §1 — the one refusal of any write on a finalized chart (pass the LOCKED row). */
    private static function refuseFinalizedChart(?Odontogram $lockedChart): void
    {
        if ($lockedChart !== null && $lockedChart->isFinalized()) {
            throw ValidationException::withMessages([
                'odontogram' => self::MESSAGE_FINALIZED_CHART_LOCKED,
            ]);
        }
    }

    /**
     * Rule 240 §1 / rule 223 §1 item 6 — lock the chart's visit row (FOR NO KEY UPDATE,
     * the lock every RME write takes first), then the chart row, and return the
     * chart as it is now. Callers assert the authoring authority before this.
     */
    private function lockChart(Odontogram $odontogram): Odontogram
    {
        $this->lockOpenVisit((int) $odontogram->clinic_visit_id);

        $locked = $this->odontograms->findByClinicVisitForUpdate((int) $odontogram->clinic_visit_id);

        if ($locked === null || (int) $locked->getKey() !== (int) $odontogram->getKey()) {
            throw (new ModelNotFoundException)->setModel(Odontogram::class, [$odontogram->getKey()]);
        }

        return $locked;
    }

    /**
     * The chart's visit, locked and re-checked: the authority above read it
     * before the lock, so a finish or cancel committed meanwhile is seen here
     * (rule 223 §1 items 1–2) instead of a chart being written onto a closed encounter.
     */
    private function lockOpenVisit(int $clinicVisitId): ClinicVisit
    {
        $visit = $this->visits->lockForStatusDecision($clinicVisitId);

        if ($visit === null || $visit->status !== ClinicVisit::STATUS_IN_PROGRESS) {
            throw ValidationException::withMessages([
                'consent' => 'Odontogram hanya dapat diubah saat pasien sedang dalam pemeriksaan. Mulai pemeriksaan terlebih dahulu.',
            ]);
        }

        return $visit;
    }
}
