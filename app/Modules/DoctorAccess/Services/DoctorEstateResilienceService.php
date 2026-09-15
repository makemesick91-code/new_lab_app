<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Services;

use App\Modules\DoctorAccess\Interfaces\DoctorBranchLockRepositoryInterface;
use App\Modules\DoctorAccess\Interfaces\DoctorEstateResilienceRepositoryInterface;
use App\Modules\DoctorAccess\Models\DoctorBranchLock;
use App\Modules\DoctorAccess\Support\DoctorEstateResilienceVerdict;
use App\Modules\DoctorDevice\Interfaces\DoctorDeviceRolloutReadinessRepositoryInterface;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
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
 * WHAT IT DOES NOT DO. It does not attest, does not write, does not arm, does
 * not widen a cohort, and does not gate the attestation block — the owner chose
 * measurement over coupling, so a contradiction between what is measured here
 * and what is signed there is reported as a FINDING and changes no verdict.
 * Signing remains a human act with a human's name on it.
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

        $branches = $this->branchMatrix($estate, $locks, $rooms);

        /*
         * The fleet engine owns the (doctor x eligible tablet) reconciliation.
         * It is COMPOSED rather than re-implemented: a second authorization
         * calculator is a second thing to keep in step, and this programme has
         * already paid for one predicate written down twice.
         */
        $fleetReport = $this->fleet->build();

        $eligibleIds = $this->eligibleDeviceIds($estate);
        $snapshot = $this->snapshotAgreement($eligibleIds, $fleetReport);

        $gates = $this->gates($branches, $eligibleIds, $fleetReport, $snapshot);

        return [
            'verdict' => DoctorEstateResilienceVerdict::worst(
                array_map(static fn (array $gate): string => $gate['verdict'], $gates),
            ),
            'branch_scope' => DoctorEstateResilienceVerdict::BRANCH_SCOPE,
            'gates' => $gates,
            'branches' => $branches,
            'devices' => $this->deviceDetail($estate),
            'estate_totals' => $this->estateTotals($estate, $eligibleIds),
            'authorization_matrix' => $fleetReport['authorization_matrix'],
            'failure_domain' => $this->failureDomain($branches),
            'required_capacity' => $this->requiredCapacity($branches),
            'attestation' => $this->attestation($gates),
            'findings' => $this->findings($branches, $estate, $snapshot, $fleetReport),
            'runtime' => $fleetReport['runtime'],

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
     * @return list<array<string,mixed>>
     */
    private function branchMatrix(Collection $estate, Collection $locks, Collection $rooms): array
    {
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
        }

        unset($row);

        $rows = [];

        foreach ($branches as $branchId => $row) {
            $row['home_doctor_count'] = $homeCounts[$branchId] ?? 0;

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
            'active_treatment_rooms' => null,
        ];
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
         * standing a tablet in an unstaffed room made the gate worse. Worse, it
         * would have held `spare_device_available_per_branch` at FAIL forever —
         * after every staffed branch was fully provisioned — over a branch that
         * cannot strand anyone. Caught while charting the measured estate, where
         * the two rows sat next to each other.
         *
         * It also keeps the gate consistent with `requiredCapacity()`, which has
         * counted only branches homing doctors from the start.
         */
        if ((int) $row['home_doctor_count'] === 0) {
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
    private function gates(array $branches, array $eligibleIds, array $fleetReport, bool $snapshot): array
    {
        $withHomeDoctors = array_values(array_filter(
            $branches,
            static fn (array $b): bool => (int) $b['home_doctor_count'] > 0,
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

        $matrix = $fleetReport['authorization_matrix'];

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
            'gate' => 'spare_device_available_per_branch',
            'verdict' => DoctorEstateResilienceVerdict::worst($staffedVerdicts),
            'detail' => ($withHomeDoctors === []
                ? 'No branch homes a doctor, so there is no clinic day to protect and nothing to measure. '
                    .'An empty population is not a satisfied one. '
                : 'Measured over branches that home doctors, against the lower bound (one concurrent station '
                    .'needs two eligible devices). ')
                .'This is the config prerequisite of the same name, which until this sprint had no '
                .'implementation anywhere — only a declaration and a hand-signed boolean.',
            'branches_failing' => array_values(array_map(
                static fn (array $b): string => (string) ($b['branch_code'] ?? $b['branch_id']),
                array_filter(
                    $withHomeDoctors,
                    static fn (array $b): bool => $b['verdict'] === DoctorEstateResilienceVerdict::FAIL,
                ),
            )),
        ];

        $gates[] = [
            'gate' => 'device_credential_coverage',
            'verdict' => $eligibleIds === []
                ? DoctorEstateResilienceVerdict::FAIL
                : ($withoutCredential === []
                    ? DoctorEstateResilienceVerdict::PASS
                    : DoctorEstateResilienceVerdict::FAIL),
            /*
             * Says exactly what it asserts. UNREVOKED is the model's own
             * `isUsable()`; whether a credential is ADMISSIBLE additionally
             * requires user verification and device binding, and that policy
             * belongs to the login gate and the provisioning engine. It is
             * deliberately not restated here — a second copy of a security
             * decision is a second thing that can drift.
             */
            'detail' => 'Every eligible device carries at least one UNREVOKED credential. Admissibility '
                .'(user-verified, device-bound) is the login gate\'s decision and is not re-implemented here; '
                .'doctor:rollout-readiness reports it.',
            'eligible_devices_without_credential' => $withoutCredential,
        ];

        $gates[] = [
            'gate' => 'authorization_coverage',
            'verdict' => ((int) $matrix['target_pairs'] > 0
                && (int) $matrix['missing_pairs'] === 0
                && (int) $matrix['duplicate_active_pairs'] === 0)
                ? DoctorEstateResilienceVerdict::PASS
                : DoctorEstateResilienceVerdict::FAIL,
            'detail' => 'Recalculated by the fleet engine against the CURRENT eligible estate, not carried '
                .'over. Growing the estate raises the target, so a new tablet makes this gate red until every '
                .'doctor is authorized on it.',
            'target_pairs' => (int) $matrix['target_pairs'],
            'active_pairs' => (int) $matrix['active_pairs'],
            'missing_pairs' => (int) $matrix['missing_pairs'],
            'duplicate_active_pairs' => (int) $matrix['duplicate_active_pairs'],
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
            static fn (array $b): bool => (int) $b['home_doctor_count'] > 0,
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
            if ((int) $branch['home_doctor_count'] === 0) {
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
     * What is SIGNED beside what is MEASURED — reported, never enforced.
     *
     * The owner chose measurement over coupling: this engine does not gate the
     * attestation block and cannot fail because of it. What it will not do is
     * stay silent when the two disagree, because an attestation nobody ever
     * compares to reality is the failure mode the whole prerequisite list
     * exists to prevent.
     *
     * @param  list<array<string,mixed>>  $gates
     * @return array<string,mixed>
     */
    private function attestation(array $gates): array
    {
        $measured = DoctorEstateResilienceVerdict::UNVERIFIED;

        foreach ($gates as $gate) {
            if ($gate['gate'] === 'spare_device_available_per_branch') {
                $measured = (string) $gate['verdict'];
            }
        }

        $attested = config('android_release.enforcement.global_prerequisites_attested.spare_device_available_per_branch');

        return [
            'prerequisite' => 'spare_device_available_per_branch',
            'measured' => $measured,
            'attested' => $attested === true,
            'contradiction' => $attested === true && $measured !== DoctorEstateResilienceVerdict::PASS,
            'note' => 'Informational. Signing remains a human act recorded in source control; this engine '
                .'neither signs nor blocks a signature.',
        ];
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
                    .'doctors here, so they neither strand a branch nor inflate its requirement.',
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
     * ELIGIBLE is asked of the MODEL — active AND cryptographically verified —
     * exactly as the provisioning and fleet engines ask it. Restating it as a
     * status string here is how three engines end up disagreeing about which
     * hardware counts.
     */
    private function isEligible(DoctorDevice $device): bool
    {
        return $device->isActive() && $device->isCryptographicallyVerified();
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
