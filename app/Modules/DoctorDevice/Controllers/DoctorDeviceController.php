<?php

namespace App\Modules\DoctorDevice\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchService;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceEnrollment;
use App\Modules\DoctorDevice\Requests\ApproveDoctorDeviceEnrollmentRequest;
use App\Modules\DoctorDevice\Requests\DoctorDeviceReasonRequest;
use App\Modules\DoctorDevice\Requests\StoreDoctorDeviceRequest;
use App\Modules\DoctorDevice\Requests\UpdateDoctorDeviceRequest;
use App\Modules\DoctorDevice\Services\DoctorDeviceEnrollmentService;
use App\Modules\DoctorDevice\Services\DoctorDeviceService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Master Data → Device Dokter. Thin: every rule lives in DoctorDeviceService.
 *
 * There is no `destroy` action — a device that has ever been trusted keeps its
 * security history, so trust is withdrawn with `revoke`, never deleted.
 */
class DoctorDeviceController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly DoctorDeviceService $devices,
        private readonly BranchService $branches,
        private readonly DoctorDeviceEnrollmentService $enrollments,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', DoctorDevice::class);

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'branch_id' => $request->integer('branch_id') ?: null,
        ];

        return view('settings.doctor-devices.index', [
            'devices' => $this->devices->paginate($filters),
            'filters' => $filters,
            'statuses' => DoctorDevice::STATUSES,
            'branches' => $this->branches->listRmeEnabled(),
            // Phase 3 — Android installs waiting for an administrator to pair
            // them. Shown here so approval lives on the ONE device surface.
            'pendingEnrollments' => DoctorDeviceEnrollment::query()
                ->where('status', DoctorDeviceEnrollment::STATUS_PENDING)
                ->where('expires_at', '>', now())
                ->orderByDesc('id')
                ->limit(25)
                ->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', DoctorDevice::class);

        return view('settings.doctor-devices.create', [
            'branches' => $this->branches->listRmeEnabled(),
        ]);
    }

    public function store(StoreDoctorDeviceRequest $request): RedirectResponse
    {
        $device = $this->devices->register($request->validated(), $request->user());

        return redirect()
            ->route('settings.doctor-devices.show', $device)
            ->with('status', 'Perangkat berhasil didaftarkan.');
    }

    public function show(DoctorDevice $doctorDevice): View
    {
        $this->authorize('view', $doctorDevice);

        $doctorDevice->load(['branch', 'registeredBy', 'disabledBy', 'revokedBy']);

        return view('settings.doctor-devices.show', ['device' => $doctorDevice]);
    }

    public function edit(DoctorDevice $doctorDevice): View
    {
        $this->authorize('update', $doctorDevice);

        return view('settings.doctor-devices.edit', [
            'device' => $doctorDevice,
            'branches' => $this->branches->listRmeEnabled(),
        ]);
    }

    public function update(UpdateDoctorDeviceRequest $request, DoctorDevice $doctorDevice): RedirectResponse
    {
        $this->devices->updateMetadata($doctorDevice, $request->validated(), $request->user());

        return redirect()
            ->route('settings.doctor-devices.show', $doctorDevice)
            ->with('status', 'Data perangkat diperbarui.');
    }

    public function disable(DoctorDeviceReasonRequest $request, DoctorDevice $doctorDevice): RedirectResponse
    {
        $this->devices->disable($doctorDevice, $request->validated()['reason'], $request->user());

        return redirect()
            ->route('settings.doctor-devices.show', $doctorDevice)
            ->with('status', 'Perangkat dinonaktifkan.');
    }

    public function reactivate(Request $request, DoctorDevice $doctorDevice): RedirectResponse
    {
        $this->authorize('manageLifecycle', $doctorDevice);

        $this->devices->reactivate($doctorDevice, $request->user());

        return redirect()
            ->route('settings.doctor-devices.show', $doctorDevice)
            ->with('status', 'Perangkat diaktifkan kembali.');
    }

    public function revoke(DoctorDeviceReasonRequest $request, DoctorDevice $doctorDevice): RedirectResponse
    {
        $this->devices->revoke($doctorDevice, $request->validated()['reason'], $request->user());

        return redirect()
            ->route('settings.doctor-devices.show', $doctorDevice)
            ->with('status', 'Perangkat dicabut permanen (revoked).');
    }

    /**
     * Phase 3 — approve a pending Android pairing and bind its public key to a
     * registry device. Approval authorises a proof ATTEMPT; the device only
     * becomes cryptographically verified once it signs a challenge.
     */
    public function approveEnrollment(
        ApproveDoctorDeviceEnrollmentRequest $request,
        DoctorDeviceEnrollment $enrollment,
    ): RedirectResponse {
        $validated = $request->validated();

        // PHASE4A-DOCTOR-ANDROID-PILOT-ACTIVATION-1 — two ways in, one binding
        // path. An existing ACTIVE registry row, or a row created for this
        // pairing when the registry has nothing to offer. Either way the key
        // itself is bound by the service, from the verified enrolment.
        $approved = $request->createsNewDevice()
            ? $this->enrollments->approveIntoNewDevice(
                $enrollment,
                [
                    'device_name' => $validated['device_name'],
                    'branch_id' => (int) $validated['branch_id'],
                ],
                $request->user(),
            )
            : $this->enrollments->approve(
                $enrollment,
                DoctorDevice::query()->findOrFail($validated['doctor_device_id']),
                $request->user(),
            );

        return redirect()
            ->route('settings.doctor-devices.show', $approved->doctor_device_id)
            ->with('status', 'Pendaftaran perangkat disetujui. Perangkat harus membuktikan kunci sebelum terverifikasi.');
    }

    public function rejectEnrollment(
        DoctorDeviceReasonRequest $request,
        DoctorDeviceEnrollment $enrollment,
    ): RedirectResponse {
        $this->enrollments->reject($enrollment, $request->validated()['reason'], $request->user());

        return redirect()
            ->route('settings.doctor-devices.index')
            ->with('status', 'Pendaftaran perangkat ditolak.');
    }
}
