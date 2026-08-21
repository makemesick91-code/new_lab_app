<?php

namespace App\Modules\Odontogram\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\Odontogram\Interfaces\OdontogramRepositoryInterface;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\RME\Services\DoctorPatientScopeService;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OdontogramService
{
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
        // CORRECTIVE-03 — the active odontogram is a clinical workspace, so it
        // now carries the same signed-consent authority as the RME beside it.
        private readonly RmeVisitConsentService $consents,
    ) {}

    /**
     * FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-04 — the patient's PREVIOUS
     * odontograms, as a read-only view model.
     *
     * READ-ONLY BY CONSTRUCTION. This returns presentation rows, not models bound
     * to a form: the history card renders them and offers no edit, save, finalize
     * or delete control, and there is no mutation endpoint that accepts a history
     * row. Correcting a past chart is still done by opening that visit's own
     * odontogram page, which keeps the Sprint 59 behaviour untouched.
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

        return $records
            ->filter(fn (Odontogram $o) => $this->hasRecordedTeeth($o))
            ->map(fn (Odontogram $o) => $this->toHistoryRow($o))
            ->values();
    }

    /**
     * An odontogram counts as history only once a tooth has actually been
     * recorded: an empty draft is not a clinical finding.
     *
     * POST-RME-ODONTOGRAM-STABILIZATION-1 / FIX-01 — opening the page no
     * longer creates such a draft. This filter is retained as belt-and-braces
     * for the empty rows that already exist in production, which are not
     * deleted by that fix.
     */
    private function hasRecordedTeeth(Odontogram $odontogram): bool
    {
        $payload = $odontogram->tooth_map_payload;

        if (! is_array($payload)) {
            return false;
        }

        $teeth = $payload['teeth'] ?? null;

        return is_array($teeth) && $teeth !== [];
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
     * Consent is asserted BEFORE the row is created, not after: otherwise an
     * unconsented submission would still persist an empty chart and then fail,
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

            // BEFORE the insert. A refused encounter leaves no trace.
            $this->consents->assertOdontogramAuthoringAllowed($clinicVisit, $user);

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
     * checks the RME branch but deliberately does NOT assert consent, so
     * calling it from application code would recreate exactly the defect this
     * sprint removed: a `trx_odontograms` row inserted with no signed consent.
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
             * any time. Sprint 59 removed the FINALIZATION lock, and that part
             * stands: a finalized chart on the LIVE encounter is still revisable.
             * What it may no longer do is reach back into a previous encounter's
             * chart, and it may not record anything at all before the patient has
             * signed. The chart being written must be the live, consented
             * encounter's own chart.
             *
             * The status/finalized_at/finalized_by columns keep their meaning and
             * are untouched.
             */
            $this->consents->assertOdontogramAuthoringAllowed($odontogram->clinicVisit, $user);

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

            return $this->odontograms->updatePlaceholder($odontogram, $safe);
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

            // CORRECTIVE-03 — finalizing a chart is a clinical write, so it needs
            // the same authorized, consented, live encounter. Asserted BEFORE the
            // already-finalized short circuit, so an unconsented caller cannot use
            // the idempotent path to probe which charts exist.
            $this->consents->assertOdontogramAuthoringAllowed($odontogram->clinicVisit, $user);

            if ($odontogram->isFinalized()) {
                return $odontogram;
            }

            return $this->odontograms->finalize($odontogram, [
                'status' => Odontogram::STATUS_FINALIZED,
                'finalized_at' => now(),
                'finalized_by' => $user->id,
                'updated_by' => $user->id,
            ]);
        });
    }
}
