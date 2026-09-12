<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Services;

use App\Modules\DoctorAccess\Interfaces\DoctorBranchLockRepositoryInterface;
use App\Modules\DoctorAccess\Interfaces\DoctorFleetReadinessRepositoryInterface;
use App\Modules\DoctorAccess\Models\DoctorBranchLock;
use App\Modules\DoctorAccess\Support\DoctorFleetReadinessVerdict;
use App\Modules\DoctorDevice\Interfaces\DoctorDeviceRolloutReadinessRepositoryInterface;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Services\DoctorGlobalRolloutReadinessService;
use Illuminate\Support\Collection;

/**
 * DOCTOR-ACCESS-FLEET-ROLLOUT-READINESS-1 — is the fleet ready for a later
 * activation decision?
 *
 * THIS IS NOT THE PROVISIONING ENGINE AND MUST NEVER BECOME A SECOND COPY OF
 * IT. DoctorGlobalRolloutReadinessService owns the five-condition trusted path
 * (rule 152, GR-R4) and is composed here verbatim — its per-doctor state and
 * its reason codes are carried through, never recomputed. Two engines that both
 * decide what a trusted path is will eventually disagree, and the one nobody is
 * reading will be the one that is right.
 *
 * WHAT THIS ADDS, AND WHY PROVISIONING ALONE IS NOT READINESS.
 *
 * On 2026-09-12 a bulk authorization run wrote 45 correct rows. The provisioning
 * engine went from 3 ready doctors to 15 and from PARTIAL to its top verdict,
 * while twelve clinicians who had never once logged in on a tablet stayed
 * exactly as unready as the day before. Nothing was miscounted. The measurement
 * simply did not include the two things that separate "a path exists" from "a
 * doctor can work":
 *
 *   1. A HOME BRANCH. Owner decision O1 starts every doctor UNSET and forbids
 *      inferring one. UNSET is a legitimate running state and a disqualifying
 *      readiness state, and only an approved assignment clears it.
 *   2. A LOGIN THAT ACTUALLY HAPPENED. A provisioned path is a hypothesis until
 *      a clinician walks it.
 *
 * HOW HONEST THIS CAN BE ABOUT WHAT A PROOF MEANS. A device assertion proves
 * the TABLET was present and its screen lock was passed. The clinician is
 * identified by the password step before it, never by the credential — the
 * credential has no doctor column and deliberately never will (GR-R5). So a
 * proof here means "this account reached a clinical session through this
 * tablet", which is exactly what an operator needs and is strictly less than
 * "this human was at this tablet". The report says so in those words rather
 * than letting a reader assume the stronger claim.
 *
 * READ-ONLY, and structurally so — a companion test scans this file for the
 * primitives that could write, spawn, fetch or read a request, the same guard
 * the provisioning engine carries. It also cannot activate anything: it never
 * writes a flag, never touches the enforcement scope, and never widens the
 * pilot cohort. A readiness verdict authorises a later DECISION and nothing
 * else (GR-R1, GR-R2).
 */
class DoctorFleetReadinessService
{
    public function __construct(
        private readonly DoctorGlobalRolloutReadinessService $provisioning,
        private readonly DoctorBranchLockRepositoryInterface $locks,
        private readonly DoctorFleetReadinessRepositoryInterface $evidence,
        private readonly DoctorDeviceRolloutReadinessRepositoryInterface $estate,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(): array
    {
        $provisioning = $this->provisioning->build();

        /** @var list<array<string,mixed>> $provisionedDoctors */
        $provisionedDoctors = $provisioning['doctors'] ?? [];

        $userIds = array_values(array_map(
            static fn (array $row): int => (int) $row['user_id'],
            $provisionedDoctors,
        ));

        $locks = $this->locks
            ->withDoctorAndBranch()
            ->keyBy(fn (DoctorBranchLock $lock): int => (int) $lock->doctor_id);

        $proof = $this->evidence->deviceLoginProofForUsers($userIds);

        /*
         * Reconciled here from the estate, INDEPENDENTLY of the bulk
         * authorization tool that writes these rows. A tool that both writes
         * the matrix and reports it complete is one bug away from agreeing
         * with itself about a gap that is really there.
         */
        $eligibleDeviceIds = $this->eligibleDeviceIds();

        $doctorIds = array_values(array_filter(array_map(
            static fn (array $row): ?int => $row['doctor_id'] === null ? null : (int) $row['doctor_id'],
            $provisionedDoctors,
        ), static fn (?int $id): bool => $id !== null));

        $activePairs = $this->activeAuthorizationPairs($doctorIds, $eligibleDeviceIds);

        $doctors = array_map(
            fn (array $row): array => $this->doctorRow($row, $locks, $proof, $activePairs, $eligibleDeviceIds),
            $provisionedDoctors,
        );

        $ready = array_values(array_filter(
            $doctors,
            static fn (array $row): bool => $row['state'] === DoctorFleetReadinessVerdict::STATE_READY,
        ));

        $devices = $this->deviceCoverage($proof);
        $findings = $this->findings($doctors, $devices);

        return [
            'verdict' => $this->verdict($doctors, $ready, $devices),
            'eligible_doctor_count' => count($doctors),
            'locked_doctor_count' => $this->countWithout($doctors, DoctorFleetReadinessVerdict::BLOCKER_HOME_BRANCH_UNSET),
            'unset_doctor_count' => $this->countWith($doctors, DoctorFleetReadinessVerdict::BLOCKER_HOME_BRANCH_UNSET),
            'real_device_ready_doctor_count' => $this->countWithout($doctors, DoctorFleetReadinessVerdict::BLOCKER_LOGIN_NOT_PROVEN),
            'real_device_not_ready_doctor_count' => $this->countWith($doctors, DoctorFleetReadinessVerdict::BLOCKER_LOGIN_NOT_PROVEN),
            'fleet_ready_doctor_count' => count($ready),
            'fleet_ready_doctor_user_ids' => array_map(static fn (array $r): int => $r['user_id'], $ready),
            'doctors' => $doctors,
            'blocker_tally' => $this->blockerTally($doctors),
            'home_branch_matrix' => $this->homeBranchMatrix($doctors),
            'authorization_matrix' => $this->authorizationMatrix($doctors, $eligibleDeviceIds),
            'devices' => $devices,
            'findings' => $findings,
            /*
             * Carried through, never re-derived. An operator comparing the two
             * reports is entitled to see the provisioning engine's own numbers
             * rather than this engine's paraphrase of them.
             */
            'provisioning' => [
                'verdict' => $provisioning['verdict'] ?? null,
                'ready_doctor_count' => $provisioning['ready_doctor_count'] ?? 0,
                'not_ready_doctor_count' => $provisioning['not_ready_doctor_count'] ?? 0,
                'blocking_reasons' => $provisioning['blocking_reasons'] ?? [],
                'findings' => $provisioning['findings'] ?? [],
            ],
            'runtime' => $provisioning['runtime'] ?? [],
            /*
             * Stated in the payload, not left to a reader's assumption. Every
             * consumer of this report — including a future activation sprint —
             * sees the limit of the claim in the same breath as the verdict.
             */
            'proof_semantics' => 'A device proof records that this ACCOUNT reached a clinical session through this TABLET. '
                .'The clinician is identified by the password step; the device assertion proves hardware presence and '
                .'screen-lock passage, never which human was standing there.',
            'authorizes_activation' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $row  a provisioning row, carried through
     * @param  Collection<int, DoctorBranchLock>  $locks  keyed by doctor id
     * @param  Collection<int, array{count:int,last_at:string|null,actions:list<string>,device_ids:list<int>}>  $proof
     * @param  array<int, list<int>>  $activePairs  eligible device ids holding an ACTIVE authorization, keyed by doctor id
     * @param  list<int>  $eligibleDeviceIds
     * @return array<string,mixed>
     */
    private function doctorRow(
        array $row,
        Collection $locks,
        Collection $proof,
        array $activePairs,
        array $eligibleDeviceIds,
    ): array {
        $userId = (int) $row['user_id'];
        $doctorId = $row['doctor_id'] === null ? null : (int) $row['doctor_id'];

        $blockers = [];

        if (($row['state'] ?? null) !== DoctorGlobalRolloutReadinessService::STATE_READY) {
            $blockers[] = DoctorFleetReadinessVerdict::BLOCKER_PATH_INCOMPLETE;
        }

        $authorizedDeviceIds = $doctorId === null ? [] : ($activePairs[$doctorId] ?? []);
        $missingDeviceIds = array_values(array_diff($eligibleDeviceIds, $authorizedDeviceIds));

        /*
         * With no eligible device at all there is nothing to be authorized ON,
         * and an empty diff would read as a complete matrix. The fleet-level
         * NOTHING_TO_MEASURE finding and the NO-GO verdict carry that case;
         * this blocker stays silent rather than claiming a row is complete.
         */
        if ($missingDeviceIds !== []) {
            $blockers[] = DoctorFleetReadinessVerdict::BLOCKER_AUTHORIZATION_GAP;
        }

        /*
         * A doctor with no linked record has no doctor_id to look a lock up by.
         * That is UNSET, not unknown — the same blocker, reached from a
         * different direction. Treating it as "no opinion" would let an
         * unlinked account pass the branch gate by having nothing to check.
         */
        $lock = $doctorId === null ? null : $locks->get($doctorId);

        if (! $lock instanceof DoctorBranchLock) {
            $blockers[] = DoctorFleetReadinessVerdict::BLOCKER_HOME_BRANCH_UNSET;
        }

        $evidence = $proof->get($userId);

        if (! is_array($evidence) || ($evidence['count'] ?? 0) < 1) {
            $blockers[] = DoctorFleetReadinessVerdict::BLOCKER_LOGIN_NOT_PROVEN;
        }

        return [
            'user_id' => $userId,
            'user_name' => $row['user_name'] ?? null,
            'doctor_id' => $doctorId,
            'doctor_code' => $row['doctor_code'] ?? null,
            'doctor_name' => $row['doctor_name'] ?? null,
            'home_branch_locked' => $lock instanceof DoctorBranchLock,
            'home_branch_id' => $lock instanceof DoctorBranchLock ? (int) $lock->home_branch_id : null,
            'home_branch_code' => $lock?->homeBranch?->code === null ? null : (string) $lock->homeBranch->code,
            'trusted_path_state' => $row['state'] ?? DoctorGlobalRolloutReadinessService::STATE_NOT_READY,
            'trusted_path_reasons' => $row['reasons'] ?? [],
            'real_device_login_proven' => is_array($evidence) && ($evidence['count'] ?? 0) >= 1,
            'real_device_login_count' => is_array($evidence) ? (int) $evidence['count'] : 0,
            'real_device_login_last_at' => is_array($evidence) ? $evidence['last_at'] : null,
            'real_device_login_paths' => is_array($evidence) ? $evidence['actions'] : [],
            'proven_device_ids' => is_array($evidence) ? $evidence['device_ids'] : [],
            'authorized_device_ids' => $authorizedDeviceIds,
            'unauthorized_eligible_device_ids' => $missingDeviceIds,
            'blockers' => $blockers,
            'primary_blocker' => DoctorFleetReadinessVerdict::primary($blockers),
            'state' => $blockers === []
                ? DoctorFleetReadinessVerdict::STATE_READY
                : DoctorFleetReadinessVerdict::STATE_NOT_READY,
        ];
    }

    /**
     * The eligible trusted tablets, ascending.
     *
     * ELIGIBLE is asked of the model — active AND cryptographically verified —
     * exactly as the provisioning engine asks it, so the two cannot drift into
     * disagreeing about which hardware counts.
     *
     * @return list<int>
     */
    private function eligibleDeviceIds(): array
    {
        $ids = $this->deviceEstate()
            ->filter(fn (DoctorDevice $device): bool => $device->isActive() && $device->isCryptographicallyVerified())
            ->map(fn (DoctorDevice $device): int => (int) $device->id)
            ->values()
            ->all();

        sort($ids);

        return $ids;
    }

    /**
     * Eligible device ids carrying an ACTIVE authorization, keyed by doctor id.
     *
     * A pair is counted once however many rows back it — `duplicate_active_pairs`
     * reports the excess separately rather than letting it inflate the tally.
     * An authorization on a device that is NOT eligible (revoked, unverified)
     * is dropped here, so a revoked tablet can never close a matrix gap.
     *
     * @param  list<int>  $doctorIds
     * @param  list<int>  $eligibleDeviceIds
     * @return array<int, list<int>>
     */
    private function activeAuthorizationPairs(array $doctorIds, array $eligibleDeviceIds): array
    {
        // Reset, not accumulate: build() may legitimately be called twice on
        // one instance (a command that prints text AND json), and a running
        // total would report the second call as twice as broken.
        $this->duplicateActivePairs = 0;

        if ($doctorIds === [] || $eligibleDeviceIds === []) {
            return [];
        }

        $eligible = array_flip($eligibleDeviceIds);
        $pairs = [];

        foreach ($this->estate->authorizationsForDoctors($doctorIds) as $doctorId => $authorizations) {
            $seen = [];

            foreach ($authorizations as $authorization) {
                if (! $authorization->isActive()) {
                    continue;
                }

                $deviceId = (int) $authorization->doctor_device_id;

                if (! isset($eligible[$deviceId])) {
                    continue;
                }

                $seen[$deviceId] = ($seen[$deviceId] ?? 0) + 1;
            }

            $ids = array_keys($seen);
            sort($ids);

            $pairs[(int) $doctorId] = $ids;
            $this->duplicateActivePairs += array_sum(array_map(
                static fn (int $n): int => $n - 1,
                $seen,
            ));
        }

        return $pairs;
    }

    /**
     * Excess ACTIVE rows for a (doctor, device) pair already counted once.
     *
     * A unique index does not exist for this pair, so a duplicate is
     * representable and must be reported rather than silently deduplicated —
     * two ACTIVE grants for one pair is an approval-trail defect even though it
     * changes nothing about what the doctor can reach.
     */
    private int $duplicateActivePairs = 0;

    /**
     * The hardware estate is read TWICE per report — once to resolve which
     * tablets are eligible, once to build the coverage table. That is one
     * question, so it is one query; the query-budget test pins it.
     *
     * @var Collection<int, DoctorDevice>|null
     */
    private ?Collection $deviceEstate = null;

    /**
     * @return Collection<int, DoctorDevice>
     */
    private function deviceEstate(): Collection
    {
        return $this->deviceEstate ??= $this->estate->deviceEstate();
    }

    /**
     * Which eligible trusted devices have actually carried a readiness login.
     *
     * ELIGIBLE means exactly what the provisioning engine means by it — active
     * and cryptographically verified — asked of the model rather than restated
     * as a status string here, so the two cannot drift apart. A revoked device
     * is counted in the estate and never contributes (GR-R9).
     *
     * @param  Collection<int, array{count:int,last_at:string|null,actions:list<string>,device_ids:list<int>}>  $proof
     * @return array<string,mixed>
     */
    private function deviceCoverage(Collection $proof): array
    {
        $provenIds = [];

        foreach ($proof as $entry) {
            foreach ($entry['device_ids'] as $deviceId) {
                $provenIds[$deviceId] = true;
            }
        }

        $estate = $this->deviceEstate();

        $eligible = $estate
            ->filter(fn (DoctorDevice $device): bool => $device->isActive() && $device->isCryptographicallyVerified())
            ->values();

        $rows = $eligible
            ->map(fn (DoctorDevice $device): array => [
                'device_id' => (int) $device->id,
                'device_name' => (string) $device->device_name,
                'branch_code' => $device->branch?->code === null ? null : (string) $device->branch->code,
                'readiness_proof' => isset($provenIds[(int) $device->id]),
            ])
            ->all();

        $withProof = array_values(array_filter($rows, static fn (array $r): bool => $r['readiness_proof']));

        return [
            'eligible_count' => count($rows),
            'estate_count' => $estate->count(),
            'with_readiness_proof_count' => count($withProof),
            'without_readiness_proof_ids' => array_values(array_map(
                static fn (array $r): int => $r['device_id'],
                array_filter($rows, static fn (array $r): bool => ! $r['readiness_proof']),
            )),
            'rows' => $rows,
        ];
    }

    /**
     * The (doctor x eligible tablet) reconciliation, computed from the estate
     * rather than taken from the tool that writes it.
     *
     * TARGET counts only doctors with a linked record, because an unlinked
     * account has no doctor_id an authorization could name. Counting it would
     * inflate the denominator with a pair that cannot exist and leave MISSING
     * permanently non-zero for a reason no approval can fix.
     *
     * @param  list<array<string,mixed>>  $doctors
     * @param  list<int>  $eligibleDeviceIds
     * @return array<string,int>
     */
    private function authorizationMatrix(array $doctors, array $eligibleDeviceIds): array
    {
        $linked = array_values(array_filter(
            $doctors,
            static fn (array $row): bool => $row['doctor_id'] !== null,
        ));

        $target = count($linked) * count($eligibleDeviceIds);

        $active = 0;

        foreach ($linked as $row) {
            $active += count($row['authorized_device_ids']);
        }

        return [
            'target_pairs' => $target,
            'active_pairs' => $active,
            'missing_pairs' => max(0, $target - $active),
            'duplicate_active_pairs' => $this->duplicateActivePairs,
            'unlinked_doctor_count' => count($doctors) - count($linked),
        ];
    }

    /**
     * Where the fleet would sit once every approved assignment is in place.
     * Read-only: it reports the locks that EXIST, and proposes nothing.
     *
     * @param  list<array<string,mixed>>  $doctors
     * @return array<string,int>
     */
    private function homeBranchMatrix(array $doctors): array
    {
        $matrix = [];

        foreach ($doctors as $row) {
            $code = $row['home_branch_code'] ?? null;
            $key = is_string($code) && $code !== '' ? $code : 'UNSET';
            $matrix[$key] = ($matrix[$key] ?? 0) + 1;
        }

        ksort($matrix);

        return $matrix;
    }

    /**
     * @param  list<array<string,mixed>>  $doctors
     * @param  array<string,mixed>  $devices
     * @return list<array<string,mixed>>
     */
    private function findings(array $doctors, array $devices): array
    {
        $findings = [];

        /*
         * The vacuous-pass guard, reported and not merely acted on. An empty
         * fleet satisfies "every doctor is ready" for free, and this programme
         * has already shipped a gate that read silence as success.
         */
        if ($doctors === [] || $devices['eligible_count'] === 0) {
            $findings[] = [
                'finding' => DoctorFleetReadinessVerdict::FINDING_NOTHING_TO_MEASURE,
                'eligible_doctor_count' => count($doctors),
                'eligible_device_count' => $devices['eligible_count'],
            ];
        }

        foreach ($devices['without_readiness_proof_ids'] as $deviceId) {
            $findings[] = [
                'finding' => DoctorFleetReadinessVerdict::FINDING_DEVICE_UNPROVEN,
                'device_id' => $deviceId,
            ];
        }

        return $findings;
    }

    /**
     * FAIL CLOSED. READY requires every eligible doctor to clear every gate AND
     * every eligible trusted device to carry at least one proof. An empty
     * population is NO-GO, never READY.
     *
     * @param  list<array<string,mixed>>  $doctors
     * @param  list<array<string,mixed>>  $ready
     * @param  array<string,mixed>  $devices
     */
    private function verdict(array $doctors, array $ready, array $devices): string
    {
        if ($doctors === [] || $devices['eligible_count'] === 0) {
            return DoctorFleetReadinessVerdict::NO_GO;
        }

        if (count($ready) === count($doctors) && $devices['without_readiness_proof_ids'] === []) {
            return DoctorFleetReadinessVerdict::READY;
        }

        if ($ready === []) {
            return DoctorFleetReadinessVerdict::NO_GO;
        }

        return DoctorFleetReadinessVerdict::PARTIAL;
    }

    /**
     * @param  list<array<string,mixed>>  $doctors
     * @return array<string,int>
     */
    private function blockerTally(array $doctors): array
    {
        $tally = [];

        foreach ($doctors as $row) {
            foreach ($row['blockers'] as $blocker) {
                $tally[$blocker] = ($tally[$blocker] ?? 0) + 1;
            }
        }

        arsort($tally);

        return $tally;
    }

    /**
     * @param  list<array<string,mixed>>  $doctors
     */
    private function countWith(array $doctors, string $blocker): int
    {
        return count(array_filter(
            $doctors,
            static fn (array $row): bool => in_array($blocker, $row['blockers'], true),
        ));
    }

    /**
     * @param  list<array<string,mixed>>  $doctors
     */
    private function countWithout(array $doctors, string $blocker): int
    {
        return count($doctors) - $this->countWith($doctors, $blocker);
    }
}
