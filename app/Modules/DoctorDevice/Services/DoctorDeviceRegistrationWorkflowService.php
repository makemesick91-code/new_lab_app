<?php

namespace App\Modules\DoctorDevice\Services;

use App\Models\User;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * DOCTOR-DEVICE-GUIDED-REGISTRATION-WORKFLOW-1 — where a tablet has got to.
 *
 * DERIVATION ONLY, AND THAT IS THE WHOLE POINT.
 *
 * There is no `wizard_status` column and there must not be one. Every step's
 * state is read back out of the security truth that already exists:
 *
 *   1 Data Device          the DoctorDevice row
 *   2 Registrasi WebAuthn  its credentials, judged by the shared usability policy
 *   3 Approval Device      its `status` (PENDING_APPROVAL → ACTIVE)
 *   4 Authorization Dokter its DoctorDeviceAuthorization rows
 *   5 Uji Login            DoctorWebAuthnLiveProofService, via the readiness engine
 *   6 Readiness            DoctorDeviceRegistrationReadinessService
 *   7 Selesai              6, and only 6
 *
 * A stored wizard status would be a SECOND copy of all of that, free to drift
 * the moment anyone revokes a credential from the device page instead of from
 * here — and a "step 7 complete" row survives a revocation that makes the
 * tablet unusable. Deriving costs a few queries and cannot lie.
 *
 * This class therefore decides NOTHING about trust. It reads state, labels it,
 * and says which party can act next. Every actual gate stays in the policies
 * and services it names.
 */
class DoctorDeviceRegistrationWorkflowService
{
    public const STATUS_COMPLETE = 'COMPLETE';

    public const STATUS_CURRENT = 'CURRENT';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_ACTION_REQUIRED = 'ACTION_REQUIRED';

    public const STATUS_FAILED = 'FAILED';

    public const STEP_DEVICE = 'device';

    public const STEP_WEBAUTHN = 'webauthn';

    public const STEP_APPROVAL = 'approval';

    public const STEP_DOCTORS = 'doctors';

    public const STEP_LOGIN_TEST = 'login-test';

    public const STEP_READINESS = 'readiness';

    public const STEP_COMPLETE = 'complete';

    /** The ordered spine of the workflow. */
    public const STEPS = [
        self::STEP_DEVICE,
        self::STEP_WEBAUTHN,
        self::STEP_APPROVAL,
        self::STEP_DOCTORS,
        self::STEP_LOGIN_TEST,
        self::STEP_READINESS,
        self::STEP_COMPLETE,
    ];

    /**
     * The other party, named for the operator rather than for the permission.
     *
     * Supervisor RME files a tablet; only `manage_doctor_devices` enrols its
     * credential and admits it into service. That split is deliberate (D11),
     * so a filer who reaches step 2 or 3 is not looking at a broken screen —
     * they are looking at somebody else's turn, and the page says so.
     */
    public const WAITING_ON_DEVICE_MANAGER = 'Menunggu Super Admin';

    public const WAITING_ON_AUTHORIZATION_APPROVER = 'Menunggu Approval Device Dokter';

    public function __construct(
        private readonly DoctorDeviceCredentialUsabilityPolicy $credentialUsability,
        private readonly DoctorDeviceRegistrationReadinessService $readiness,
    ) {}

    /**
     * @return array{
     *     device:DoctorDevice,
     *     steps:list<array<string,mixed>>,
     *     current_step:string,
     *     readiness:array<string,mixed>,
     *     is_ready:bool
     * }
     */
    public function overview(DoctorDevice $device, User $actor): array
    {
        $readiness = $this->readiness->evaluate($device);
        $credentials = $device->webAuthnCredentials()->orderBy('id')->get();
        $authorizations = $device->authorizations()->with('doctor')->orderBy('id')->get();

        $steps = [
            $this->deviceStep($device, $actor),
            $this->webAuthnStep($device, $credentials, $actor),
            $this->approvalStep($device, $actor),
            $this->doctorsStep($device, $authorizations, $actor),
            $this->loginTestStep($readiness),
            $this->readinessStep($readiness),
            $this->completeStep($readiness),
        ];

        // The first unfinished step is the one the operator is on. Marking it
        // CURRENT is presentation; it never unlocks anything, because the
        // mutations are gated by policy and by their own prerequisites.
        $currentStep = self::STEP_COMPLETE;

        foreach ($steps as $index => $step) {
            if ($step['status'] !== self::STATUS_COMPLETE) {
                $currentStep = (string) $step['key'];

                if ($step['status'] === self::STATUS_PENDING) {
                    $steps[$index]['status'] = self::STATUS_CURRENT;
                }

                break;
            }
        }

        return [
            'device' => $device,
            'steps' => $steps,
            'current_step' => $currentStep,
            'readiness' => $readiness,
            'is_ready' => $readiness['verdict'] === DoctorDeviceRegistrationReadinessService::READY,
        ];
    }

    /**
     * May this actor perform the mutation behind a step?
     *
     * Presentation only — it decides what the sub-sidebar renders, never what
     * the server accepts. Each mutation re-authorizes through its own policy,
     * and a hand-typed URL meets that check with or without this method.
     */
    public function actorCanAct(string $step, DoctorDevice $device, User $actor): bool
    {
        return match ($step) {
            self::STEP_DEVICE => Gate::forUser($actor)->allows('update', $device)
                || Gate::forUser($actor)->allows('register', DoctorDevice::class),
            self::STEP_WEBAUTHN => Gate::forUser($actor)->allows('update', $device),
            self::STEP_APPROVAL => Gate::forUser($actor)->allows('approveRegistration', $device),
            self::STEP_DOCTORS => $actor->can('manage_doctor_device_authorizations'),
            default => true,
        };
    }

    /** @return array<string,mixed> */
    private function step(
        string $key,
        int $number,
        string $label,
        string $status,
        ?string $detail = null,
        ?string $waitingFor = null,
    ): array {
        return [
            'key' => $key,
            'number' => $number,
            'label' => $label,
            'status' => $status,
            'detail' => $detail,
            'waiting_for' => $waitingFor,
        ];
    }

    private function deviceStep(DoctorDevice $device, User $actor): array
    {
        // Reaching the workflow at all means the row exists; filing it IS this
        // step. It is listed so the spine is complete and the metadata stays
        // editable, not because it can be unfinished.
        return $this->step(
            self::STEP_DEVICE,
            1,
            'Data Device',
            self::STATUS_COMPLETE,
            $device->device_name,
            $this->actorCanAct(self::STEP_DEVICE, $device, $actor) ? null : self::WAITING_ON_DEVICE_MANAGER,
        );
    }

    /**
     * @param  Collection<int, DoctorDeviceWebAuthnCredential>  $credentials
     */
    private function webAuthnStep(DoctorDevice $device, $credentials, User $actor): array
    {
        $waiting = $this->actorCanAct(self::STEP_WEBAUTHN, $device, $actor)
            ? null
            : self::WAITING_ON_DEVICE_MANAGER;

        $usable = $credentials->filter(
            fn (DoctorDeviceWebAuthnCredential $c): bool => $this->credentialUsability->usable($c),
        );

        if ($usable->isNotEmpty()) {
            return $this->step(
                self::STEP_WEBAUTHN,
                2,
                'Registrasi WebAuthn',
                self::STATUS_COMPLETE,
                $usable->count().' kredensial dapat dipakai',
                $waiting,
            );
        }

        if ($credentials->isEmpty()) {
            return $this->step(
                self::STEP_WEBAUTHN,
                2,
                'Registrasi WebAuthn',
                self::STATUS_PENDING,
                'Belum ada kredensial',
                $waiting,
            );
        }

        // Credentials exist and none can sign — revoked, syncable, or not
        // user-verified. That is a fault to fix, not a step still to start.
        return $this->step(
            self::STEP_WEBAUTHN,
            2,
            'Registrasi WebAuthn',
            self::STATUS_FAILED,
            'Ada kredensial, tetapi tidak ada yang dapat dipakai',
            $waiting,
        );
    }

    private function approvalStep(DoctorDevice $device, User $actor): array
    {
        $waiting = $this->actorCanAct(self::STEP_APPROVAL, $device, $actor)
            ? null
            : self::WAITING_ON_DEVICE_MANAGER;

        if ($device->isRevoked()) {
            return $this->step(self::STEP_APPROVAL, 3, 'Approval Device', self::STATUS_FAILED, 'Perangkat dicabut', $waiting);
        }

        if ($device->isDisabled()) {
            return $this->step(self::STEP_APPROVAL, 3, 'Approval Device', self::STATUS_FAILED, 'Perangkat dinonaktifkan', $waiting);
        }

        if ($device->isPendingApproval()) {
            return $this->step(
                self::STEP_APPROVAL,
                3,
                'Approval Device',
                self::STATUS_ACTION_REQUIRED,
                'Menunggu persetujuan pendaftaran',
                $waiting,
            );
        }

        return $this->step(self::STEP_APPROVAL, 3, 'Approval Device', self::STATUS_COMPLETE, 'Disetujui', $waiting);
    }

    /**
     * @param  Collection<int, DoctorDeviceAuthorization>  $authorizations
     */
    private function doctorsStep(DoctorDevice $device, $authorizations, User $actor): array
    {
        $waiting = $this->actorCanAct(self::STEP_DOCTORS, $device, $actor)
            ? null
            : self::WAITING_ON_AUTHORIZATION_APPROVER;

        $active = $authorizations->filter(static fn (DoctorDeviceAuthorization $a): bool => $a->isActive());

        if ($active->isNotEmpty()) {
            return $this->step(
                self::STEP_DOCTORS,
                4,
                'Authorization Dokter',
                self::STATUS_COMPLETE,
                $active->count().' dokter aktif',
                $waiting,
            );
        }

        $pending = $authorizations->filter(
            static fn (DoctorDeviceAuthorization $a): bool => $a->status === DoctorDeviceAuthorization::STATUS_PENDING,
        );

        if ($pending->isNotEmpty()) {
            // Filed here, decided in the approval inbox. The wizard never
            // approves its own request.
            return $this->step(
                self::STEP_DOCTORS,
                4,
                'Authorization Dokter',
                self::STATUS_ACTION_REQUIRED,
                $pending->count().' permintaan menunggu persetujuan',
                self::WAITING_ON_AUTHORIZATION_APPROVER,
            );
        }

        return $this->step(
            self::STEP_DOCTORS,
            4,
            'Authorization Dokter',
            self::STATUS_PENDING,
            'Belum ada dokter yang diajukan',
            $waiting,
        );
    }

    /** @param  array<string,mixed>  $readiness */
    private function loginTestStep(array $readiness): array
    {
        $proof = (string) ($readiness['proof']['live_assertion_proof']
            ?? DoctorWebAuthnLiveProofService::PROOF_NEVER_PROVEN);

        return match ($proof) {
            DoctorWebAuthnLiveProofService::PROOF_PASS => $this->step(
                self::STEP_LOGIN_TEST, 5, 'Uji Login', self::STATUS_COMPLETE, 'Bukti login terbaru terverifikasi',
            ),
            DoctorWebAuthnLiveProofService::PROOF_STALE => $this->step(
                self::STEP_LOGIN_TEST, 5, 'Uji Login', self::STATUS_ACTION_REQUIRED, 'Bukti login sudah kedaluwarsa',
            ),
            DoctorWebAuthnLiveProofService::PROOF_UNVERIFIED => $this->step(
                self::STEP_LOGIN_TEST, 5, 'Uji Login', self::STATUS_ACTION_REQUIRED, 'Bukti login belum dapat diukur',
            ),
            default => $this->step(
                self::STEP_LOGIN_TEST, 5, 'Uji Login', self::STATUS_PENDING, 'Belum pernah ada login berhasil',
            ),
        };
    }

    /** @param  array<string,mixed>  $readiness */
    private function readinessStep(array $readiness): array
    {
        if ($readiness['verdict'] === DoctorDeviceRegistrationReadinessService::READY) {
            return $this->step(self::STEP_READINESS, 6, 'Verifikasi Readiness', self::STATUS_COMPLETE, 'Semua gate lulus');
        }

        return $this->step(
            self::STEP_READINESS,
            6,
            'Verifikasi Readiness',
            self::STATUS_ACTION_REQUIRED,
            count($readiness['failed_gates']).' gate belum lulus',
        );
    }

    /** @param  array<string,mixed>  $readiness */
    private function completeStep(array $readiness): array
    {
        $ready = $readiness['verdict'] === DoctorDeviceRegistrationReadinessService::READY;

        return $this->step(
            self::STEP_COMPLETE,
            7,
            'Selesai',
            $ready ? self::STATUS_COMPLETE : self::STATUS_PENDING,
            $ready ? 'Siap dipakai klinis' : 'Belum siap dipakai klinis',
        );
    }
}
