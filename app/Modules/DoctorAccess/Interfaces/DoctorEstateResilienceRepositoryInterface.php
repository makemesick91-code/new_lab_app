<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Interfaces;

use Illuminate\Support\Collection;

/**
 * DOCTOR-ACCESS-TRUSTED-DEVICE-ESTATE-RESILIENCE-1 — the one read this sprint
 * adds: how many ACTIVE treatment rooms each branch runs.
 *
 * WHY THIS EXISTS AT ALL, AND WHY IT IS ADVISORY.
 *
 * An adversarial review of this sprint refuted a claim it had made in its own
 * documentation. The sprint asserted that "concurrent Doctor stations" — the
 * input the spare-device formula needs — "exists in no database table". It
 * overstated. `mst_clinic_rooms` is branch-scoped, typed, status-tracked and
 * already populated per RME branch, and
 *
 *     COUNT(*) WHERE branch_id = ? AND type = 'treatment_room'
 *              AND status = 'active' AND deleted_at IS NULL
 *
 * is a real, existing, branch-scoped candidate. Declining it silently would
 * have left a false statement in the record.
 *
 * SO IT IS READ, REPORTED, AND DELIBERATELY NOT USED TO DECIDE ANYTHING.
 *
 * A treatment room is an INVENTORY OF ROOMS. The runbook asks for PEAK
 * CONCURRENT STAFFED STATIONS, which is a different quantity in both
 * directions: a branch with three rooms and one doctor on shift runs one
 * station, and a single room fitted with two chairs runs two. Substituting one
 * for the other would be exactly the failure this engine exists to end —
 * manufacturing the input to its own gate — merely with a more plausible
 * number than a guess.
 *
 * What the count IS good for is giving whoever records the real figure a
 * defensible starting point, on the same screen, sourced rather than imagined.
 *
 * WHY NOT `ClinicRoomRepositoryInterface`. That interface carries create,
 * update and delete. A resilience engine that can write is one that can
 * manufacture its own capacity, and the cheapest way to keep that guarantee
 * honest is to not hand it a write-capable dependency. Its `listActive()` is
 * also per-branch, so the estate would cost one query per branch.
 *
 * READ-ONLY, and there will never be a write method here.
 */
interface DoctorEstateResilienceRepositoryInterface
{
    /**
     * Active, non-deleted treatment rooms per branch, keyed by branch id.
     *
     * A branch with none is ABSENT from the map rather than present with zero —
     * a branch nobody has configured rooms for and a branch measured as having
     * none are different facts, and only the caller knows which it is holding.
     *
     * @return Collection<int, int>
     */
    public function activeTreatmentRoomCountsByBranch(): Collection;
}
