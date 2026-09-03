<?php

namespace App\Modules\DoctorDevice\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchService;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Requests\DoctorDeviceAuthorizationReasonRequest;
use App\Modules\DoctorDevice\Services\DoctorDeviceAuthorizationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Approval → Approval Device Dokter.
 *
 * A SEPARATE SURFACE FROM MASTER DATA → DEVICE DOKTER, on purpose:
 *
 *   Device Dokter            the physical device registry and its security
 *                            lifecycle — a Super Admin concern
 *   Approval Device Dokter   an inbox of "may this doctor use this device?"
 *                            requests — day-to-day operational work
 *
 * Thin, as the architecture requires: every rule lives in
 * DoctorDeviceAuthorizationService, and every action re-validates under a lock
 * there rather than trusting the state the screen was rendered with.
 *
 * There is no `destroy`. An authorization is security history; trust is
 * withdrawn with REVOKE.
 */
class DoctorDeviceAuthorizationController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly DoctorDeviceAuthorizationService $authorizations,
        private readonly BranchService $branches,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', DoctorDeviceAuthorization::class);

        // PENDING by default: the point of this screen is the work waiting to
        // be done, not a browsable archive.
        $status = $request->string('status')->toString() ?: DoctorDeviceAuthorization::STATUS_PENDING;

        if ($status === 'all') {
            $status = null;
        }

        $filters = [
            'status' => $status,
            'search' => $request->string('search')->toString() ?: null,
            'branch_id' => $request->integer('branch_id') ?: null,
        ];

        return view('doctor-device-authorizations.index', [
            'authorizations' => $this->authorizations->paginate($filters),
            'filters' => $filters + ['status' => $status ?? 'all'],
            'statuses' => DoctorDeviceAuthorization::STATUSES,
            'branches' => $this->branches->listRmeEnabled(),
            'pendingCount' => $this->authorizations->countPending(),
        ]);
    }

    public function show(DoctorDeviceAuthorization $authorization): View
    {
        $this->authorize('view', $authorization);

        $authorization->load([
            'doctor', 'device.branch', 'requestedBy',
            'approvedBy', 'rejectedBy', 'revokedBy', 'reRequestAllowedBy',
        ]);

        return view('doctor-device-authorizations.show', ['authorization' => $authorization]);
    }

    public function approve(Request $request, DoctorDeviceAuthorization $authorization): RedirectResponse
    {
        $this->authorize('decide', $authorization);

        $this->authorizations->approve($authorization, $request->user());

        return redirect()
            ->route('doctor-device-authorizations.show', $authorization)
            ->with('status', 'Akses dokter untuk perangkat ini disetujui.');
    }

    public function reject(
        DoctorDeviceAuthorizationReasonRequest $request,
        DoctorDeviceAuthorization $authorization,
    ): RedirectResponse {
        $this->authorizations->reject($authorization, $request->validated()['reason'], $request->user());

        return redirect()
            ->route('doctor-device-authorizations.show', $authorization)
            ->with('status', 'Permintaan ditolak.');
    }

    public function revoke(
        DoctorDeviceAuthorizationReasonRequest $request,
        DoctorDeviceAuthorization $authorization,
    ): RedirectResponse {
        $this->authorizations->revoke($authorization, $request->validated()['reason'], $request->user());

        return redirect()
            ->route('doctor-device-authorizations.show', $authorization)
            ->with('status', 'Akses dokter untuk perangkat ini dicabut.');
    }

    /**
     * Let a refused pair be asked for again. This approves nothing: the doctor
     * still has to attempt a login from that device, and an approver still has
     * to decide.
     */
    public function allowReRequest(Request $request, DoctorDeviceAuthorization $authorization): RedirectResponse
    {
        $this->authorize('decide', $authorization);

        $this->authorizations->allowReRequest($authorization, $request->user());

        return redirect()
            ->route('doctor-device-authorizations.show', $authorization)
            ->with('status', 'Dokter diizinkan mengajukan ulang dari perangkat ini.');
    }
}
