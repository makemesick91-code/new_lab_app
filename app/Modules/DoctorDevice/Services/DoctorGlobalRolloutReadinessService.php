<?php

namespace App\Modules\DoctorDevice\Services;

use App\Models\User;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorDevice\Interfaces\DoctorDeviceRolloutReadinessRepositoryInterface;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\DoctorDevice\Support\DoctorSessionProof;
use App\Services\Foundation\FeatureFlagService;
use App\Support\Android\AndroidDoctorEnforcementScope;
use App\Support\Android\Phase4aPilotPreparationScanner;
use Illuminate\Support\Collection;

/**
 * DOCTOR-PWA-GLOBAL-ROLLOUT-READINESS-1 — could fleet-wide doctor enforcement
 * be switched on without locking a clinician out?
 *
 * It answers per DOCTOR, never per device. A WebAuthn credential belongs to a
 * device, so counting three usable credentials says nothing about whether
 * fifteen doctors can log in; sharing a tablet is not being authorized on it.
 * The unit of the answer is therefore "does this doctor have at least one
 * complete trusted path", and a doctor with two devices needs only one of them
 * to work.
 *
 * READ-ONLY, and structurally so. A companion test scans this file for the
 * primitives that could write, spawn, fetch or read a request, because a
 * readiness gate that can act is a readiness gate that will one day act. That
 * is the same argument Phase4aPilotPreparationScanner makes about itself, and
 * it applies harder here: this one runs against the whole fleet.
 *
 * It also cannot activate anything. It never writes the enforcement flag,
 * never touches the scope, and reports the global posture by ASKING the scope
 * rather than by asserting a recorded value.
 */
class DoctorGlobalRolloutReadinessService
{
    /** Every target doctor has a complete trusted path. */
    public const VERDICT_GLOBAL_READY = 'GLOBAL_READY';

    /** Some do, some do not. The ordinary state of a rollout in progress. */
    public const VERDICT_PARTIAL = 'PARTIAL';

    /** None do, or there is nobody to measure. */
    public const VERDICT_NOT_READY = 'NOT_READY';

    public const STATE_READY = 'READY';

    public const STATE_NOT_READY = 'NOT_READY';

    public const REASON_DOCTOR_NOT_LINKED = 'doctor_record_not_linked';

    public const REASON_DOCTOR_INACTIVE = 'doctor_record_inactive';

    public const REASON_NO_AUTHORIZATION = 'no_device_authorization';

    public const REASON_AUTHORIZATION_NOT_ACTIVE = 'authorization_not_active';

    public const REASON_DEVICE_NOT_ACTIVE = 'device_not_active';

    public const REASON_DEVICE_IDENTITY_UNVERIFIED = 'device_identity_not_cryptographically_verified';

    public const REASON_NO_CREDENTIAL = 'no_webauthn_credential';

    public const REASON_CREDENTIAL_REVOKED = 'all_credentials_revoked';

    public const REASON_CREDENTIAL_NOT_USER_VERIFIED = 'credential_not_user_verified';

    public const REASON_CREDENTIAL_NOT_DEVICE_BOUND = 'credential_not_device_bound';

    /**
     * The engine believes a path is complete and the login gate would still
     * refuse it. Reported as a FINDING, never silently resolved either way.
     */
    public const FINDING_GATE_DISAGREES = 'gate_refuses_a_provisioned_path';

    public function __construct(
        private readonly DoctorDeviceRolloutReadinessRepositoryInterface $estate,
        private readonly DoctorAppLoginGate $gate,
        private readonly AndroidDoctorEnforcementScope $scope,
        private readonly Phase4aPilotPreparationScanner $posture,
        private readonly FeatureFlagService $flags,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(): array
    {
        $accounts = $this->estate->doctorAccounts();
        $userIds = $accounts->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        $records = $this->estate->doctorRecordsForUsers($userIds);

        $doctorIds = $records
            ->map(fn (Doctor $doctor): int => (int) $doctor->id)
            ->values()
            ->all();

        $authorizations = $this->estate->authorizationsForDoctors($doctorIds);

        $doctors = $accounts
            ->map(fn (User $user): array => $this->doctorReadiness($user, $records, $authorizations))
            ->values()
            ->all();

        $ready = array_values(array_filter($doctors, fn (array $row): bool => $row['state'] === self::STATE_READY));
        $notReady = array_values(array_filter($doctors, fn (array $row): bool => $row['state'] !== self::STATE_READY));

        $estate = $this->deviceEstateSummary();
        $cohort = $this->cohort($doctors);
        $findings = $this->findings($doctors);

        return [
            'verdict' => $this->verdict($doctors, $ready),
            'target_doctor_count' => count($doctors),
            'ready_doctor_count' => count($ready),
            'not_ready_doctor_count' => count($notReady),
            'ready_doctor_user_ids' => array_map(static fn (array $r): int => $r['user_id'], $ready),
            'doctors' => $doctors,
            'blocking_reasons' => $this->reasonTally($notReady),
            'branches' => $this->branchReadiness($doctors),
            'devices' => $estate,
            'cohort' => $cohort,
            'runtime' => $this->runtime(),
            'findings' => $findings,
        ];
    }

    /**
     * The five conditions, and nothing else.
     *
     * @param  Collection<int, Doctor>  $records  keyed by user id
     * @param  Collection<int, Collection<int, DoctorDeviceAuthorization>>  $authorizations  keyed by doctor id
     * @return array<string,mixed>
     */
    private function doctorReadiness(User $user, Collection $records, Collection $authorizations): array
    {
        $userId = (int) $user->id;
        $record = $records->get($userId);

        $row = [
            'user_id' => $userId,
            'user_name' => (string) $user->name,
            'doctor_id' => null,
            'doctor_code' => null,
            'doctor_name' => null,
            'state' => self::STATE_NOT_READY,
            'reasons' => [],
            'path' => null,
            'branch_id' => null,
            'branch_code' => null,
        ];

        if (! $record instanceof Doctor) {
            $row['reasons'] = [self::REASON_DOCTOR_NOT_LINKED];

            return $row;
        }

        $row['doctor_id'] = (int) $record->id;
        $row['doctor_code'] = $record->code === null ? null : (string) $record->code;
        $row['doctor_name'] = (string) $record->name;

        /*
         * Checked here rather than relied upon from the repository.
         *
         * DoctorIdentityResolver::resolveForUser() does NOT filter is_active,
         * so an inactive doctor resolves perfectly well at login. A readiness
         * engine that assumed otherwise would report an inactive clinician as
         * ready to be enforced.
         */
        if ($record->is_active !== true) {
            $row['reasons'] = [self::REASON_DOCTOR_INACTIVE];

            return $row;
        }

        $doctorAuthorizations = $authorizations->get((int) $record->id) ?? collect();

        if ($doctorAuthorizations->isEmpty()) {
            $row['reasons'] = [self::REASON_NO_AUTHORIZATION];

            return $row;
        }

        $reasons = [];

        foreach ($doctorAuthorizations as $authorization) {
            $path = $this->pathFor($record, $authorization);

            if ($path['complete']) {
                $row['state'] = self::STATE_READY;
                $row['reasons'] = [];
                $row['path'] = $path['path'];
                $row['branch_id'] = $path['path']['branch_id'];
                $row['branch_code'] = $path['path']['branch_code'];

                return $row;
            }

            foreach ($path['reasons'] as $reason) {
                $reasons[$reason] = true;
            }
        }

        $row['reasons'] = array_keys($reasons);

        return $row;
    }

    /**
     * One doctor, one authorization: is this a complete trusted path?
     *
     * @return array{complete:bool,reasons:list<string>,path:array<string,mixed>|null}
     */
    private function pathFor(Doctor $doctor, DoctorDeviceAuthorization $authorization): array
    {
        if (! $authorization->isActive()) {
            return ['complete' => false, 'reasons' => [self::REASON_AUTHORIZATION_NOT_ACTIVE], 'path' => null];
        }

        $device = $authorization->device;

        if (! $device instanceof DoctorDevice) {
            return ['complete' => false, 'reasons' => [self::REASON_DEVICE_NOT_ACTIVE], 'path' => null];
        }

        if (! $device->isActive()) {
            return ['complete' => false, 'reasons' => [self::REASON_DEVICE_NOT_ACTIVE], 'path' => null];
        }

        if (! $device->isCryptographicallyVerified()) {
            return ['complete' => false, 'reasons' => [self::REASON_DEVICE_IDENTITY_UNVERIFIED], 'path' => null];
        }

        $credentials = $device->webAuthnCredentials ?? collect();

        if ($credentials->isEmpty()) {
            return ['complete' => false, 'reasons' => [self::REASON_NO_CREDENTIAL], 'path' => null];
        }

        $reasons = [];

        foreach ($credentials as $credential) {
            $failure = $this->credentialFailure($credential);

            if ($failure === null) {
                return [
                    'complete' => true,
                    'reasons' => [],
                    'path' => [
                        'doctor_id' => (int) $doctor->id,
                        'authorization_id' => (int) $authorization->id,
                        'device_id' => (int) $device->id,
                        'device_name' => (string) $device->device_name,
                        // The ROW id, deliberately not called `credential_id`.
                        // That name belongs to the base64url WebAuthn handle on
                        // the same model, which is credential material and must
                        // never reach a terminal or an evidence artifact. Two
                        // fields one rename apart is how the wrong one gets
                        // printed.
                        'credential_row_id' => (int) $credential->id,
                        'branch_id' => $device->branch_id === null ? null : (int) $device->branch_id,
                        'branch_code' => $device->branch?->code === null ? null : (string) $device->branch->code,
                        'gate_would_admit' => $this->gateWouldAdmit($doctor, $device, $credential),
                    ],
                ];
            }

            $reasons[$failure] = true;
        }

        return ['complete' => false, 'reasons' => array_keys($reasons), 'path' => null];
    }

    /**
     * Why this credential cannot carry a doctor, or null if it can.
     *
     * `backup_eligible` is NULLABLE on purpose — an authenticator that reported
     * neither flag has told us nothing, and a null is an honest record of that.
     * So the test is `=== false`, never `!== true`: an unstated property is not
     * a measured one, and unknown must not be admitted.
     *
     * The device-bound verdict is then checked SEPARATELY even though it is
     * derived from the same flag. The two agreeing is the normal case; the two
     * disagreeing means a stored verdict has drifted from the flags it was
     * derived from, which is the only route by which a syncable passkey could
     * reach a clinical session. Collapsing them would remove the only place
     * that drift is visible.
     */
    private function credentialFailure(DoctorDeviceWebAuthnCredential $credential): ?string
    {
        if ($credential->isRevoked()) {
            return self::REASON_CREDENTIAL_REVOKED;
        }

        if ($credential->user_verified !== true) {
            return self::REASON_CREDENTIAL_NOT_USER_VERIFIED;
        }

        if ($credential->backup_eligible !== false) {
            return self::REASON_CREDENTIAL_NOT_DEVICE_BOUND;
        }

        if (! $credential->isDeviceBound()) {
            return self::REASON_CREDENTIAL_NOT_DEVICE_BOUND;
        }

        return null;
    }

    /**
     * Would the login gate actually admit this path?
     *
     * Reported, never used to decide readiness. The engine deliberately does
     * not delegate the whole question to the gate, because the gate needs a
     * session and a flag state that a report has no business simulating. But a
     * second implementation of a security decision is a second implementation
     * that can drift, so the two are compared and any disagreement is surfaced
     * as a finding rather than quietly resolved in either direction.
     */
    private function gateWouldAdmit(Doctor $doctor, DoctorDevice $device, DoctorDeviceWebAuthnCredential $credential): bool
    {
        $proof = DoctorSessionProof::webAuthn((int) $credential->id);

        return $this->gate->deviceUsableForProof($device, $proof)
            && $this->gate->activeAuthorizationFor((int) $doctor->id, (int) $device->id) !== null;
    }

    /**
     * @param  list<array<string,mixed>>  $doctors
     * @return list<array<string,mixed>>
     */
    private function findings(array $doctors): array
    {
        $findings = [];

        foreach ($doctors as $row) {
            if ($row['state'] !== self::STATE_READY || $row['path'] === null) {
                continue;
            }

            if ($row['path']['gate_would_admit'] === true) {
                continue;
            }

            /*
             * The WebAuthn master switch being off makes the gate refuse every
             * path, which is a deployment state and not a provisioning defect.
             * Readiness is about whether the fleet COULD be enforced, so the
             * switch being off must not make fifteen doctors look unprovisioned.
             */
            if (! $this->gate->enforcementEnabled() || ! $this->webAuthnLoginArmed()) {
                continue;
            }

            $findings[] = [
                'finding' => self::FINDING_GATE_DISAGREES,
                'user_id' => $row['user_id'],
                'doctor_id' => $row['doctor_id'],
                'device_id' => $row['path']['device_id'],
                'credential_row_id' => $row['path']['credential_row_id'],
                'detail' => 'This path satisfies every provisioning condition and the login gate would still '
                    .'refuse it. One of the two is wrong and neither may be assumed correct.',
            ];
        }

        return $findings;
    }

    /**
     * Read through FeatureFlagService, never a dotted config lookup: the flag
     * keys contain dots, so `config('feature_flags.flags.doctor.…')` traverses
     * into nothing and returns null, which reads as "off" and is the quiet way
     * a test passes with the switch it meant to arm still down.
     */
    private function webAuthnLoginArmed(): bool
    {
        return $this->flags->enabled(DoctorDeviceWebAuthnLoginService::FLAG);
    }

    /**
     * Per branch, three numbers that are routinely confused for one another.
     *
     * Every doctor is pivot-authorized to practise at every RME branch, so a
     * count keyed on that pivot returns the whole fleet for every branch and
     * means nothing about readiness. The operationally useful number is the
     * third: doctors whose complete path runs through a device sitting here.
     *
     * @param  list<array<string,mixed>>  $doctors
     * @return list<array<string,mixed>>
     */
    private function branchReadiness(array $doctors): array
    {
        $branches = [];

        foreach ($this->estate->deviceEstate() as $device) {
            $branchId = $device->branch_id === null ? 0 : (int) $device->branch_id;

            $branches[$branchId] ??= [
                'branch_id' => $device->branch_id === null ? null : $branchId,
                'branch_code' => $device->branch?->code === null ? null : (string) $device->branch->code,
                'ready_devices_registered_here' => 0,
                'doctors_ready_via_a_device_here' => 0,
            ];

            if ($this->deviceCouldCarryADoctor($device)) {
                $branches[$branchId]['ready_devices_registered_here']++;
            }
        }

        foreach ($doctors as $row) {
            if ($row['state'] !== self::STATE_READY || $row['branch_id'] === null) {
                continue;
            }

            $branchId = (int) $row['branch_id'];

            if (! isset($branches[$branchId])) {
                continue;
            }

            $branches[$branchId]['doctors_ready_via_a_device_here']++;
        }

        ksort($branches);

        return array_values($branches);
    }

    private function deviceCouldCarryADoctor(DoctorDevice $device): bool
    {
        if (! $device->isActive() || ! $device->isCryptographicallyVerified()) {
            return false;
        }

        foreach ($device->webAuthnCredentials ?? collect() as $credential) {
            if ($this->credentialFailure($credential) === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    private function deviceEstateSummary(): array
    {
        $devices = $this->estate->deviceEstate();
        $active = 0;
        $revoked = 0;
        $usableCredentials = 0;
        $revokedCredentials = 0;

        foreach ($devices as $device) {
            if ($device->isActive()) {
                $active++;
            }

            if ($device->isRevoked()) {
                $revoked++;
            }

            foreach ($device->webAuthnCredentials ?? collect() as $credential) {
                if ($credential->isRevoked()) {
                    $revokedCredentials++;

                    continue;
                }

                if ($this->credentialFailure($credential) === null) {
                    $usableCredentials++;
                }
            }
        }

        return [
            'total' => $devices->count(),
            'active' => $active,
            'revoked' => $revoked,
            'usable_credentials' => $usableCredentials,
            'revoked_credentials' => $revokedCredentials,
        ];
    }

    /**
     * The enforced cohort, and whether the doctors inside it are provisioned.
     *
     * Membership and readiness are different facts and are reported as such:
     * a doctor can be covered without being ready, which is the state that
     * strands a clinician, and ready without being covered, which is merely
     * a doctor waiting their turn.
     *
     * @param  list<array<string,mixed>>  $doctors
     * @return array<string,mixed>
     */
    private function cohort(array $doctors): array
    {
        $declared = $this->scope->pilotDoctorUserIds();
        $coveredNotReady = [];

        foreach ($doctors as $row) {
            if (! in_array($row['user_id'], $declared, true)) {
                continue;
            }

            if ($row['state'] !== self::STATE_READY) {
                $coveredNotReady[] = $row['user_id'];
            }
        }

        return [
            'user_ids' => $declared,
            'size' => count($declared),
            'maximum' => $this->scope->pilotCohortMaximum(),
            'all_ready' => $declared !== [] && $coveredNotReady === [],
            'covered_but_not_ready' => $coveredNotReady,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runtime(): array
    {
        return [
            'enforcement_flag_armed' => $this->gate->enforcementEnabled(),
            'webauthn_login_armed' => $this->webAuthnLoginArmed(),
            'enforcement_scope_mode' => $this->scope->mode(),
            'enforcement_scope_usable' => $this->scope->isUsable(),
            'enforcement_posture' => $this->posture->observedPosture(),
            'global_scope_permitted' => $this->scope->globalPermitted(),
            'global_enforcement_active' => $this->posture->globalEnforcementActiveLive(),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $notReady
     * @return array<string,int>
     */
    private function reasonTally(array $notReady): array
    {
        $tally = [];

        foreach ($notReady as $row) {
            foreach ($row['reasons'] as $reason) {
                $tally[$reason] = ($tally[$reason] ?? 0) + 1;
            }
        }

        ksort($tally);

        return $tally;
    }

    /**
     * @param  list<array<string,mixed>>  $doctors
     * @param  list<array<string,mixed>>  $ready
     */
    private function verdict(array $doctors, array $ready): string
    {
        if ($doctors === [] || $ready === []) {
            return self::VERDICT_NOT_READY;
        }

        return count($ready) === count($doctors)
            ? self::VERDICT_GLOBAL_READY
            : self::VERDICT_PARTIAL;
    }
}
