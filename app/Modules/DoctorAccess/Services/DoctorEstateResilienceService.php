<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Services;

use App\Modules\DoctorAccess\Interfaces\DoctorBranchLockRepositoryInterface;
use App\Modules\DoctorAccess\Interfaces\DoctorEstateResilienceRepositoryInterface;
use App\Modules\DoctorAccess\Models\DoctorBranchLock;
use App\Modules\DoctorAccess\Support\DoctorEstateCapacityLevel;
use App\Modules\DoctorAccess\Support\DoctorEstateResilienceVerdict;
use App\Modules\DoctorDevice\Interfaces\DoctorDeviceRolloutReadinessRepositoryInterface;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\DoctorDevice\Services\DoctorDeviceIdentityProofPolicy;
use App\Modules\DoctorDevice\Support\WebAuthnDeviceBinding;
use Illuminate\Support\Collection;

/**
 * DOCTOR-ACCESS-TRUSTED-DEVICE-ESTATE-RESILIENCE-1 — does the hardware estate
 * survive losing a tablet?
 *
 * WHY THIS ENGINE HAD TO BE WRITTEN. `spare_device_available_per_branch` has
 * been a declared prerequisite of global doctor enforcement since Phase 3.5 and
 * has NEVER had an implementation. It exists as a string in
 * `android_release.enforcement.global_prerequisites` and as a hand-signed
 * boolean beside it. The scanner that reads those asserts only that somebody
 * SIGNED — by its own docblock, "it cannot and does not measure the world".
 *
 * That is a defensible position for an attestation and an indefensible one for
 * a programme about to widen enforcement to fifteen doctors: nothing in the
 * system could tell the signer whether the thing they were signing was true.
 * This engine measures the part that IS measurable, so an attestation becomes a
 * signature on a fact rather than a signature on a hope.
 *
 * WHAT IT DOES NOT DO. It does not attest, does not write, does not arm and
 * does not widen a cohort. Signing remains a human act with a human's name on
 * it, and the ABSENCE of a signature still fails nothing here — the owner's
 * choice of measurement over coupling stands.
 *
 * WHAT REVISION-DOCTOR-TRUSTED-DEVICE-ESTATE-CAPACITY-POLICY-1 NARROWED. One
 * case, in one direction: a signature that CONTRADICTS a measurement now fails
 * a gate of its own. Nothing is trusted more than before — the point is that a
 * recorded `true` can no longer sit beside a measured falsehood and be reported
 * as agreement. An unsigned prerequisite is still merely unsigned.
 *
 * WHAT THAT REVISION SEPARATED. Three requirements had been asked with one
 * word. `spare_device_available_per_branch` is HIGH AVAILABILITY and is
 * unchanged here in key, formula and answer; beside it now sit LEVEL 1
 * (activation-test coverage) and LEVEL 2 (room capacity), measured from the
 * same snapshot and never derived from each other. See
 * {@see DoctorEstateCapacityLevel}.
 *
 * THE LIMIT OF THE MEASUREMENT, STATED PLAINLY. The runbook sizes capacity as
 * `concurrent Doctor stations + 1 spare`, and the station count is a fact about
 * a room. This engine never invents it. What it can decide without it is the
 * LOWER BOUND — for any station count >= 1 the requirement is at least two
 * eligible devices — and the estate fails that bound today at every branch.
 * Undecidability begins at two devices, not at zero.
 *
 * PHYSICAL BRANCH IS NOT AUTHORIZATION AUTHORITY. A device's `branch_id` says
 * where the tablet lives, never which doctors may use it. Cross-branch trusted
 * device use is valid and proven — every readiness ceremony in the fleet
 * campaign ran on ONE tablet at SPN4, including for doctors homed elsewhere.
 * So the per-branch numbers below are OPERATIONAL RESILIENCE (can this room
 * keep working when its tablet breaks?) and never an access boundary.
 */
class DoctorEstateResilienceService
{
    public function __construct(
        private readonly DoctorDeviceRolloutReadinessRepositoryInterface $estate,
        private readonly DoctorBranchLockRepositoryInterface $locks,
        private readonly DoctorFleetReadinessService $fleet,
        private readonly DoctorEstateResilienceRepositoryInterface $rooms,
        private readonly DoctorDeviceIdentityProofPolicy $identityProof,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(): array
    {
        /*
         * Cleared at the TOP of build(), never mid-way. The sibling engine
         * shipped this exact memo with its reset inside a consumer, so one
         * report could compute two of its tables from two different snapshots
         * of the hardware — and, because the last consumer re-filled the memo,
         * a second report was served the first one's estate. Both halves of
         * that bug come from the same misplacement, and the fix is the same:
         * one build, one question, one answer.
         */
        $this->deviceEstate = null;

        $estate = $this->deviceEstate();
        $locks = $this->locks->withDoctorAndBranch();
        $rooms = $this->rooms->activeTreatmentRoomCountsByBranch();

        /*
         * REVISION-DOCTOR-TRUSTED-DEVICE-ESTATE-CAPACITY-POLICY-1 — read ONCE,
         * here, beside every other estate read, for the same reason the device
         * estate is: one build, one question, one answer. A level that fetched
         * its own denominator would be a second moment in a report that claims
         * to describe one.
         */
        $roomProfile = $this->rooms->activeDoctorRoomProfileByBranch();
        $coverBranches = $this->rooms->activeCoverTargetBranches(now());

        $branches = $this->branchMatrix($estate, $locks, $rooms, $roomProfile, $coverBranches);

        /*
         * The fleet engine owns the (doctor x eligible tablet) reconciliation.
         * It is COMPOSED rather than re-implemented: a second authorization
         * calculator is a second thing to keep in step, and this programme has
         * already paid for one predicate written down twice.
         */
        $fleetReport = $this->fleet->build();

        $eligibleIds = $this->eligibleDeviceIds($estate);
        $snapshot = $this->snapshotAgreement($eligibleIds, $fleetReport);

        /*
         * LEVEL 1 IS RESOLVED ONCE, HERE, GUARD INCLUDED.
         *
         * It used to be computed twice — guarded in the gate, raw in the
         * capacity block — so one report could print UNVERIFIED in the gate
         * list and PASS in the capacity table three lines below it, with
         * `minimum_additional_branches_to_cover: 0` beside the PASS. The
         * capacity block is the surface an operator is told to act on, so the
         * disagreeing half was the actionable one.
         */
        $level1 = $this->activationTestCoverageLevel($branches, $fleetReport);

        $gates = $this->gates($branches, $eligibleIds, $fleetReport, $snapshot, $level1);

        /*
         * The attestation cross-check reads the measurement gates, so it is
         * computed AFTER them and appended as a gate of its own. Ordering, not
         * preference: a gate cannot cross-check a verdict that does not exist
         * yet.
         */
        /*
         * The composed measurement is resolved BEFORE the attestation, because
         * the attestation cross-check must compare a signature against the
         * SAME question the signature names.
         *
         * It used to compare against the single Level-1 gate while the
         * prerequisite of the identical name composed three, so a signature
         * standing beside a FAILING prerequisite printed "Each agrees with what
         * this engine measured". One key, two measurements, in one report.
         */
        $composed = $this->composedActivationMeasurement($gates);

        $attestation = $this->attestation($gates, $composed);
        $gates[] = $this->attestationGate($attestation);

        $prerequisite = $this->activationTestPrerequisite($composed, $attestation);

        return [
            /*
             * THE FULL-MATURITY AGGREGATE, and NOT the activation-testing gate.
             *
             * Worst of every gate, exactly as shipped, so it is never greener
             * than `spare_device_available_per_branch` and no reader of the old
             * field is handed a weaker question under the old name. The three
             * capacity levels are reported separately BECAUSE this number
             * cannot answer them: today it reads FAIL while two of three
             * staffed branches clear Level 1.
             */
            'verdict' => DoctorEstateResilienceVerdict::worst(
                array_map(static fn (array $gate): string => (string) $gate['verdict'], $gates),
            ),
            'verdict_semantics' => 'ESTATE_RESILIENCE is the worst of EVERY gate and retains its shipped '
                .'meaning: it can never be greener than '.DoctorEstateResilienceVerdict::GATE_SPARE_DEVICE
                .' (high availability). It is NOT the activation-testing signal — read '
                .'ACTIVATION_TEST_COVERAGE for that.',
            'branch_scope' => DoctorEstateResilienceVerdict::BRANCH_SCOPE,
            'capacity_policy' => $this->capacityLevels($branches, $level1),
            'activation_test_prerequisite' => $prerequisite,
            'gates' => $gates,
            'branches' => $branches,
            'devices' => $this->deviceDetail($estate),
            'estate_totals' => $this->estateTotals($estate, $eligibleIds),
            'authorization_matrix' => $fleetReport['authorization_matrix'] ?? [],
            'failure_domain' => $this->failureDomain($branches),
            'required_capacity' => $this->requiredCapacity($branches),
            'attestation' => $attestation,
            'findings' => $this->findings($branches, $estate, $snapshot, $fleetReport),
            'runtime' => $fleetReport['runtime'] ?? [],

            /*
             * Printed with the verdict, never buried in --json. A PASS here
             * would mean the hardware survives a loss; it would still not mean
             * anybody may arm a flag.
             */
            'authorizes_activation' => false,
            'resilience_semantics' => 'Per-branch device counts are OPERATIONAL RESILIENCE, not an access '
                .'boundary. A tablet\'s branch says where the hardware lives; cross-branch trusted-device use '
                .'is valid and proven. A PASS authorises no activation, no flag and no cohort change.',
        ];
    }

    /**
     * The branch universe: every branch that holds a registered Doctor device
     * or is some doctor's HOME branch.
     *
     * Built as a UNION rather than from a branch table, and that is the whole
     * point of the reading. Today the two halves disagree: ATG3 holds a tablet
     * and homes nobody, TLK1 homes two doctors and holds nothing. A scope taken
     * from either half alone would have hidden exactly one of those, and the
     * hidden one would have been TLK1 — the only branch that actually cannot
     * work.
     *
     * @param  Collection<int, DoctorDevice>  $estate
     * @param  Collection<int, DoctorBranchLock>  $locks
     * @param  Collection<int, int>  $rooms
     * @param  Collection<int, array{doctor_facing:int, assignable:int}>  $roomProfile
     * @param  array<int, string|null>  $coverBranches
     * @return list<array<string,mixed>>
     */
    private function branchMatrix(
        Collection $estate,
        Collection $locks,
        Collection $rooms,
        Collection $roomProfile,
        array $coverBranches,
    ): array {
        $branches = [];

        foreach ($estate as $device) {
            $branchId = (int) $device->branch_id;

            $branches[$branchId] ??= $this->emptyBranch($branchId, $device->branch?->code, $device->branch?->name);
        }

        $homeCounts = [];
        $orphanLockBranchIds = [];

        foreach ($locks as $lock) {
            $branchId = (int) $lock->home_branch_id;

            if ($branchId === 0) {
                continue;
            }

            /*
             * COUNT LIVE DOCTORS, NOT LOCK ROWS.
             *
             * `Doctor` is soft-deleted and the lock's FK is restrictOnDelete, so
             * retiring a doctor leaves their home lock behind forever. Counting
             * rows would keep reporting home doctors at that branch, could earn
             * it a GAP_NO_LOCAL_DEVICE, and would inflate required_capacity for
             * somebody who no longer practises. The composed fleet engine
             * resolves doctors through a soft-delete-scoped query, so the two
             * would also disagree — silently, because the snapshot gate compares
             * device ids and not this.
             *
             * The relation is eager-loaded by withDoctorAndBranch(), so a
             * soft-deleted doctor arrives here as null.
             */
            if ($lock->doctor === null) {
                $orphanLockBranchIds[$branchId] = true;

                continue;
            }

            $branches[$branchId] ??= $this->emptyBranch($branchId, $lock->homeBranch?->code, $lock->homeBranch?->name);
            $homeCounts[$branchId] = ($homeCounts[$branchId] ?? 0) + 1;
        }

        foreach ($estate as $device) {
            $branchId = (int) $device->branch_id;
            $row = &$branches[$branchId];

            $row['total_device_count']++;

            if ($device->isRevoked()) {
                $row['revoked_device_ids'][] = (int) $device->id;
            }

            if (! $this->isEligible($device)) {
                continue;
            }

            $row['eligible_device_ids'][] = (int) $device->id;

            if ($this->unrevokedCredentialCount($device) === 0) {
                $row['eligible_devices_without_credential'][] = (int) $device->id;
            }

            /*
             * A STRICTER LIST, for Level 1 only, and NOT a redefinition of the
             * one above.
             *
             * `device_credential_coverage` asks whether a tablet carries an
             * UNREVOKED credential, and that is its shipped meaning. Level 1
             * asks something harder — whether a doctor could actually SIGN IN
             * on it — and an adversarial review found the gap between the two:
             * a credential that is never revoked but whose device-binding
             * verdict the login gate refuses is usable by the first predicate
             * and DENIED by the gate. A branch holding only that tablet would
             * have reported activation-test coverage PASS while every login on
             * it fails.
             */
            if ($this->loginAdmissibleCredentialCount($device) === 0) {
                $row['eligible_devices_without_admissible_credential'][] = (int) $device->id;
            }
        }

        unset($row);

        /*
         * REVISION-1 — a branch hosting a COVERING doctor is staffed too.
         *
         * A cover grants a doctor time-boxed authority to work away from home
         * and deliberately does not move their home lock, so such a branch
         * counts zero home doctors while a doctor stands in it. Without this
         * the branch would be read as unstaffed, land on NOT_APPLICABLE, and
         * every capacity level would decline to ask whether it holds a tablet.
         *
         * It is added to the branch UNIVERSE as well as to the staffed flag: a
         * covered branch with no hardware and no home doctor has no other way
         * into the matrix, and that is precisely the branch worth seeing.
         */
        foreach ($coverBranches as $coverBranchId => $coverBranchCode) {
            $branches[$coverBranchId] ??= $this->emptyBranch($coverBranchId, $coverBranchCode, null);
        }

        $rows = [];

        foreach ($branches as $branchId => $row) {
            $row['home_doctor_count'] = $homeCounts[$branchId] ?? 0;
            $row['hosts_active_cover'] = array_key_exists($branchId, $coverBranches);

            /*
             * ADVISORY ONLY, and it decides nothing below. A treatment room is
             * an inventory of rooms; the formula wants PEAK CONCURRENT STAFFED
             * stations. Three rooms with one doctor on shift is one station;
             * one room with two chairs is two. Reported so whoever records the
             * real figure starts from something sourced.
             */
            $row['active_treatment_rooms'] = $rooms->get($branchId);
            $row['eligible_device_count'] = count($row['eligible_device_ids']);
            $row['revoked_device_count'] = count($row['revoked_device_ids']);

            /*
             * REVISION-1 — devices that can actually be LOGGED INTO.
             *
             * Level 1 counts these, not `eligible_device_count`. An adversarial
             * review found the hole: a branch holding one active,
             * cryptographically verified tablet whose only credential is
             * revoked scores eligible = 1, and the activation-testing
             * prerequisite would have read PASS at a branch where no doctor can
             * sign in. `device_credential_coverage` FAILs beside it, but a
             * reader told to watch one field would not have been looking there.
             *
             * Derived by SUBTRACTION from the lists already collected above,
             * deliberately: a second eligibility predicate is a second thing to
             * keep in step, and this module has paid for that once.
             */
            $row['locally_usable_device_count'] = max(
                0,
                $row['eligible_device_count'] - count($row['eligible_devices_without_admissible_credential']),
            );

            /*
             * ROOM DENOMINATOR — carried as an explicit `known` flag beside the
             * counts, never as a nullable int the arithmetic could cast.
             * `(int) null === 0` makes `eligible >= rooms` true for a branch
             * holding nothing, which would turn "nobody configured this
             * branch's rooms" into a satisfied capacity target.
             */
            $profile = $roomProfile->get($branchId);

            $row['active_doctor_rooms'] = $profile === null ? null : (int) $profile['doctor_facing'];
            $row['active_assignable_rooms'] = $profile === null ? null : (int) $profile['assignable'];
            $row['room_profile_known'] = $profile !== null;

            /*
             * STAFFED is the population every capacity level is measured over,
             * and it is two facts, not one: doctors homed here, or a doctor
             * covering here right now.
             */
            $row['staffed'] = (int) $row['home_doctor_count'] > 0 || $row['hosts_active_cover'] === true;

            /*
             * Spare is reported against the WEAKEST station assumption there
             * is — one concurrent station — because that is the only one the
             * data supports. It is named for that assumption so nobody reads it
             * as the branch's actual headroom.
             */
            $row['spare_devices_at_one_station'] = max(0, $row['eligible_device_count'] - 1);
            $row['serviceable_after_one_device_loss'] = $row['eligible_device_count']
                >= DoctorEstateResilienceVerdict::MINIMUM_DEVICES_FOR_ANY_SPARE;

            [$verdict, $gaps] = $this->branchVerdict($row);

            $row['gaps'] = $gaps;
            $row['verdict'] = $verdict;

            /*
             * THE THREE LEVELS, COMPUTED FROM THE ONE ROW.
             *
             * `verdict` above is LEVEL 3 and is untouched by this revision —
             * same formula, same key, same answer. The two below are separate
             * questions asked of the same snapshot, never derived from each
             * other and never from `verdict`.
             */
            $row['capacity_levels'] = [
                DoctorEstateCapacityLevel::ACTIVATION_TEST_COVERAGE => $this->activationTestCoverageStatus($row),
                DoctorEstateCapacityLevel::ROOM_CAPACITY => $this->roomCapacityStatus($row),
                DoctorEstateCapacityLevel::FAILURE_RESILIENCE => $this->failureResilienceStatus($row),
            ];

            $rows[] = $row;
        }

        usort($rows, static fn (array $a, array $b): int => $a['branch_id'] <=> $b['branch_id']);

        $this->orphanHomeLockBranchIds = array_keys($orphanLockBranchIds);

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyBranch(int $branchId, ?string $code, ?string $name): array
    {
        return [
            'branch_id' => $branchId,
            'branch_code' => $code === null ? null : (string) $code,
            'branch_name' => $name === null ? null : (string) $name,
            'home_doctor_count' => 0,
            'total_device_count' => 0,
            'eligible_device_ids' => [],
            'revoked_device_ids' => [],
            'eligible_devices_without_credential' => [],
            'eligible_devices_without_admissible_credential' => [],
            'active_treatment_rooms' => null,
            'hosts_active_cover' => false,
            'staffed' => false,
        ];
    }

    /**
     * LEVEL 1 for one branch — can this branch be TESTED at all?
     *
     * `>= 1` device that is eligible AND carries a credential the LOGIN GATE
     * WOULD ADMIT. Not `>= 1` eligible device, and not merely `>= 1` unrevoked
     * credential either — see `locally_usable_device_count` above for both
     * holes that closes.
     *
     * An unstaffed branch is NOT_APPLICABLE, which is neither a shortfall nor a
     * satisfied requirement — it is removed from the population entirely by
     * {@see DoctorEstateCapacityLevel::worst()} rather than ranked as a pass.
     *
     * @param  array<string,mixed>  $row
     */
    private function activationTestCoverageStatus(array $row): string
    {
        if ($row['staffed'] !== true) {
            return DoctorEstateCapacityLevel::NOT_APPLICABLE;
        }

        return (int) $row['locally_usable_device_count'] >= 1
            ? DoctorEstateCapacityLevel::PASS
            : DoctorEstateCapacityLevel::FAIL;
    }

    /**
     * LEVEL 2 for one branch — can every active Doctor room be worked?
     *
     * The owner's normal-production target: one eligible trusted device per
     * active Doctor room. Reported, and never a blocker of activation testing.
     *
     * A LOWER BOUND, and said so out loud. The sibling class argues that a room
     * is not a station "in either direction — three rooms with one doctor on
     * shift is one station, one room with two chairs is two". The first half
     * does not apply here (Level 2 sizes ROOMS, which is the unit the owner
     * chose), but the second half does: a room fitted with two chairs needs two
     * tablets, and no table records chairs. So a PASS means "meets the
     * one-device-per-room target", never "has enough tablets for peak
     * concurrency" — which is Level 3's question and keeps its own input.
     *
     * UNVERIFIED when the branch has no room profile at all. Nobody configuring
     * a branch's rooms is not the same fact as a branch measured at zero rooms,
     * and only one of those can be divided by.
     *
     * @param  array<string,mixed>  $row
     */
    private function roomCapacityStatus(array $row): string
    {
        if ($row['staffed'] !== true) {
            return DoctorEstateCapacityLevel::NOT_APPLICABLE;
        }

        if ($row['room_profile_known'] !== true) {
            return DoctorEstateCapacityLevel::UNVERIFIED;
        }

        $rooms = (int) $row['active_doctor_rooms'];
        $usable = (int) $row['locally_usable_device_count'];

        /*
         * A staffed branch with active rooms of which NONE is doctor-facing.
         * Dividing by zero rooms would make any device count sufficient — and
         * would make a branch with zero tablets sufficient. The branch is
         * staffed, so somebody is working somewhere; which room is not
         * something this engine can answer.
         */
        if ($rooms === 0) {
            return DoctorEstateCapacityLevel::UNVERIFIED;
        }

        if ($usable === 0) {
            return DoctorEstateCapacityLevel::FAIL;
        }

        return $usable >= $rooms
            ? DoctorEstateCapacityLevel::PASS
            : DoctorEstateCapacityLevel::PARTIAL;
    }

    /**
     * LEVEL 3 for one branch — survives losing one device.
     *
     * A TRANSLATION of the shipped `branchVerdict()` into the five-word
     * capacity vocabulary, never a second calculation of it. The formula, the
     * population and the answer are the previous sprint's; this method only
     * renames PASS/FAIL/UNVERIFIED so all three levels can be read in one
     * column, and adds NOT_APPLICABLE for the unstaffed rows Level 3 has always
     * excluded from its gate.
     *
     * @param  array<string,mixed>  $row
     */
    private function failureResilienceStatus(array $row): string
    {
        if ($row['staffed'] !== true) {
            return DoctorEstateCapacityLevel::NOT_APPLICABLE;
        }

        return match ((string) $row['verdict']) {
            DoctorEstateResilienceVerdict::PASS => DoctorEstateCapacityLevel::PASS,
            DoctorEstateResilienceVerdict::UNVERIFIED => DoctorEstateCapacityLevel::UNVERIFIED,
            default => DoctorEstateCapacityLevel::FAIL,
        };
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array{0:string,1:list<string>}
     */
    private function branchVerdict(array $row): array
    {
        $gaps = [];

        if ($row['eligible_devices_without_credential'] !== []) {
            $gaps[] = DoctorEstateResilienceVerdict::GAP_DEVICE_WITHOUT_CREDENTIAL;
        }

        $eligible = (int) $row['eligible_device_count'];

        /*
         * THE SPARE REQUIREMENT APPLIES TO BRANCHES THAT HOME DOCTORS, AND ONLY
         * TO THEM.
         *
         * A spare exists so that a branch losing a tablet can still see
         * patients. Where nobody is homed, there is no clinic day to protect and
         * no doctor to strand — the hardware is idle capacity, already reported
         * as its own finding, not a shortfall.
         *
         * This clause is not tidiness. Without it the verdicts contradict
         * themselves: a branch with zero devices and zero home doctors PASSED
         * while a branch with ONE device and zero home doctors FAILED, so
         * standing a tablet in an unstaffed room made the SPARE verdict worse.
         * Worse, it would have held `spare_device_available_per_branch` at FAIL
         * forever — after every staffed branch was fully provisioned — over a
         * branch that cannot strand anyone. Caught while charting the measured
         * estate, where the two rows sat next to each other.
         *
         * THE MONOTONICITY IS SCOPED TO THE SPARE REQUIREMENT, not to the row.
         * An unstaffed branch whose tablet carries no unrevoked credential still
         * reports FAIL through the credential gap collected above, and should:
         * an eligible device nobody can log into is a defect wherever it sits.
         * That row is excluded from the spare gate's population, so it reddens
         * the row and `device_credential_coverage` — never the spare gate.
         *
         * It also keeps the gate consistent with `requiredCapacity()`, which has
         * counted only branches homing doctors from the start.
         */
        /*
         * REVISION-1 widened this from `home_doctor_count === 0` to the
         * `staffed` flag, which is the same test PLUS a branch hosting an
         * active cover. A cover deliberately does not move a home lock, so such
         * a branch counted zero home doctors, took this early return, and a
         * covered branch holding no tablet reported PASS. Widening a population
         * can only add FAILs; no branch that failed before can pass now.
         */
        if ($row['staffed'] !== true) {
            return [
                $gaps === [] ? DoctorEstateResilienceVerdict::PASS : DoctorEstateResilienceVerdict::FAIL,
                $gaps,
            ];
        }

        if ($eligible === 0) {
            $gaps[] = DoctorEstateResilienceVerdict::GAP_NO_LOCAL_DEVICE;

            return [DoctorEstateResilienceVerdict::FAIL, $gaps];
        }

        if ($eligible < DoctorEstateResilienceVerdict::MINIMUM_DEVICES_FOR_ANY_SPARE) {
            $gaps[] = DoctorEstateResilienceVerdict::GAP_NO_SPARE;

            return [DoctorEstateResilienceVerdict::FAIL, $gaps];
        }

        /*
         * Two or more eligible devices, so the lower bound no longer settles
         * it. Whether this is a spare now depends on the concurrent station
         * count — which the engine reads and never guesses.
         */
        $stations = $this->declaredStations($row['branch_code']);

        if ($stations === null) {
            $gaps[] = DoctorEstateResilienceVerdict::GAP_STATION_COUNT_UNDECLARED;

            return [
                in_array(DoctorEstateResilienceVerdict::GAP_DEVICE_WITHOUT_CREDENTIAL, $gaps, true)
                    ? DoctorEstateResilienceVerdict::FAIL
                    : DoctorEstateResilienceVerdict::UNVERIFIED,
                $gaps,
            ];
        }

        if ($eligible < $stations + 1) {
            $gaps[] = DoctorEstateResilienceVerdict::GAP_NO_SPARE;

            return [DoctorEstateResilienceVerdict::FAIL, $gaps];
        }

        return [
            $gaps === []
                ? DoctorEstateResilienceVerdict::PASS
                : DoctorEstateResilienceVerdict::FAIL,
            $gaps,
        ];
    }

    /**
     * The recorded peak concurrent Doctor stations for a branch, or null when
     * nobody has counted them.
     *
     * Null is NOT zero. Zero stations would make one device a spare and turn
     * the whole gate green on a branch nobody has looked at, which is the
     * precise failure this engine was written to end.
     */
    private function declaredStations(?string $branchCode): ?int
    {
        if ($branchCode === null) {
            return null;
        }

        $declared = (array) config('android_release.enforcement.concurrent_doctor_stations_per_branch', []);
        $value = $declared[$branchCode] ?? null;

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        $stations = (int) $value;

        // A declared count below one is not a clinic. Treated as undeclared
        // rather than honoured, so a typo cannot manufacture headroom.
        return $stations >= 1 ? $stations : null;
    }

    /**
     * The named gates, each answering one question and each able to say it does
     * not know.
     *
     * @param  list<array<string,mixed>>  $branches
     * @param  list<int>  $eligibleIds
     * @param  array<string,mixed>  $fleetReport
     * @return list<array<string,mixed>>
     */
    private function gates(
        array $branches,
        array $eligibleIds,
        array $fleetReport,
        bool $snapshot,
        array $level1,
    ): array {
        $withHomeDoctors = array_values(array_filter(
            $branches,
            static fn (array $b): bool => $b['staffed'] === true,
        ));

        $strandedBranches = array_values(array_map(
            static fn (array $b): string => (string) ($b['branch_code'] ?? $b['branch_id']),
            array_filter(
                $withHomeDoctors,
                static fn (array $b): bool => (int) $b['eligible_device_count'] === 0,
            ),
        ));

        $withoutCredential = [];

        foreach ($branches as $branch) {
            foreach ($branch['eligible_devices_without_credential'] as $deviceId) {
                $withoutCredential[] = (int) $deviceId;
            }
        }

        $matrix = $fleetReport['authorization_matrix'] ?? [];

        $gates = [];

        /*
         * Generalised, never hardcoded to TLK1. A gate naming one branch code
         * is a gate that silently stops applying the day a branch is renamed or
         * a fifth one opens — and this estate has renamed a branch code once
         * already.
         */
        $gates[] = [
            'gate' => 'local_trusted_device_coverage',
            'verdict' => $withHomeDoctors === []
                ? DoctorEstateResilienceVerdict::FAIL
                : ($strandedBranches === []
                    ? DoctorEstateResilienceVerdict::PASS
                    : DoctorEstateResilienceVerdict::FAIL),
            'detail' => $withHomeDoctors === []
                ? 'No branch homes a doctor, so there is nothing to measure. An empty population is not a pass.'
                : ($strandedBranches === []
                    ? 'Every branch that homes a doctor holds at least one eligible trusted device.'
                    : 'Branches homing doctors with NO eligible local device: '.implode(', ', $strandedBranches)
                        .'. A doctor there can still authenticate on a tablet elsewhere — this is a physical '
                        .'availability gap, not an authorization gap.'),
            'stranded_branches' => $strandedBranches,
        ];

        /*
         * THE POPULATION IS STAFFED BRANCHES, AND AN EMPTY ONE FAILS.
         *
         * A spare protects a clinic day, so an unstaffed branch is not part of
         * the question — and taking the worst over ALL branches let an estate
         * made only of unstaffed branches report this gate as PASS while
         * holding zero usable tablets. The overall verdict was still FAIL
         * (local coverage catches an empty staffed population), but the NAMED
         * gate read green, and `attestation()` reads its verdict from here — so
         * a signed `true` would have been reported as no contradiction. That is
         * the exact false green this engine exists to end.
         */
        $staffedVerdicts = array_map(
            static fn (array $b): string => (string) $b['verdict'],
            $withHomeDoctors,
        );

        $gates[] = [
            'gate' => DoctorEstateResilienceVerdict::GATE_SPARE_DEVICE,
            'verdict' => DoctorEstateResilienceVerdict::worst($staffedVerdicts),
            /*
             * Names the cause it actually found. The old text said "measured
             * against the lower bound" whatever went wrong, so a branch with
             * four spares and one credential-less tablet was listed under a
             * message about spares.
             */
            'detail' => $this->spareGateDetail($withHomeDoctors)
                .'This is the config prerequisite of the same name, which until this sprint had no '
                .'implementation anywhere — only a declaration and a hand-signed boolean.',
            'branches_failing' => array_values(array_map(
                static fn (array $b): string => (string) ($b['branch_code'] ?? $b['branch_id']),
                array_filter(
                    $withHomeDoctors,
                    static fn (array $b): bool => $b['verdict'] === DoctorEstateResilienceVerdict::FAIL,
                ),
            )),

            /*
             * Listed separately because an UNVERIFIED gate used to name no
             * branch at all — the filter above matches FAIL only, so the reader
             * got a verdict they could not act on.
             */
            'branches_unverified' => array_values(array_map(
                static fn (array $b): string => (string) ($b['branch_code'] ?? $b['branch_id']),
                array_filter(
                    $withHomeDoctors,
                    static fn (array $b): bool => $b['verdict'] === DoctorEstateResilienceVerdict::UNVERIFIED,
                ),
            )),
        ];

        /*
         * LEVEL 1 — the only capacity level that gates controlled activation
         * testing, and the reason this revision exists.
         *
         * It sits BESIDE `local_trusted_device_coverage` rather than replacing
         * it, and the two are not aliases: that gate counts ELIGIBLE devices,
         * this one counts devices that can be LOGGED INTO. Level 1 is therefore
         * never greener than it, and a test pins that ordering. Renaming the
         * older gate was rejected — four documents and a test cite it, and its
         * recorded meaning is the weaker predicate it actually implements.
         */
        $unsetKnown = $level1['population_known'];
        $unsetDoctors = $level1['doctors_without_home_branch'];
        $staffedCount = count($withHomeDoctors);

        $level1Verdict = DoctorEstateCapacityLevel::toGateVerdict($level1['status']);

        $level1Short = $this->branchCodesAtLevel(
            $branches,
            DoctorEstateCapacityLevel::ACTIVATION_TEST_COVERAGE,
            [DoctorEstateCapacityLevel::FAIL],
        );

        $gates[] = [
            'gate' => DoctorEstateResilienceVerdict::GATE_ACTIVATION_TEST_COVERAGE,
            'verdict' => $level1Verdict,
            'capacity_level' => 1,
            'level_status' => $level1['status'],
            'level_status_unguarded' => $level1['status_unguarded'],
            'detail' => ($staffedCount === 0
                ? 'No branch staffs a doctor, so there is nothing to test and nothing to measure. An empty '
                    .'population is not a satisfied one. '
                : ($level1Short === []
                    ? 'Every staffed branch holds at least one trusted device that can be logged into. '
                    : 'Staffed branches with NO locally usable trusted device: '.implode(', ', $level1Short)
                        .'. A tablet counts here only if it is eligible AND carries a credential the login '
                        .'gate would admit — unrevoked AND passing the device-binding policy. '))
                .(! $unsetKnown
                    ? 'The fleet engine did not report how many doctors belong to no branch, so the '
                        .'completeness of this population is unknown and it cannot be passed. '
                    : ($unsetDoctors > 0
                        ? $unsetDoctors.' doctor(s) belong to no branch and are therefore invisible to every '
                            .'per-branch count, so this population is incomplete. '
                        : ''))
                .'This is LEVEL 1 of three: it decides whether controlled activation TESTING can begin, and '
                .'says nothing about whether a branch survives losing a tablet — that is '
                .DoctorEstateResilienceVerdict::GATE_SPARE_DEVICE.', measured separately and reported beside it.',
            'staffed_branch_count' => $staffedCount,
            'doctors_without_home_branch' => $unsetDoctors,
            'population_complete' => $unsetKnown && $unsetDoctors === 0,
            'branches_failing' => $level1Short,
        ];

        $gates[] = [
            'gate' => 'device_credential_coverage',
            'verdict' => $eligibleIds === []
                ? DoctorEstateResilienceVerdict::FAIL
                : ($withoutCredential === []
                    ? DoctorEstateResilienceVerdict::PASS
                    : DoctorEstateResilienceVerdict::FAIL),
            /*
             * THE DETAIL FOLLOWS THE VERDICT.
             *
             * This string was unconditional, so a FAILing gate printed "Every
             * eligible device carries at least one UNREVOKED credential" —
             * flatly contradicted by the list of devices printed beside it. It
             * is the same defect this sprint renamed a sibling gate to fix, one
             * gate away, and it survived that round because only the sibling
             * was being looked at.
             *
             * Says exactly what it asserts, too. UNREVOKED is the model's own
             * `isUsable()`; whether a credential is ADMISSIBLE additionally
             * requires user verification and device binding, and that policy
             * belongs to the login gate and the provisioning engine. It is
             * deliberately not restated here — a second copy of a security
             * decision is a second thing that can drift.
             */
            'detail' => ($eligibleIds === []
                ? 'No eligible device exists, so there is nothing to carry a credential. An empty population '
                    .'is not a satisfied one. '
                : ($withoutCredential === []
                    ? 'Every eligible device carries at least one UNREVOKED credential. '
                    : count($withoutCredential).' eligible device(s) carry NO unrevoked credential and cannot '
                        .'be logged into. '))
                .'Admissibility (user-verified, device-bound) is the login gate\'s decision and is not '
                .'re-implemented here; doctor:rollout-readiness reports it.',
            'eligible_devices_without_credential' => $withoutCredential,
        ];

        $gates[] = [
            'gate' => 'authorization_coverage',
            'verdict' => ((int) ($matrix['target_pairs'] ?? 0) > 0
                && (int) ($matrix['missing_pairs'] ?? 0) === 0
                && (int) ($matrix['duplicate_active_pairs'] ?? 0) === 0)
                ? DoctorEstateResilienceVerdict::PASS
                : DoctorEstateResilienceVerdict::FAIL,
            'detail' => 'Recalculated by the fleet engine against the CURRENT eligible estate, not carried '
                .'over. Growing the estate raises the target, so a new tablet makes this gate red until every '
                .'doctor is authorized on it.',
            'target_pairs' => (int) ($matrix['target_pairs'] ?? 0),
            'active_pairs' => (int) ($matrix['active_pairs'] ?? 0),
            'missing_pairs' => (int) ($matrix['missing_pairs'] ?? 0),
            'duplicate_active_pairs' => (int) ($matrix['duplicate_active_pairs'] ?? 0),
        ];

        /*
         * NAMED FOR EXACTLY WHAT IT COMPARES, WHICH IS THE ELIGIBLE DEVICE ID
         * LIST AND NOTHING ELSE.
         *
         * It was called `estate_snapshot_agreement` and its PASS message said
         * the two engines had read the same hardware. They have not been shown
         * to. Each engine issues its own lock read and its own estate read, so a
         * home lock moved, a credential revoked or an authorization granted
         * between them produces a two-moment report with a byte-identical device
         * id list — and this gate would still say PASS.
         *
         * Narrowing a verdict has to narrow its MESSAGE too, or the old claim
         * survives in the text where operators actually read it.
         */
        $gates[] = [
            'gate' => 'eligible_device_set_agreement',
            'verdict' => $snapshot
                ? DoctorEstateResilienceVerdict::PASS
                : DoctorEstateResilienceVerdict::UNVERIFIED,
            'detail' => $snapshot
                ? 'This engine and the composed fleet engine resolved an identical ELIGIBLE DEVICE ID LIST. '
                    .'That is all this compares: locks, credentials and authorizations are read separately by '
                    .'each engine and a change to them between the two reads is not detected here.'
                : 'The two engines disagreed about which devices are eligible. The estate changed mid-report, '
                    .'so these figures mix two moments — re-run before acting on them.',
        ];

        return $gates;
    }

    /**
     * LEVEL 1, resolved once, with the population guard already applied.
     *
     * A POPULATION WITH A HOLE IN IT CANNOT BE PASSED. An UNSET doctor belongs
     * to no branch, so no branch counts them and no branch is sized for them.
     * Worse, the population is SHRINKABLE: moving the last locked doctor away
     * from a branch with no tablet makes that branch unstaffed, drops it out of
     * Level 1, and flips the level green with no hardware bought and nothing in
     * the report saying the population changed.
     *
     * The unguarded status is carried alongside so a reader can see WHAT was
     * downgraded and why, rather than being handed a bare UNVERIFIED.
     *
     * @param  list<array<string,mixed>>  $branches
     * @param  array<string,mixed>  $fleetReport
     * @return array{status:string, status_unguarded:string, population_known:bool, doctors_without_home_branch:int|null}
     */
    private function activationTestCoverageLevel(array $branches, array $fleetReport): array
    {
        $raw = $this->levelStatus($branches, DoctorEstateCapacityLevel::ACTIVATION_TEST_COVERAGE);

        /*
         * FAILS CLOSED ON A MISSING KEY, not open. `?? 0` would read "the fleet
         * engine no longer publishes this" as "there is no hole", which PERMITS
         * the pass — the green direction. This module's own constant docblock
         * records the last time a silent fallthrough like that went unnoticed.
         */
        $known = array_key_exists('unset_doctor_count', $fleetReport);
        $unset = $known ? (int) $fleetReport['unset_doctor_count'] : null;

        $status = $raw;

        if ((! $known || $unset > 0) && $raw === DoctorEstateCapacityLevel::PASS) {
            $status = DoctorEstateCapacityLevel::UNVERIFIED;
        }

        return [
            'status' => $status,
            'status_unguarded' => $raw,
            'population_known' => $known,
            'doctors_without_home_branch' => $unset,
        ];
    }

    /**
     * What the activation-testing prerequisite MEASURES — the worst of the
     * three gates that decide whether a branch can be tested on.
     *
     * Level 1 asks whether a branch holds a tablet somebody could log into. It
     * does not ask whether any doctor is AUTHORIZED on it, and an unauthorized
     * tablet is a tablet nobody can use. Resolved in one place so the
     * prerequisite and the attestation cross-check cannot measure the same
     * named thing differently.
     *
     * @param  list<array<string,mixed>>  $gates
     */
    private function composedActivationMeasurement(array $gates): string
    {
        return DoctorEstateResilienceVerdict::worst(array_values(array_map(
            static fn (array $gate): string => (string) $gate['verdict'],
            array_filter($gates, static fn (array $gate): bool => in_array($gate['gate'], [
                DoctorEstateResilienceVerdict::GATE_ACTIVATION_TEST_COVERAGE,
                'device_credential_coverage',
                'authorization_coverage',
            ], true)),
        )));
    }

    /**
     * One capacity level's fleet status, taken over its own population.
     *
     * NOT_APPLICABLE rows are discarded by {@see
     * DoctorEstateCapacityLevel::worst()} rather than ranked, so an estate of
     * only unstaffed branches arrives there empty and lands on FAIL. That is
     * the false green the previous sprint shipped and then caught, and it is
     * prevented here once for all three levels rather than three times.
     *
     * @param  list<array<string,mixed>>  $branches
     */
    private function levelStatus(array $branches, string $level): string
    {
        return DoctorEstateCapacityLevel::worst(array_map(
            static fn (array $b): string => (string) $b['capacity_levels'][$level],
            $branches,
        ));
    }

    /**
     * Branch codes sitting at any of the given statuses for one level.
     *
     * @param  list<array<string,mixed>>  $branches
     * @param  list<string>  $statuses
     * @return list<string>
     */
    private function branchCodesAtLevel(array $branches, string $level, array $statuses): array
    {
        return array_values(array_map(
            static fn (array $b): string => (string) ($b['branch_code'] ?? $b['branch_id']),
            array_filter(
                $branches,
                static fn (array $b): bool => in_array((string) $b['capacity_levels'][$level], $statuses, true),
            ),
        ));
    }

    /**
     * The three levels as one block, each with its own population and its own
     * reasons.
     *
     * DELIBERATELY NOT AGGREGATED INTO A SINGLE NUMBER. Three answers that
     * disagree is the normal state of a partially provisioned estate, and
     * collapsing them is what this revision exists to undo. The one aggregate
     * that survives is `verdict`, which is the worst of every GATE and is
     * therefore never greener than Level 3.
     *
     * LEVEL 2 IS ABSENT FROM THE GATE ARRAY ON PURPOSE. It is a normal-
     * production target, not a blocker of activation testing, and rolling it
     * into `verdict` would let an incremental hardware rollout redden the
     * `--strict` exit code that CI and the runbooks read. It is reported here,
     * in the command output and in the JSON, and it decides nothing.
     *
     * @param  list<array<string,mixed>>  $branches
     * @param  array{status:string, status_unguarded:string, population_known:bool, doctors_without_home_branch:int|null}  $level1
     * @return array<string,mixed>
     */
    private function capacityLevels(array $branches, array $level1): array
    {
        $staffed = array_values(array_filter($branches, static fn (array $b): bool => $b['staffed'] === true));

        $l1 = DoctorEstateCapacityLevel::ACTIVATION_TEST_COVERAGE;
        $l2 = DoctorEstateCapacityLevel::ROOM_CAPACITY;
        $l3 = DoctorEstateCapacityLevel::FAILURE_RESILIENCE;

        /*
         * The number the owner actually needs, and it is NOT
         * `minimum_additional_devices`. That figure sizes Level 3 across every
         * staffed branch; this one counts the branches that cannot be TESTED
         * at all. Today they differ by three, and reporting only the larger one
         * is what turned "one tablet at TLK1" into "buy four".
         */
        $level1Short = $this->branchCodesAtLevel($branches, $l1, [DoctorEstateCapacityLevel::FAIL]);

        return [
            'policy' => 'Three nested requirements, measured separately and never collapsed. Satisfying a '
                .'lower level does not satisfy a higher one, and lowering what activation TESTING requires '
                .'does not lower what high availability requires.',
            'levels' => [
                [
                    'level' => 1,
                    'signal' => $l1,
                    'requirement' => '>= 1 trusted device the login gate would admit, at every staffed branch',
                    'status' => $level1['status'],
                    'status_before_population_guard' => $level1['status_unguarded'],
                    'population_known' => $level1['population_known'],
                    'doctors_without_home_branch' => $level1['doctors_without_home_branch'],
                    'gates_activation_testing' => true,
                    'branches_failing' => $level1Short,
                    'minimum_additional_branches_to_cover' => count($level1Short),
                ],
                [
                    'level' => 2,
                    'signal' => $l2,
                    'requirement' => '>= 1 trusted device per ACTIVE DOCTOR ROOM at every staffed branch '
                        .'(a lower bound: no table records chairs per room)',
                    'status' => $this->levelStatus($branches, $l2),
                    'gates_activation_testing' => false,
                    'branches_failing' => $this->branchCodesAtLevel($branches, $l2, [DoctorEstateCapacityLevel::FAIL]),
                    'branches_partial' => $this->branchCodesAtLevel($branches, $l2, [DoctorEstateCapacityLevel::PARTIAL]),
                    'branches_unverified' => $this->branchCodesAtLevel($branches, $l2, [DoctorEstateCapacityLevel::UNVERIFIED]),
                ],
                [
                    'level' => 3,
                    'signal' => $l3,
                    'requirement' => 'concurrent Doctor stations + 1 spare — survives losing one eligible device',
                    'status' => $this->levelStatus($branches, $l3),
                    'gates_activation_testing' => false,
                    'branches_failing' => $this->branchCodesAtLevel($branches, $l3, [DoctorEstateCapacityLevel::FAIL]),
                    'branches_unverified' => $this->branchCodesAtLevel($branches, $l3, [DoctorEstateCapacityLevel::UNVERIFIED]),
                ],
            ],
            'staffed_branch_count' => count($staffed),
            'staffed_branch_codes' => array_values(array_map(
                static fn (array $b): string => (string) ($b['branch_code'] ?? $b['branch_id']),
                $staffed,
            )),
        ];
    }

    /**
     * Is the ACTIVATION-TESTING prerequisite satisfied — measured AND signed?
     *
     * WHY THIS IS NOT JUST LEVEL 1. An adversarial review found the hole: Level
     * 1 asks whether a branch holds a tablet somebody could log into. It does
     * not ask whether any doctor is AUTHORIZED on that tablet, and an
     * unauthorized tablet is a tablet nobody can use. So the prerequisite is
     * the worst of Level 1, credential coverage and authorization coverage —
     * three gates already computed, composed rather than re-measured.
     *
     * AND WHY THE SIGNATURE STILL MATTERS. A measurement says the hardware is
     * there; it cannot say a human looked at the room. The prerequisite is
     * satisfied only when both hold, which is why an unsigned but measured-PASS
     * estate reports UNVERIFIED rather than PASS.
     *
     * @param  array<string,mixed>  $attestation
     * @return array<string,mixed>
     */
    private function activationTestPrerequisite(string $composed, array $attestation): array
    {
        $record = $attestation['prerequisites'][DoctorEstateResilienceVerdict::GATE_ACTIVATION_TEST_COVERAGE] ?? [];
        $attested = ($record['attested'] ?? null) === true;
        $contradiction = ($record['contradiction'] ?? false) === true;

        if ($composed !== DoctorEstateResilienceVerdict::PASS || $contradiction) {
            $status = $composed === DoctorEstateResilienceVerdict::FAIL || $contradiction
                ? DoctorEstateResilienceVerdict::FAIL
                : $composed;
        } else {
            $status = $attested
                ? DoctorEstateResilienceVerdict::PASS
                : DoctorEstateResilienceVerdict::UNVERIFIED;
        }

        return [
            'prerequisite' => DoctorEstateResilienceVerdict::GATE_ACTIVATION_TEST_COVERAGE,
            'status' => $status,
            'measured' => $composed,
            'attested' => $attested,
            'contradiction' => $contradiction,
            'composed_from' => [
                DoctorEstateResilienceVerdict::GATE_ACTIVATION_TEST_COVERAGE,
                'device_credential_coverage',
                'authorization_coverage',
            ],
            'note' => 'Reported. This engine authorises no activation and arms no flag — '
                .'`authorizes_activation` is a literal false beside it, and owner authorisation is external '
                .'governance. What a PASS here means is that the estate no longer blocks the OWNER from '
                .'deciding to begin controlled activation testing.',
        ];
    }

    /**
     * Names the cause the spare gate actually found, rather than restating the
     * lower bound whatever went wrong.
     *
     * @param  list<array<string,mixed>>  $staffed
     */
    private function spareGateDetail(array $staffed): string
    {
        if ($staffed === []) {
            return 'No branch homes a doctor, so there is no clinic day to protect and nothing to measure. '
                .'An empty population is not a satisfied one. ';
        }

        $causes = [];

        foreach ($staffed as $branch) {
            foreach ($branch['gaps'] as $gap) {
                $causes[(string) $gap] = true;
            }
        }

        if ($causes === []) {
            return 'Every branch that homes a doctor holds a spare against its recorded station count. ';
        }

        $named = [
            DoctorEstateResilienceVerdict::GAP_NO_LOCAL_DEVICE => 'a staffed branch holds no eligible device',
            DoctorEstateResilienceVerdict::GAP_NO_SPARE => 'a staffed branch holds no spare',
            DoctorEstateResilienceVerdict::GAP_DEVICE_WITHOUT_CREDENTIAL => 'an eligible device carries no '
                .'unrevoked credential',
            DoctorEstateResilienceVerdict::GAP_STATION_COUNT_UNDECLARED => 'a branch holds two or more devices '
                .'but its concurrent station count is unrecorded, so whether that is a spare is undecidable',
        ];

        $found = [];

        foreach ($named as $gap => $text) {
            if (isset($causes[$gap])) {
                $found[] = $text;
            }
        }

        return 'Measured over branches that home doctors: '.implode('; ', $found).'. ';
    }

    /**
     * @param  list<int>  $eligibleIds
     * @param  array<string,mixed>  $fleetReport
     */
    private function snapshotAgreement(array $eligibleIds, array $fleetReport): bool
    {
        $fleetIds = array_map(
            static fn (array $row): int => (int) $row['device_id'],
            $fleetReport['devices']['rows'] ?? [],
        );

        sort($fleetIds);

        return $fleetIds === $eligibleIds;
    }

    /**
     * REQUIRED_CAPACITY is reported as a RANGE with its missing input named,
     * because the owner recorded the concurrent station count as not yet
     * measured. A single number here would be an invented operational fact
     * dressed as a computed one.
     *
     * @param  list<array<string,mixed>>  $branches
     * @return array<string,mixed>
     */
    private function requiredCapacity(array $branches): array
    {
        $participating = array_values(array_filter(
            $branches,
            static fn (array $b): bool => $b['staffed'] === true,
        ));

        $minimum = 0;
        $held = 0;
        $undeclared = [];

        foreach ($participating as $branch) {
            $stations = $this->declaredStations($branch['branch_code']);

            if ($stations === null) {
                $undeclared[] = (string) ($branch['branch_code'] ?? $branch['branch_id']);
            }

            /*
             * A declared branch contributes its real requirement; an undeclared
             * one contributes only the lower bound, and is named so the total
             * is never mistaken for the answer.
             */
            $minimum += $stations === null
                ? DoctorEstateResilienceVerdict::MINIMUM_DEVICES_FOR_ANY_SPARE
                : $stations + 1;

            $held += (int) $branch['eligible_device_count'];
        }

        return [
            'basis' => 'concurrent Doctor stations + 1 spare (docs/runbooks/android-device-loss-replacement-and-decommission.md)',
            'missing_input' => 'concurrent_doctor_stations_per_branch',
            'missing_input_state' => $undeclared === [] ? 'DECLARED' : 'NOT_MEASURED',
            'branches_with_undeclared_stations' => $undeclared,
            'branches_counted' => count($participating),
            'minimum_eligible_devices_required' => $minimum,
            'eligible_devices_held' => $held,
            'minimum_additional_devices' => max(0, $minimum - $held),
            'note' => $undeclared === []
                ? 'Computed from the recorded station counts for every participating branch.'
                : 'A LOWER BOUND, not the requirement. Branches with no recorded station count contribute '
                    .'only the two devices any staffed branch needs; one running two chairs at once needs '
                    .'three. Record the counts in '
                    .'android_release.enforcement.concurrent_doctor_stations_per_branch to resolve them.',
        ];
    }

    /**
     * What the estate looks like the morning after one tablet is lost.
     *
     * @param  list<array<string,mixed>>  $branches
     * @return array<string,mixed>
     */
    private function failureDomain(array $branches): array
    {
        $survives = [];
        $stops = [];

        foreach ($branches as $branch) {
            if ($branch['staffed'] !== true) {
                continue;
            }

            $code = (string) ($branch['branch_code'] ?? $branch['branch_id']);

            if ($branch['serviceable_after_one_device_loss']) {
                $survives[] = $code;

                continue;
            }

            $stops[] = [
                'branch_code' => $code,
                'home_doctors_affected' => (int) $branch['home_doctor_count'],
                'eligible_devices' => (int) $branch['eligible_device_count'],
            ];
        }

        return [
            'scenario' => 'loss_or_revocation_of_one_eligible_device',
            'branches_serviceable_after_one_loss' => $survives,
            'branches_that_stop' => $stops,
            'home_doctors_affected_total' => array_sum(array_map(
                static fn (array $row): int => $row['home_doctors_affected'],
                $stops,
            )),
        ];
    }

    /**
     * What is SIGNED beside what is MEASURED.
     *
     * The owner chose measurement over coupling and that still holds in the
     * direction it was chosen for: this engine does not require an attestation
     * and cannot fail merely because one is absent. REVISION-DOCTOR-TRUSTED-
     * DEVICE-ESTATE-CAPACITY-POLICY-1 narrowed the other direction — an
     * attestation nobody ever compares to reality is the failure mode the whole
     * prerequisite list exists to prevent, so a signature that CONTRADICTS a
     * measurement fails {@see self::attestationGate()}. Measurement wins; it is
     * never overridden by what somebody signed.
     *
     * @param  list<array<string,mixed>>  $gates
     * @return array<string,mixed>
     */
    private function attestation(array $gates, string $composedActivation): array
    {
        $verdicts = [];

        foreach ($gates as $gate) {
            $verdicts[(string) $gate['gate']] = (string) $gate['verdict'];
        }

        /*
         * THE SIGNATURE IS COMPARED AGAINST THE QUESTION IT NAMES.
         *
         * `activation_test_prerequisites_attested` signs the PREREQUISITE, which
         * composes Level 1 with credential and authorization coverage. Reading
         * the single Level-1 gate here let a signature sit beside a FAILING
         * prerequisite and be reported as agreement.
         */
        $verdicts[DoctorEstateResilienceVerdict::GATE_ACTIVATION_TEST_COVERAGE] = $composedActivation;

        $prerequisites = [];

        foreach ([
            DoctorEstateResilienceVerdict::GATE_SPARE_DEVICE => DoctorEstateResilienceVerdict::CONFIG_GLOBAL_PREREQUISITES_ATTESTED,
            DoctorEstateResilienceVerdict::GATE_ACTIVATION_TEST_COVERAGE => DoctorEstateResilienceVerdict::CONFIG_ACTIVATION_TEST_ATTESTED,
        ] as $gateKey => $configPath) {
            /*
             * KEYED BY THE GATE CONSTANT, never by a literal repeated here.
             * The previous form hardcoded the config path AND the key, so a
             * rename of the gate would have left `attestation()` reading a
             * key nobody publishes — falling through to a default that is
             * `!== PASS`, which the contradiction test also accepts. Silent in
             * exactly the way this class exists to prevent.
             */
            $signed = (array) config($configPath, []);
            $present = array_key_exists($gateKey, $signed);
            $measured = $verdicts[$gateKey] ?? DoctorEstateResilienceVerdict::UNVERIFIED;
            $attested = ($signed[$gateKey] ?? null) === true;

            $prerequisites[$gateKey] = [
                'prerequisite' => $gateKey,
                'config_path' => $configPath,
                'measured' => $measured,
                'attested' => $attested,
                'signature_recorded' => $present,

                /*
                 * A SIGNATURE MAY NOT STAND AGAINST A MEASUREMENT.
                 *
                 * Both halves are contradictions. `attested true` beside a
                 * measurement that is not PASS records something the estate
                 * says is untrue. An ABSENT key is the other half: the
                 * prerequisite is declared and its slot does not exist, which
                 * reads identically to "nobody has signed yet" and is in fact
                 * "the list and the signatures have drifted apart". Only one of
                 * those can be fixed by signing.
                 */
                'contradiction' => $attested && $measured !== DoctorEstateResilienceVerdict::PASS,
                'signature_slot_missing' => $this->prerequisiteDeclared($gateKey) && ! $present,
            ];
        }

        $contradictions = array_values(array_map(
            static fn (array $row): string => (string) $row['prerequisite'],
            array_filter(
                $prerequisites,
                static fn (array $row): bool => $row['contradiction'] === true || $row['signature_slot_missing'] === true,
            ),
        ));

        return [
            /*
             * RETAINED AT THE TOP LEVEL, and still the SPARE prerequisite.
             *
             * `attestation.measured` / `.attested` / `.contradiction` were the
             * shipped shape and are cited by a test and by the command. They go
             * on meaning what they meant — Level 3 — so nothing that reads them
             * quietly starts reading a different, weaker question. The
             * per-prerequisite block beside them is where the second one lives.
             */
            'prerequisite' => DoctorEstateResilienceVerdict::GATE_SPARE_DEVICE,
            'measured' => $prerequisites[DoctorEstateResilienceVerdict::GATE_SPARE_DEVICE]['measured'],
            'attested' => $prerequisites[DoctorEstateResilienceVerdict::GATE_SPARE_DEVICE]['attested'],
            'contradiction' => $prerequisites[DoctorEstateResilienceVerdict::GATE_SPARE_DEVICE]['contradiction'],
            'prerequisites' => $prerequisites,
            'contradicting_prerequisites' => $contradictions,
            'note' => 'This engine still neither signs nor blocks a signature, and the ABSENCE of a signature '
                .'fails nothing here. What changed in REVISION-DOCTOR-TRUSTED-DEVICE-ESTATE-CAPACITY-POLICY-1 '
                .'is narrower: a signature that CONTRADICTS a measurement now fails the '
                .DoctorEstateResilienceVerdict::GATE_ATTESTATION_NO_CONTRADICTION.' gate. Measurement is never '
                .'overridden by what somebody signed.',
        ];
    }

    /**
     * The one gate an attestation can fail.
     *
     * NAMED FOR EXACTLY WHAT IT DECIDES. `attestation_consistency` would have
     * read as "the attestations are in order", and this gate PASSES on an
     * estate where nothing at all is signed — which is today's estate. Its
     * detail says so rather than letting a green row imply a satisfied
     * prerequisite; that is the defect the previous sprint found twice, one
     * gate apart, and the discipline is applied here to the NAME as well as the
     * message.
     *
     * @param  array<string,mixed>  $attestation
     * @return array<string,mixed>
     */
    private function attestationGate(array $attestation): array
    {
        $contradicting = (array) ($attestation['contradicting_prerequisites'] ?? []);

        $signed = array_values(array_map(
            static fn (array $row): string => (string) $row['prerequisite'],
            array_filter(
                (array) ($attestation['prerequisites'] ?? []),
                static fn (array $row): bool => $row['attested'] === true,
            ),
        ));

        return [
            'gate' => DoctorEstateResilienceVerdict::GATE_ATTESTATION_NO_CONTRADICTION,
            'verdict' => $contradicting === []
                ? DoctorEstateResilienceVerdict::PASS
                : DoctorEstateResilienceVerdict::FAIL,
            'detail' => ($contradicting === []
                ? ($signed === []
                    ? 'No estate prerequisite is attested, so no signature can contradict a measurement. '
                        .'This gate is PASS because there is nothing to contradict — it is NOT a statement '
                        .'that any prerequisite is satisfied. '
                    : 'Signed: '.implode(', ', $signed).'. Each agrees with what this engine measured. ')
                : 'A recorded signature disagrees with the measured estate, or a declared prerequisite has no '
                    .'signature slot at all: '.implode(', ', $contradicting).'. ')
                .'Measurement is never overridden by a signature. The ABSENCE of a signature fails nothing '
                .'here; only a signature that stands against a measurement does.',
            'contradicting_prerequisites' => array_values($contradicting),
            'attested_prerequisites' => $signed,
        ];
    }

    /**
     * Is this prerequisite declared in a list somewhere, so that a missing
     * signature slot is drift rather than an option nobody uses?
     */
    private function prerequisiteDeclared(string $gateKey): bool
    {
        $declared = array_merge(
            (array) config('android_release.enforcement.global_prerequisites', []),
            (array) config(DoctorEstateResilienceVerdict::CONFIG_ACTIVATION_TEST_PREREQUISITES, []),
        );

        return in_array($gateKey, array_map(static fn ($name): string => (string) $name, $declared), true);
    }

    /**
     * @param  list<array<string,mixed>>  $branches
     * @param  Collection<int, DoctorDevice>  $estate
     * @param  array<string,mixed>  $fleetReport
     * @return list<array<string,mixed>>
     */
    private function findings(array $branches, Collection $estate, bool $snapshot, array $fleetReport): array
    {
        $findings = [];

        if (! $snapshot) {
            $findings[] = [
                'finding' => 'eligible_device_set_disagreement',
                'detail' => 'The composed fleet engine resolved a different eligible device set than this one.',
            ];
        }

        /*
         * A home lock naming a doctor who no longer exists. Excluded from the
         * counts above so it cannot inflate a requirement, but reported rather
         * than swallowed: it is a real row somebody has to clean up, and a
         * silent exclusion is how a branch's doctor count drifts from the fleet
         * engine's without anybody noticing.
         */
        if ($this->orphanHomeLockBranchIds !== []) {
            $findings[] = [
                'finding' => 'home_lock_names_a_doctor_that_no_longer_exists',
                'branch_ids' => $this->orphanHomeLockBranchIds,
                'detail' => 'Soft-deleted doctors still hold home lock rows. They are NOT counted as home '
                    .'doctors here, so they neither strand a branch nor inflate its requirement. A branch '
                    .'reachable ONLY through such locks holds no hardware and no live doctor, so it has no '
                    .'row in the table above — the id here may be the only place it appears.',
            ];
        }

        /*
         * A branch whose code will not resolve — soft-deleted, most likely.
         * `concurrent_doctor_stations_per_branch` is keyed by CODE, so such a
         * branch can never have its station count declared and is pinned at
         * UNVERIFIED or FAIL until somebody notices. Reported because a numeric
         * id in a table is not a thing an operator can act on.
         */
        $unresolvable = array_values(array_map(
            static fn (array $b): int => (int) $b['branch_id'],
            array_filter($branches, static fn (array $b): bool => $b['branch_code'] === null),
        ));

        if ($unresolvable !== []) {
            $findings[] = [
                'finding' => 'branch_code_unresolvable',
                'branch_ids' => $unresolvable,
                'detail' => 'A branch in the estate has no resolvable code (soft-deleted, most likely). Its '
                    .'station count cannot be declared, because that map is keyed by code.',
            ];
        }

        /*
         * Doctors with no home lock at all. The composed report already knows
         * this; discarding a signal we are holding is how the estate view and
         * the fleet view end up telling an operator different stories.
         */
        $unset = (int) ($fleetReport['unset_doctor_count'] ?? 0);

        if ($unset > 0) {
            $findings[] = [
                'finding' => 'doctors_without_a_home_branch',
                'count' => $unset,
                'detail' => 'These doctors belong to no branch in this report, so no branch counts them and no '
                    .'branch is sized for them. Reported by the fleet engine as UNSET.',
            ];
        }

        foreach ($branches as $branch) {
            if ((int) $branch['home_doctor_count'] === 0 && (int) $branch['eligible_device_count'] > 0) {
                $findings[] = [
                    'finding' => 'eligible_device_at_branch_homing_no_doctor',
                    'branch_code' => $branch['branch_code'],
                    'eligible_device_ids' => $branch['eligible_device_ids'],
                    'detail' => 'Hardware sits where no doctor is homed. Not a fault — cross-branch use is '
                        .'valid — but it is idle capacity while another branch has none.',
                ];
            }
        }

        /*
         * THE ROOM-SET DIVERGENCE, REPORTED RATHER THAN INHERITED.
         *
         * The repository returns two counts and says the point of returning
         * them together is that the disagreement gets reported. It was computed
         * and then read by nothing — a stated justification with no
         * implementation behind it, which is its own small false claim.
         *
         * The divergence is real and operational: `ClinicVisitService::
         * activeRoomsForBranch()` offers the room gate every ACTIVE room at the
         * branch with no type clause, so a doctor can be placed in a
         * sterilization or x-ray room that Level 2 did not size a tablet for.
         */
        foreach ($branches as $branch) {
            if ($branch['staffed'] !== true || $branch['room_profile_known'] !== true) {
                continue;
            }

            $extra = (int) $branch['active_assignable_rooms'] - (int) $branch['active_doctor_rooms'];

            if ($extra > 0) {
                $findings[] = [
                    'finding' => 'assignable_room_a_doctor_could_be_placed_in_is_not_counted_by_level_2',
                    'branch_code' => $branch['branch_code'],
                    'active_doctor_rooms' => (int) $branch['active_doctor_rooms'],
                    'active_assignable_rooms' => (int) $branch['active_assignable_rooms'],
                    'detail' => $extra.' active room(s) at this branch are neither treatment nor consultation '
                        .'rooms, so Level 2 does not size a tablet for them — but the room-assignment gate '
                        .'offers every active room whatever its type, so a doctor CAN be placed in one. '
                        .'Either the room is mistyped or the assignment path is wider than it should be.',
                ];
            }
        }

        $revoked = $estate->filter(fn (DoctorDevice $d): bool => $d->isRevoked())->count();

        if ($revoked > 0) {
            $findings[] = [
                'finding' => 'revoked_devices_present_in_estate',
                'count' => $revoked,
                'detail' => 'Counted in the estate total and contributing NOTHING to capacity. A revoked '
                    .'device is never brought back — a repaired unit enrols as a new device.',
            ];
        }

        return $findings;
    }

    /**
     * @param  Collection<int, DoctorDevice>  $estate
     * @return list<array<string,mixed>>
     */
    private function deviceDetail(Collection $estate): array
    {
        return $estate
            ->map(fn (DoctorDevice $device): array => [
                'device_id' => (int) $device->id,
                'device_name' => (string) $device->device_name,
                'physical_branch_code' => $device->branch?->code === null ? null : (string) $device->branch->code,
                'active' => $device->isActive(),
                'cryptographically_verified' => $device->isCryptographicallyVerified(),
                'revoked' => $device->isRevoked(),
                'eligible' => $this->isEligible($device),
                'unrevoked_credential_count' => $this->unrevokedCredentialCount($device),
                'unrevoked_credentials_reporting_device_bound' => $this->deviceBoundCredentialCount($device),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, DoctorDevice>  $estate
     * @param  list<int>  $eligibleIds
     * @return array<string,mixed>
     */
    private function estateTotals(Collection $estate, array $eligibleIds): array
    {
        return [
            'total_devices' => $estate->count(),
            'eligible_devices' => count($eligibleIds),
            'eligible_device_ids' => $eligibleIds,
            'revoked_devices' => $estate->filter(fn (DoctorDevice $d): bool => $d->isRevoked())->count(),
        ];
    }

    /**
     * ELIGIBLE is asked of ONE POLICY — active AND holding an accepted identity
     * proof — exactly as the provisioning and fleet engines ask it. Restating
     * it here is how three engines end up disagreeing about which hardware
     * counts.
     *
     * REVISION-DOCTOR-PWA-WEBAUTHN-ONLY-ACCESS-1: the identity half used to be
     * `isCryptographicallyVerified()`, the Android keystore flag. That made a
     * PWA-only tablet permanently ineligible, which mattered most HERE —
     * `spare_device_available_per_branch` is computed from this predicate, so
     * no quantity of new WebAuthn-only hardware could ever have satisfied it.
     */
    private function isEligible(DoctorDevice $device): bool
    {
        return $device->isActive() && $this->identityProof->acceptable($device);
    }

    /**
     * Credentials the LOGIN GATE would admit on this device.
     *
     * NOT A THIRD COPY OF A SECURITY DECISION. The binding half is asked of
     * `WebAuthnDeviceBinding::isAcceptable()` — the same policy-aware helper
     * `DoctorAppLoginGate` itself calls, which returns true when device binding
     * is not required and demands a device-bound verdict when it is. If that
     * policy tightens, this count tightens with it, because there is one
     * implementation and both callers ask it.
     *
     * The narrower question `doctor:rollout-readiness` asks — user verification
     * and backup eligibility as well — is deliberately NOT restated here: it
     * belongs to the provisioning engine, and this count claims only what the
     * login gate admits.
     */
    private function loginAdmissibleCredentialCount(DoctorDevice $device): int
    {
        return $this->credentials($device)
            ->filter(fn (DoctorDeviceWebAuthnCredential $c): bool => $c->isUsable()
                && WebAuthnDeviceBinding::isAcceptable((string) $c->device_bound_verdict))
            ->count();
    }

    private function unrevokedCredentialCount(DoctorDevice $device): int
    {
        return $this->credentials($device)
            ->filter(fn (DoctorDeviceWebAuthnCredential $c): bool => $c->isUsable())
            ->count();
    }

    /**
     * Unrevoked credentials whose STORED VERDICT says device-bound.
     *
     * A FRAGMENT of admissibility, not admissibility: the login gate also
     * requires `user_verified === true` and `backup_eligible === false`, and
     * those checks are deliberately not copied here. Named at length because
     * `device_bound_credential_count` read like "this tablet can be logged into"
     * and is not that.
     */
    private function deviceBoundCredentialCount(DoctorDevice $device): int
    {
        return $this->credentials($device)
            ->filter(fn (DoctorDeviceWebAuthnCredential $c): bool => $c->isUsable() && $c->isDeviceBound())
            ->count();
    }

    /**
     * @return Collection<int, DoctorDeviceWebAuthnCredential>
     */
    private function credentials(DoctorDevice $device): Collection
    {
        $credentials = $device->webAuthnCredentials;

        return $credentials instanceof Collection ? $credentials : collect();
    }

    /**
     * @param  Collection<int, DoctorDevice>  $estate
     * @return list<int>
     */
    private function eligibleDeviceIds(Collection $estate): array
    {
        $ids = $estate
            ->filter(fn (DoctorDevice $device): bool => $this->isEligible($device))
            ->map(fn (DoctorDevice $device): int => (int) $device->id)
            ->values()
            ->all();

        sort($ids);

        return $ids;
    }

    /**
     * Branch ids whose home lock names a doctor that no longer exists.
     *
     * @var list<int>
     */
    private array $orphanHomeLockBranchIds = [];

    /** @var Collection<int, DoctorDevice>|null */
    private ?Collection $deviceEstate = null;

    /**
     * @return Collection<int, DoctorDevice>
     */
    private function deviceEstate(): Collection
    {
        return $this->deviceEstate ??= $this->estate->deviceEstate();
    }
}
