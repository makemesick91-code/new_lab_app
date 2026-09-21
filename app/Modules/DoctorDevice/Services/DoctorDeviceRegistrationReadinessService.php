<?php

namespace App\Modules\DoctorDevice\Services;

use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\DoctorDevice\Support\DoctorSessionProof;
use Illuminate\Support\Collection;
use Throwable;

/**
 * DOCTOR-DEVICE-GUIDED-REGISTRATION-WORKFLOW-1 — is THIS tablet ready for
 * clinical use?
 *
 * The fleet engine {@see DoctorGlobalRolloutReadinessService} answers per
 * DOCTOR across the estate; the guided registration workflow finishes one
 * DEVICE at a time and needs the same question scoped to it. This class is that
 * projection and nothing more:
 *
 *   - the REASON_* vocabulary is the fleet engine's, imported not redefined;
 *   - credential usability is {@see DoctorDeviceCredentialUsabilityPolicy},
 *     the same chain the fleet engine runs;
 *   - device identity is {@see DoctorDeviceIdentityProofPolicy}, unchanged;
 *   - live assertion proof is {@see DoctorWebAuthnLiveProofService}, whose
 *     PASS/STALE/NEVER_PROVEN/UNVERIFIED classification is consumed verbatim.
 *
 * It therefore holds no security truth of its own. If it ever starts deciding
 * something rather than reporting it, that is the bug.
 *
 * EVERY GATE IS EVALUATED. There is deliberately no early return once a gate
 * fails, because an operator who revoked a credential must be told that the
 * credential gate failed — not that the first gate in some arbitrary order
 * did. A short-circuiting checklist reports one cause for three different
 * faults and sends the operator to the wrong page.
 *
 * READ-ONLY. No repository write, no model save, no dispatch. A companion test
 * scans this file for the primitives that could write, on the same argument the
 * fleet engine makes about itself.
 */
class DoctorDeviceRegistrationReadinessService
{
    public const GATE_PASS = 'PASS';

    public const GATE_FAIL = 'FAIL';

    /**
     * Measured and inconclusive — NOT a pass.
     *
     * Kept distinct from FAIL because "we could not tell" and "we checked and
     * it is wrong" are different operator instructions, and collapsing them
     * into a green is exactly the false green this codebase has shipped before.
     */
    public const GATE_UNVERIFIED = 'UNVERIFIED';

    public const READY = 'READY';

    public const NOT_READY = 'NOT_READY';

    /** Gate keys, in the order the checklist is rendered. */
    public const GATE_DEVICE_ACTIVE = 'DEVICE_ACTIVE';

    public const GATE_DEVICE_NOT_REVOKED = 'DEVICE_NOT_REVOKED';

    public const GATE_APPROVAL_ACTIVE = 'APPROVAL_ACTIVE';

    public const GATE_DEVICE_BINDING_VALID = 'DEVICE_BINDING_VALID';

    public const GATE_WEBAUTHN_CREDENTIAL_ACTIVE = 'WEBAUTHN_CREDENTIAL_ACTIVE';

    public const GATE_USER_VERIFICATION_VALID = 'USER_VERIFICATION_VALID';

    public const GATE_CREDENTIAL_DEVICE_BOUND = 'CREDENTIAL_DEVICE_BOUND';

    public const GATE_ACTIVE_DOCTOR_AUTHORIZATION = 'AT_LEAST_ONE_ACTIVE_DOCTOR_AUTHORIZATION';

    public const GATE_LOGIN_PROOF = 'SUCCESSFUL_WEBAUTHN_LOGIN_PROOF';

    public const GATE_LOGIN_PROOF_FRESHNESS = 'LOGIN_PROOF_FRESHNESS';

    public const GATE_NO_INVALID_DEVICE_STATE = 'NO_INVALID_DEVICE_STATE';

    /**
     * ONE ESTATE SNAPSHOT PER INSTANCE.
     *
     * `DoctorWebAuthnLiveProofService::report()` measures the WHOLE estate —
     * every active device, its usable credentials and their audit trail — to
     * answer a question about one tablet. The registration BOARD evaluates
     * every device on the page, so without this the estate was being rebuilt
     * once per row: twenty tablets, twenty full estate scans.
     *
     * Memoised for the life of this instance, which is one request. That also
     * makes the board internally consistent: every row on a page is judged
     * against the same snapshot rather than against twenty snapshots taken
     * milliseconds apart.
     *
     * @var array<string,mixed>|null
     */
    private ?array $estateProofReport = null;

    public function __construct(
        private readonly DoctorDeviceIdentityProofPolicy $identityProof,
        private readonly DoctorDeviceCredentialUsabilityPolicy $credentialUsability,
        private readonly DoctorWebAuthnLiveProofService $liveProof,
        private readonly DoctorAppLoginGate $gate,
    ) {}

    /**
     * @return array{
     *     device_id:int,
     *     verdict:string,
     *     gates:list<array<string,mixed>>,
     *     failed_gates:list<string>,
     *     usable_credentials:int,
     *     active_authorizations:int,
     *     proof:array<string,mixed>
     * }
     */
    public function evaluate(DoctorDevice $device): array
    {
        $credentials = $this->credentials($device);
        $authorizations = $this->authorizations($device);
        $proof = $this->proofFor($device);

        $gates = [
            $this->deviceActiveGate($device),
            $this->deviceNotRevokedGate($device),
            $this->approvalGate($device),
            $this->identityGate($device),
            $this->credentialPresenceGate($credentials),
            $this->userVerificationGate($credentials),
            $this->deviceBoundGate($credentials),
            $this->authorizationGate($authorizations),
            $this->loginProofGate($proof),
            $this->freshnessGate($proof),
            $this->gateAgreementGate($device, $credentials, $authorizations),
        ];

        $failed = array_values(array_map(
            static fn (array $gate): string => (string) $gate['key'],
            array_filter($gates, static fn (array $gate): bool => $gate['status'] !== self::GATE_PASS),
        ));

        return [
            'device_id' => (int) $device->id,
            'verdict' => $failed === [] ? self::READY : self::NOT_READY,
            'gates' => $gates,
            'failed_gates' => $failed,
            'usable_credentials' => $credentials
                ->filter(fn (DoctorDeviceWebAuthnCredential $c): bool => $this->credentialUsability->usable($c))
                ->count(),
            'active_authorizations' => $authorizations
                ->filter(static fn (DoctorDeviceAuthorization $a): bool => $a->isActive())
                ->count(),
            'proof' => $proof,
        ];
    }

    public function isReady(DoctorDevice $device): bool
    {
        return $this->evaluate($device)['verdict'] === self::READY;
    }

    /** @return Collection<int, DoctorDeviceWebAuthnCredential> */
    private function credentials(DoctorDevice $device): Collection
    {
        return $device->webAuthnCredentials()->orderBy('id')->get();
    }

    /** @return Collection<int, DoctorDeviceAuthorization> */
    private function authorizations(DoctorDevice $device): Collection
    {
        return $device->authorizations()->with('doctor')->orderBy('id')->get();
    }

    /**
     * This device's row from the estate-wide live-proof report.
     *
     * The report is consumed rather than reimplemented so that the
     * PASS/STALE/NEVER_PROVEN/UNVERIFIED classification — including its
     * structural guarantee that a proof is attributed to the device it was
     * performed on — stays in one place. A device with no row is a device the
     * report does not measure (it only measures ACTIVE devices), which is
     * reported as such and never as a pass.
     *
     * @return array<string,mixed>
     */
    private function proofFor(DoctorDevice $device): array
    {
        try {
            // A failed report is NOT cached: the next call gets to try again,
            // rather than a transient database blip pinning every device on the
            // page to UNVERIFIED for the rest of the request.
            $report = $this->estateProofReport ??= $this->liveProof->report();
        } catch (Throwable) {
            return $this->proofShape([
                'live_assertion_proof' => DoctorWebAuthnLiveProofService::PROOF_UNVERIFIED,
                'reason' => 'proof_report_failed',
            ]);
        }

        $window = $report['freshness_window_days'] ?? null;

        foreach (($report['devices'] ?? []) as $row) {
            if ((int) ($row['device_id'] ?? 0) === (int) $device->id) {
                return $this->proofShape($row + [
                    'measured' => true,
                    'freshness_window_days' => $window,
                ]);
            }
        }

        return $this->proofShape([
            'live_assertion_proof' => DoctorWebAuthnLiveProofService::PROOF_NEVER_PROVEN,
            // Named for what is true: the estate report measures active devices,
            // so a pending or revoked tablet is simply not in the population.
            'reason' => 'device_not_in_measured_population',
            'freshness_window_days' => $window,
        ]);
    }

    /**
     * Every key a caller may read, always present.
     *
     * The estate report's own rows carry a fixed shape; the rows this class
     * synthesises for a device the report does not measure did not, and a view
     * reading `proof_age_days` on one of them died with an undefined key. A
     * report that renders for a healthy tablet and crashes for an unfinished
     * one is a report that hides exactly the cases it exists to show.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function proofShape(array $row): array
    {
        return $row + [
            'live_assertion_proof' => DoctorWebAuthnLiveProofService::PROOF_NEVER_PROVEN,
            'reason' => null,
            'measured' => false,
            'freshness_window_days' => null,
            'last_proof_utc' => null,
            'last_proof_local' => null,
            'last_proof_iso' => null,
            'proof_age_days' => null,
            'credentials_usable' => 0,
            'device_status' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function gate(string $key, string $status, string $label, ?string $reason, string $step): array
    {
        return [
            'key' => $key,
            'status' => $status,
            'label' => $label,
            'reason' => $reason,
            'step' => $step,
        ];
    }

    private function deviceActiveGate(DoctorDevice $device): array
    {
        return $this->gate(
            self::GATE_DEVICE_ACTIVE,
            $device->isActive() ? self::GATE_PASS : self::GATE_FAIL,
            'Perangkat berstatus aktif',
            $device->isActive() ? null : DoctorGlobalRolloutReadinessService::REASON_DEVICE_NOT_ACTIVE,
            'approval',
        );
    }

    private function deviceNotRevokedGate(DoctorDevice $device): array
    {
        return $this->gate(
            self::GATE_DEVICE_NOT_REVOKED,
            $device->isRevoked() ? self::GATE_FAIL : self::GATE_PASS,
            'Perangkat tidak dicabut',
            $device->isRevoked() ? DoctorGlobalRolloutReadinessService::REASON_DEVICE_NOT_ACTIVE : null,
            'approval',
        );
    }

    private function approvalGate(DoctorDevice $device): array
    {
        return $this->gate(
            self::GATE_APPROVAL_ACTIVE,
            $device->isPendingApproval() ? self::GATE_FAIL : self::GATE_PASS,
            'Pendaftaran perangkat telah disetujui',
            $device->isPendingApproval() ? DoctorGlobalRolloutReadinessService::REASON_DEVICE_NOT_ACTIVE : null,
            'approval',
        );
    }

    private function identityGate(DoctorDevice $device): array
    {
        $ok = $this->identityProof->acceptable($device);

        return $this->gate(
            self::GATE_DEVICE_BINDING_VALID,
            $ok ? self::GATE_PASS : self::GATE_FAIL,
            'Identitas perangkat terbukti secara kriptografis',
            $ok ? null : DoctorGlobalRolloutReadinessService::REASON_DEVICE_IDENTITY_UNVERIFIED,
            'webauthn',
        );
    }

    /** @param  Collection<int, DoctorDeviceWebAuthnCredential>  $credentials */
    private function credentialPresenceGate(Collection $credentials): array
    {
        if ($credentials->isEmpty()) {
            return $this->gate(
                self::GATE_WEBAUTHN_CREDENTIAL_ACTIVE,
                self::GATE_FAIL,
                'Perangkat memiliki kredensial WebAuthn aktif',
                DoctorGlobalRolloutReadinessService::REASON_NO_CREDENTIAL,
                'webauthn',
            );
        }

        $live = $credentials->reject(
            static fn (DoctorDeviceWebAuthnCredential $c): bool => $c->isRevoked(),
        );

        return $this->gate(
            self::GATE_WEBAUTHN_CREDENTIAL_ACTIVE,
            $live->isEmpty() ? self::GATE_FAIL : self::GATE_PASS,
            'Perangkat memiliki kredensial WebAuthn aktif',
            $live->isEmpty() ? DoctorGlobalRolloutReadinessService::REASON_CREDENTIAL_REVOKED : null,
            'webauthn',
        );
    }

    /**
     * Evaluated over the credentials that are still live, so that a revoked
     * credential fails the credential gate ONLY. Judging a revoked credential's
     * user-verification flag would fail three gates for one act and tell the
     * operator to fix two things that are not wrong.
     *
     * @param  Collection<int, DoctorDeviceWebAuthnCredential>  $credentials
     */
    private function userVerificationGate(Collection $credentials): array
    {
        $live = $credentials->reject(
            static fn (DoctorDeviceWebAuthnCredential $c): bool => $c->isRevoked(),
        );

        if ($live->isEmpty()) {
            return $this->gate(
                self::GATE_USER_VERIFICATION_VALID,
                self::GATE_UNVERIFIED,
                'Kredensial memakai verifikasi pengguna',
                'no_live_credential_to_measure',
                'webauthn',
            );
        }

        $ok = $live->contains(static fn (DoctorDeviceWebAuthnCredential $c): bool => $c->user_verified === true);

        return $this->gate(
            self::GATE_USER_VERIFICATION_VALID,
            $ok ? self::GATE_PASS : self::GATE_FAIL,
            'Kredensial memakai verifikasi pengguna',
            $ok ? null : DoctorGlobalRolloutReadinessService::REASON_CREDENTIAL_NOT_USER_VERIFIED,
            'webauthn',
        );
    }

    /** @param  Collection<int, DoctorDeviceWebAuthnCredential>  $credentials */
    private function deviceBoundGate(Collection $credentials): array
    {
        $live = $credentials->reject(
            static fn (DoctorDeviceWebAuthnCredential $c): bool => $c->isRevoked(),
        );

        if ($live->isEmpty()) {
            return $this->gate(
                self::GATE_CREDENTIAL_DEVICE_BOUND,
                self::GATE_UNVERIFIED,
                'Kredensial terikat pada perangkat ini',
                'no_live_credential_to_measure',
                'webauthn',
            );
        }

        $ok = $live->contains(fn (DoctorDeviceWebAuthnCredential $c): bool => $this->credentialUsability->usable($c));

        return $this->gate(
            self::GATE_CREDENTIAL_DEVICE_BOUND,
            $ok ? self::GATE_PASS : self::GATE_FAIL,
            'Kredensial terikat pada perangkat ini',
            $ok ? null : DoctorGlobalRolloutReadinessService::REASON_CREDENTIAL_NOT_DEVICE_BOUND,
            'webauthn',
        );
    }

    /** @param  Collection<int, DoctorDeviceAuthorization>  $authorizations */
    private function authorizationGate(Collection $authorizations): array
    {
        if ($authorizations->isEmpty()) {
            return $this->gate(
                self::GATE_ACTIVE_DOCTOR_AUTHORIZATION,
                self::GATE_FAIL,
                'Minimal satu dokter berstatus aktif pada perangkat ini',
                DoctorGlobalRolloutReadinessService::REASON_NO_AUTHORIZATION,
                'doctors',
            );
        }

        $active = $authorizations->contains(
            static fn (DoctorDeviceAuthorization $a): bool => $a->isActive(),
        );

        return $this->gate(
            self::GATE_ACTIVE_DOCTOR_AUTHORIZATION,
            $active ? self::GATE_PASS : self::GATE_FAIL,
            'Minimal satu dokter berstatus aktif pada perangkat ini',
            $active ? null : DoctorGlobalRolloutReadinessService::REASON_AUTHORIZATION_NOT_ACTIVE,
            'doctors',
        );
    }

    /** @param  array<string,mixed>  $proof */
    private function loginProofGate(array $proof): array
    {
        $verdict = (string) ($proof['live_assertion_proof'] ?? DoctorWebAuthnLiveProofService::PROOF_NEVER_PROVEN);

        // A STALE proof is still a proof that a real assertion happened on this
        // device — its AGE is the freshness gate's business, not this one's.
        $proven = in_array($verdict, [
            DoctorWebAuthnLiveProofService::PROOF_PASS,
            DoctorWebAuthnLiveProofService::PROOF_STALE,
        ], true);

        $status = $proven
            ? self::GATE_PASS
            : ($verdict === DoctorWebAuthnLiveProofService::PROOF_UNVERIFIED
                ? self::GATE_UNVERIFIED
                : self::GATE_FAIL);

        return $this->gate(
            self::GATE_LOGIN_PROOF,
            $status,
            'Ada bukti login WebAuthn yang benar-benar berhasil di perangkat ini',
            $proven ? null : (string) ($proof['reason'] ?? $verdict),
            'login-test',
        );
    }

    /** @param  array<string,mixed>  $proof */
    private function freshnessGate(array $proof): array
    {
        $verdict = (string) ($proof['live_assertion_proof'] ?? DoctorWebAuthnLiveProofService::PROOF_NEVER_PROVEN);

        $status = match ($verdict) {
            DoctorWebAuthnLiveProofService::PROOF_PASS => self::GATE_PASS,
            DoctorWebAuthnLiveProofService::PROOF_STALE => self::GATE_FAIL,
            default => self::GATE_UNVERIFIED,
        };

        return $this->gate(
            self::GATE_LOGIN_PROOF_FRESHNESS,
            $status,
            'Bukti login masih dalam jendela kesegaran',
            $status === self::GATE_PASS ? null : (string) ($proof['reason'] ?? $verdict),
            'login-test',
        );
    }

    /**
     * The checklist believes this tablet is usable and the LOGIN GATE would
     * still refuse it.
     *
     * Asked rather than assumed, on the fleet engine's argument: a second
     * implementation of a security decision is a second implementation that can
     * drift, so the two are compared and a disagreement is surfaced instead of
     * being resolved in either direction. When the checklist already knows the
     * device is unusable there is nothing to disagree about, and the gate
     * reports UNVERIFIED rather than a vacuous pass.
     *
     * @param  Collection<int, DoctorDeviceWebAuthnCredential>  $credentials
     * @param  Collection<int, DoctorDeviceAuthorization>  $authorizations
     */
    private function gateAgreementGate(
        DoctorDevice $device,
        Collection $credentials,
        Collection $authorizations,
    ): array {
        $credential = $credentials->first(
            fn (DoctorDeviceWebAuthnCredential $c): bool => $this->credentialUsability->usable($c),
        );

        $authorization = $authorizations->first(
            static fn (DoctorDeviceAuthorization $a): bool => $a->isActive(),
        );

        if (! $credential instanceof DoctorDeviceWebAuthnCredential || ! $authorization instanceof DoctorDeviceAuthorization) {
            return $this->gate(
                self::GATE_NO_INVALID_DEVICE_STATE,
                self::GATE_UNVERIFIED,
                'Tidak ada ketidakcocokan antara checklist dan gerbang login',
                'no_complete_path_to_compare',
                'readiness',
            );
        }

        try {
            $denyReason = $this->gate->deviceProofDenyReason(
                $device,
                DoctorSessionProof::webAuthn((int) $credential->id),
            );

            $authorizationMissing = $this->gate->activeAuthorizationFor(
                (int) $authorization->doctor_id,
                (int) $device->id,
            ) === null;
        } catch (Throwable) {
            return $this->gate(
                self::GATE_NO_INVALID_DEVICE_STATE,
                self::GATE_UNVERIFIED,
                'Tidak ada ketidakcocokan antara checklist dan gerbang login',
                'gate_comparison_failed',
                'readiness',
            );
        }

        /*
         * THE GLOBAL SWITCH IS NOT THIS TABLET'S FAULT.
         *
         * `DENY_WEBAUTHN_LOGIN_DISABLED` is the estate-wide master switch for
         * browser login. While it is off the gate refuses EVERY correctly
         * provisioned tablet, so treating that refusal as an invalid device
         * state would mark the whole estate NOT READY and make step 7
         * unreachable for a device with nothing wrong with it — the workflow
         * would be telling an operator to fix a tablet in order to change a
         * deployment posture.
         *
         * It is still reported rather than ignored: UNVERIFIED, with the real
         * reason, because "we could not compare" is not "they agree".
         */
        if ($denyReason === DoctorAppLoginGate::DENY_WEBAUTHN_LOGIN_DISABLED) {
            return $this->gate(
                self::GATE_NO_INVALID_DEVICE_STATE,
                self::GATE_UNVERIFIED,
                'Tidak ada ketidakcocokan antara checklist dan gerbang login',
                DoctorAppLoginGate::DENY_WEBAUTHN_LOGIN_DISABLED,
                'readiness',
            );
        }

        $disagrees = $denyReason !== null || $authorizationMissing;

        return $this->gate(
            self::GATE_NO_INVALID_DEVICE_STATE,
            $disagrees ? self::GATE_FAIL : self::GATE_PASS,
            'Tidak ada ketidakcocokan antara checklist dan gerbang login',
            $disagrees ? DoctorGlobalRolloutReadinessService::FINDING_GATE_DISAGREES : null,
            'readiness',
        );
    }
}
