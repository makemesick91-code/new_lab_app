<?php

namespace App\Modules\Odontogram\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
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
     *   - odontograms with no recorded teeth excluded, because the page
     *     auto-creates an empty draft row on every open and those are not history
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
     * recorded. Opening the page creates an empty draft, and an empty draft is
     * not a clinical finding.
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

            // Sprint 59 — odontogram data (table + generated visual) is revisable
            // at any time, including previously finalized records and older
            // visits. The finalization edit-lock is removed; `status`,
            // `finalized_at`, and `finalized_by` remain for backward compatibility.

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
