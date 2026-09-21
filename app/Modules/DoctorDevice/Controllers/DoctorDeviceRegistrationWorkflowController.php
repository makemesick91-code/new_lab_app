<?php

namespace App\Modules\DoctorDevice\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorDevice\Interfaces\DoctorDeviceRolloutReadinessRepositoryInterface;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Requests\StoreDoctorDeviceAuthorizationRequest;
use App\Modules\DoctorDevice\Services\DoctorDeviceAuthorizationService;
use App\Modules\DoctorDevice\Services\DoctorDeviceRegistrationReadinessService;
use App\Modules\DoctorDevice\Services\DoctorDeviceRegistrationWorkflowService;
use App\Modules\DoctorDevice\Services\DoctorDeviceService;
use App\Modules\DoctorDevice\Services\DoctorGlobalRolloutReadinessService;
use App\Modules\DoctorDevice\Support\WebAuthnRelyingParty;
use App\Modules\LabOrder\Services\AuditLogService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * DOCTOR-DEVICE-GUIDED-REGISTRATION-WORKFLOW-1 — Pendaftaran Device Dokter.
 *
 * A GUIDE over the existing surfaces, not a second copy of them. Every step
 * except one posts to the mutation route that already owns it:
 *
 *   step 1  settings.doctor-devices.store / .update
 *   step 2  settings.doctor-devices.webauthn.options / .store / .revoke
 *   step 3  settings.doctor-devices.approve-registration
 *   step 4  THIS controller — the only new mutation, and it can only file a
 *           PENDING request (see `storeDoctors`)
 *   step 5–7 read-only
 *
 * TWO PARTIES, ONE WORKFLOW. Supervisor RME files a tablet
 * (`register_doctor_devices`); only `manage_doctor_devices` enrols its
 * credential and admits it into service. The workflow is therefore shared
 * rather than single-operator: a filer sees steps 2 and 3 as "Menunggu Super
 * Admin" instead of a broken button. That posture is presentation — each
 * mutation re-authorizes through its own policy regardless of what was drawn.
 *
 * This class holds no authorization logic of its own and no workflow state.
 */
class DoctorDeviceRegistrationWorkflowController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly DoctorDeviceRegistrationWorkflowService $workflow,
        private readonly DoctorDeviceRegistrationReadinessService $readiness,
        private readonly DoctorDeviceAuthorizationService $authorizations,
        private readonly DoctorDeviceService $devices,
        private readonly BranchService $branches,
        private readonly DoctorDeviceRolloutReadinessRepositoryInterface $estate,
        private readonly AuditLogService $auditLogs,
    ) {}

    /** The board: which tablets are mid-registration, and where each has got to. */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', DoctorDevice::class);

        $devices = $this->devices->paginate(
            ['search' => $request->string('search')->toString() ?: null],
            20,
        );

        $rows = collect($devices->items())->map(function (DoctorDevice $device) use ($request): array {
            $overview = $this->workflow->overview($device, $request->user());

            return [
                'device' => $device,
                'steps' => $overview['steps'],
                'current_step' => $overview['current_step'],
                'is_ready' => $overview['is_ready'],
            ];
        });

        return view('settings.doctor-device-registration.index', [
            'devices' => $devices,
            'rows' => $rows,
            'search' => $request->string('search')->toString(),
        ]);
    }

    /** Step 1, for a tablet that does not exist yet. Posts to the existing store route. */
    public function create(): View
    {
        // `register`, matching StoreDoctorDeviceRequest and the existing device
        // create page. Opening the form is the filing authority.
        $this->authorize('register', DoctorDevice::class);

        return view('settings.doctor-device-registration.create', [
            'branches' => $this->branches->listRmeEnabled(),
        ]);
    }

    public function show(DoctorDevice $registration, Request $request): View
    {
        $this->authorize('view', $registration);

        return view('settings.doctor-device-registration.show', $this->shell($registration, $request, 'ringkasan'));
    }

    public function device(DoctorDevice $registration, Request $request): View
    {
        $this->authorize('view', $registration);

        return view('settings.doctor-device-registration.device', $this->shell($registration, $request, 'device') + [
            'branches' => $this->branches->listRmeEnabled(),
        ]);
    }

    /**
     * Step 2 — opened ON the tablet being registered.
     *
     * The view posts to the EXISTING WebAuthn routes, so the ceremony, the
     * challenge store and the device-binding verdict are all unchanged. This
     * method only assembles the same view data the device-page version builds.
     */
    public function webauthn(DoctorDevice $registration, Request $request): View
    {
        $this->authorize('view', $registration);

        $relyingParty = WebAuthnRelyingParty::fromConfig();

        return view('settings.doctor-device-registration.webauthn', $this->shell($registration, $request, 'webauthn') + [
            'credentials' => $registration->webAuthnCredentials()->orderBy('id')->get(),
            'configurationFailure' => $relyingParty->usabilityFailure(),
            'requiresDeviceBound' => (bool) config('webauthn.device_binding.require_device_bound', true),
            'canEnrol' => $request->user()->can('update', $registration),
        ]);
    }

    public function approval(DoctorDevice $registration, Request $request): View
    {
        $this->authorize('view', $registration);

        return view('settings.doctor-device-registration.approval', $this->shell($registration, $request, 'approval') + [
            'canApprove' => $request->user()->can('approveRegistration', $registration),
        ]);
    }

    /** Step 4 — which doctors may use this tablet. */
    public function doctors(DoctorDevice $registration, Request $request): View
    {
        $this->authorize('view', $registration);

        return view('settings.doctor-device-registration.doctors', $this->shell($registration, $request, 'doctors') + [
            'candidates' => $this->doctorCandidates($registration),
            'canRequest' => $request->user()->can('requestForDevice', DoctorDeviceAuthorization::class),
        ]);
    }

    /**
     * Step 4's mutation — the only one this workflow adds.
     *
     * It FILES requests. `resolveOrRequest()` can only ever produce a PENDING
     * row, so no path through here approves anything; the decision stays in
     * Approval Device Dokter behind `decide`. An existing row is returned
     * untouched, which is why a revoked or rejected pair is REPORTED rather
     * than quietly reopened — reopening is `allowReRequest`, a privileged act
     * this workflow does not perform.
     *
     * PREREQUISITE, ENFORCED SERVER-SIDE. A tablet still awaiting approval
     * cannot accumulate doctor authorizations by someone typing the URL. The
     * page renders and explains; the write refuses.
     */
    public function storeDoctors(
        DoctorDevice $registration,
        StoreDoctorDeviceAuthorizationRequest $request,
    ): RedirectResponse {
        $this->authorize('view', $registration);

        if (! $registration->isActive()) {
            return redirect()
                ->route('settings.doctor-device-registration.approval', $registration)
                ->withErrors(['doctor_ids' => 'Device belum disetujui. Setujui pendaftaran perangkat terlebih dahulu.']);
        }

        $filed = [];
        $skipped = [];

        foreach ($request->doctorIds() as $doctorId) {
            $doctor = Doctor::query()->find($doctorId);

            if (! $doctor instanceof Doctor || $doctor->is_active !== true) {
                $skipped[] = $doctorId;

                continue;
            }

            $authorization = $this->authorizations->resolveOrRequest(
                $doctor,
                $registration,
                $request->user(),
                DoctorDeviceAuthorization::SOURCE_ADMIN,
            );

            // Say what HAPPENED, not what was asked for. A pair that was
            // already revoked comes back revoked, and reporting it as "filed"
            // would tell the operator to wait for an approval that will never
            // appear in the inbox.
            if ($authorization->status === DoctorDeviceAuthorization::STATUS_PENDING) {
                $filed[] = $doctorId;

                continue;
            }

            $skipped[] = $doctorId;
        }

        // Counts only. Which doctor was proposed for which tablet is already on
        // the authorization rows themselves, and a payload here would be a
        // second, unaudited copy of the estate map.
        $this->auditLogs->log(
            'mst_doctor_devices',
            (int) $registration->id,
            'DOCTOR_DEVICE_AUTHORIZATION_REQUESTED_FROM_REGISTRATION',
            null,
            ['filed' => count($filed), 'unchanged' => count($skipped)],
            $request->user(),
        );

        return redirect()
            ->route('settings.doctor-device-registration.doctors', $registration)
            ->with('status', $filed === []
                ? 'Tidak ada permintaan baru yang dibuat. Periksa status dokter pada tabel di bawah.'
                : count($filed).' permintaan otorisasi diajukan dan menunggu Approval Device Dokter.');
    }

    /**
     * Step 5 — a REPORT on whether a real login happened, never a control that
     * declares one did.
     *
     * There is deliberately no action on this page and no route that could
     * record a pass. The verdict comes from audit rows written by the login
     * path itself, attributed to the device the assertion was performed on.
     */
    public function loginTest(DoctorDevice $registration, Request $request): View
    {
        $this->authorize('view', $registration);

        $shell = $this->shell($registration, $request, 'login-test');

        return view('settings.doctor-device-registration.login-test', $shell + [
            'authorizations' => $registration->authorizations()
                ->with('doctor')
                ->orderBy('id')
                ->get()
                ->filter(static fn (DoctorDeviceAuthorization $a): bool => $a->isActive()),
        ]);
    }

    public function readiness(DoctorDevice $registration, Request $request): View
    {
        $this->authorize('view', $registration);

        return view('settings.doctor-device-registration.readiness', $this->shell($registration, $request, 'readiness'));
    }

    /**
     * Step 7 — reachable only when readiness actually passes.
     *
     * Not a cosmetic guard: "READY FOR CLINICAL USE" is the one sentence in
     * this workflow an operator will act on without re-reading the checklist,
     * so the page that says it refuses to render when it is not true.
     */
    public function complete(DoctorDevice $registration, Request $request): View|RedirectResponse
    {
        $this->authorize('view', $registration);

        $shell = $this->shell($registration, $request, 'complete');

        if (! $shell['overview']['is_ready']) {
            return redirect()
                ->route('settings.doctor-device-registration.readiness', $registration)
                ->withErrors(['readiness' => 'Perangkat belum lulus verifikasi readiness.']);
        }

        return view('settings.doctor-device-registration.complete', $shell + [
            'authorizations' => $registration->authorizations()
                ->with('doctor')
                ->orderBy('id')
                ->get()
                ->filter(static fn (DoctorDeviceAuthorization $a): bool => $a->isActive()),
        ]);
    }

    public function history(DoctorDevice $registration, Request $request): View
    {
        $this->authorize('view', $registration);

        return view('settings.doctor-device-registration.history', $this->shell($registration, $request, 'riwayat') + [
            'authorizations' => $registration->authorizations()->with('doctor')->orderByDesc('id')->get(),
            'credentials' => $registration->webAuthnCredentials()->orderByDesc('id')->get(),
        ]);
    }

    /**
     * The data every workflow page needs: the device, the derived spine, and
     * which sub-sidebar entry is active.
     *
     * @return array<string,mixed>
     */
    private function shell(DoctorDevice $device, Request $request, string $active): array
    {
        $overview = $this->workflow->overview($device, $request->user());

        return [
            'device' => $device,
            'overview' => $overview,
            'steps' => $overview['steps'],
            'readinessReport' => $overview['readiness'],
            'activeStep' => $active,
        ];
    }

    /**
     * Candidate doctors for step 4, with an honest reason when one cannot be
     * authorized.
     *
     * The population and the exclusion vocabulary are the fleet engine's — a
     * doctor with no linked account cannot log in on any tablet, and inventing
     * a second word for that here would be a second answer to the same
     * question. Ineligible doctors are SHOWN and disabled rather than hidden,
     * because "the doctor is not in the list" is indistinguishable from "the
     * list is broken".
     *
     * @return Collection<int, array<string,mixed>>
     */
    private function doctorCandidates(DoctorDevice $device): Collection
    {
        $accounts = $this->estate->doctorAccounts();
        $records = $this->estate->doctorRecordsForUsers(
            $accounts->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
        );

        $existing = $device->authorizations()
            ->get()
            ->keyBy(static fn (DoctorDeviceAuthorization $a): int => (int) $a->doctor_id);

        return $accounts->map(function ($account) use ($records, $existing): array {
            $record = $records->get((int) $account->id);

            $reason = match (true) {
                $record === null => DoctorGlobalRolloutReadinessService::REASON_DOCTOR_NOT_LINKED,
                // STRICT !== true, mirroring the readiness engine: a NULL
                // column is not "active", and a loose comparison reads it as one.
                $record->is_active !== true => DoctorGlobalRolloutReadinessService::REASON_DOCTOR_INACTIVE,
                default => null,
            };

            $authorization = $record === null ? null : $existing->get((int) $record->id);

            return [
                'doctor_id' => $record?->id === null ? null : (int) $record->id,
                'name' => (string) ($record->name ?? $account->name ?? ''),
                'ineligible_reason' => $reason,
                'authorization' => $authorization,
                'selectable' => $reason === null && $authorization === null,
            ];
        })->values();
    }
}
