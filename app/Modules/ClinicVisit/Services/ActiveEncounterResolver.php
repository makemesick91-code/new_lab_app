<?php

namespace App\Modules\ClinicVisit\Services;

use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use Illuminate\Support\Collection;

/**
 * FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / CORRECTIVE-02 — who is being
 * examined right now?
 *
 * Read-only, and deliberately the smallest possible primitive: it answers ONE
 * question, from server state only, and it can neither transition a visit nor be
 * steered by a request.
 *
 * It exists because RME write authority cannot be derived from the request or
 * from a record's historical linkage. Sprint 64.0.2 stores the patient's
 * handwriting on their CANONICAL medical record, which for a returning patient
 * belongs to their FIRST — long finished — visit, so
 * `medical_record.clinic_visit_id` describes where bytes are stored, not who is
 * in the chair. Asking "does this patient have an active examination?" is the
 * only question whose answer a caller cannot influence.
 *
 * The active examination is `in_progress` and nothing else:
 *
 *   - `registered` / `waiting` are queue states. The doctor has not started, so
 *     there is no treatment to consent to and nothing to record.
 *   - `cashier_pending` is past the examination — the doctor explicitly finished
 *     it. Reopening it for new clinical authoring would undo that.
 *   - `completed` / `cancelled` are terminal. Cancelling a visit must never
 *     become a way to unlock the patient's record.
 *
 * Scope is the active RME branch set, so a visit outside the RME estate is never
 * treated as a live encounter.
 */
class ActiveEncounterResolver
{
    public function __construct(
        private readonly BranchService $branches,
    ) {}

    /**
     * The patient's current active examination, or null.
     *
     * AMBIGUITY FAILS CLOSED. If a patient somehow has more than one `in_progress`
     * visit, this returns null rather than picking one. Picking would let a
     * SECOND encounter's consent authorise writes attributed to the FIRST — so a
     * patient who declined on the visit actually happening could be overridden by
     * registering another visit and taking a signature there. Nothing in the
     * schema prevents two active visits (the only uniques are visit_number and
     * branch+date+queue), so this is a real state, not a hypothetical.
     *
     * The operational cost is a clear refusal that a human resolves by closing the
     * stale visit; the alternative is a silently mis-attributed consent.
     */
    public function currentFor(?int $patientId): ?ClinicVisit
    {
        $active = $this->activeFor($patientId);

        return $active->count() === 1 ? $active->first() : null;
    }

    /**
     * Every `in_progress` visit for this patient within the RME estate.
     *
     * @return Collection<int, ClinicVisit>
     */
    public function activeFor(?int $patientId): Collection
    {
        if ($patientId === null) {
            return collect();
        }

        $branchIds = $this->branches->rmeEnabledIds();

        if ($branchIds === []) {
            // Fail closed: an unresolved branch set is never "every branch".
            return collect();
        }

        return ClinicVisit::query()
            ->where('patient_id', $patientId)
            ->where('status', ClinicVisit::STATUS_IN_PROGRESS)
            ->whereIn('branch_id', $branchIds)
            ->orderByDesc('visit_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Is this specific visit the patient's current active examination?
     *
     * Identity, not just status: a second `in_progress` row for the same patient
     * is not interchangeable with the encounter this resolver selected.
     */
    public function isCurrentEncounter(?ClinicVisit $visit): bool
    {
        if ($visit === null) {
            return false;
        }

        $current = $this->currentFor($visit->patient_id);

        return $current !== null && $current->id === $visit->id;
    }
}
