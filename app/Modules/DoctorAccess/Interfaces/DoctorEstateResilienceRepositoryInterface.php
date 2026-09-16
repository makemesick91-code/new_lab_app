<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Interfaces;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The read-only estate reads the resilience and capacity engines need.
 *
 * DOCTOR-ACCESS-TRUSTED-DEVICE-ESTATE-RESILIENCE-1 added the first:
 * `activeTreatmentRoomCountsByBranch()`, advisory by design and explained at
 * length below. REVISION-DOCTOR-TRUSTED-DEVICE-ESTATE-CAPACITY-POLICY-1 added
 * the room PROFILE and the active-cover read, each documented on its own
 * method. Every one is a read; see the closing note.
 *
 * WHY THE FIRST READ EXISTS AT ALL, AND WHY IT IS ADVISORY.
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

    /**
     * REVISION-DOCTOR-TRUSTED-DEVICE-ESTATE-CAPACITY-POLICY-1 — the room
     * denominator Level 2 divides by, and the wider set it must be compared
     * against.
     *
     * WHY THIS IS NOT THE ADVISORY COUNT ABOVE, AND WHY IT DECIDES SOMETHING.
     *
     * The method above is advisory because it was offered as a stand-in for
     * `concurrent Doctor stations`, which it is not. Level 2 asks a DIFFERENT
     * question the owner has now defined outright — one eligible trusted device
     * per ACTIVE DOCTOR ROOM — and a room is precisely the unit that question
     * counts in. So rooms decide Level 2 and go on deciding nothing at Level 3.
     *
     * TWO COUNTS, BECAUSE THE TWO ROOM-SETS IN THIS CODEBASE DISAGREE.
     *
     *   `doctor_facing`  active rooms typed `treatment_room` or
     *                    `consultation_room` — the rooms where a doctor and a
     *                    patient meet. An x-ray, sterilization or lab room
     *                    needs no Doctor tablet, and counting one would inflate
     *                    the requirement with hardware nobody would use.
     *
     *   `assignable`     every active room at the branch, whatever its type.
     *                    This is the set `ClinicVisitService::
     *                    activeRoomsForBranch()` actually offers the room gate:
     *                    its docblock says "active treatment rooms" and its
     *                    query filters branch and status with NO type clause,
     *                    so a doctor CAN today be placed in a sterilization
     *                    room.
     *
     * Both are returned so the divergence is reported rather than inherited.
     * Choosing `assignable` alone would size the estate for rooms no doctor
     * works in; choosing `doctor_facing` alone would silently adopt a type
     * filter the assignment path does not enforce.
     *
     * A branch with no active rooms at all is ABSENT from the map, exactly as
     * above: never configured and measured-as-none are different facts, and
     * only the caller knows which it holds. Level 2 must branch on absence
     * before any arithmetic — `(int) null === 0` would make `eligible >= rooms`
     * true for a branch holding nothing.
     *
     * @return Collection<int, array{doctor_facing:int, assignable:int}>
     */
    public function activeDoctorRoomProfileByBranch(): Collection;

    /**
     * Branch ids hosting a doctor under an APPROVED, currently-running branch
     * cover.
     *
     * WHY THE STAFFED POPULATION CANNOT BE HOME LOCKS ALONE. A cover grants a
     * doctor time-boxed authority to work away from home, and
     * `DoctorBranchLock::isLockedTo()` says outright that an active cover does
     * not change the home answer. So a branch running a covering doctor has
     * `home_doctor_count = 0` and a doctor in it — and a capacity level keyed
     * on home locks alone would report NOT_APPLICABLE for a branch that is
     * seeing patients on no tablet.
     *
     * Judged by the row and the instant, through the model's own
     * `coversInstant()`, so an expired cover cannot linger because a job was
     * late. Read-only, like everything on this interface.
     *
     * Returns branch id => branch CODE (null when the branch is soft-deleted),
     * not bare ids. A branch reachable ONLY through a cover holds no device and
     * homes no doctor, so nothing else in the report can resolve its code — and
     * a matrix row with a null code raises a finding that names soft-deletion
     * as the cause. Carrying the code here keeps that finding truthful.
     *
     * @return array<int, string|null>
     */
    public function activeCoverTargetBranches(CarbonInterface $at): array;
}
