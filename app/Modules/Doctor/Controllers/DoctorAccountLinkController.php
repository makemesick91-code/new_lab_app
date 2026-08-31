<?php

namespace App\Modules\Doctor\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Doctor\Requests\LinkDoctorAccountRequest;
use App\Modules\Doctor\Services\DoctorAccountLinkService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * FEATURE-DOCTOR-ACCOUNT-PERFORMANCE-INCOME-LINKAGE-1
 *
 * Master Data → Relasi Akun Dokter.
 *
 * Thin controller. The route middleware gates entry, the policy re-checks the
 * ability (so the route is never the only guard), and the service owns every
 * eligibility rule and the audited write.
 */
class DoctorAccountLinkController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly DoctorAccountLinkService $accountLinks,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('manageAccountLink', Doctor::class);

        return view('settings.doctors.account-links.index', [
            'doctors' => $this->accountLinks->listForManagement([
                'search' => $request->string('search')->toString() ?: null,
                'link_status' => in_array($request->string('link_status')->toString(), ['linked', 'unlinked'], true)
                    ? $request->string('link_status')->toString()
                    : null,
            ], 15),
            'search' => $request->string('search')->toString(),
            'linkStatus' => $request->string('link_status')->toString(),
            'candidates' => $this->accountLinks->linkableAccounts(),
        ]);
    }

    public function store(LinkDoctorAccountRequest $request, Doctor $doctor): RedirectResponse
    {
        $this->authorize('manageAccountLink', Doctor::class);

        $this->accountLinks->link($doctor, $request->linkedUserId(), $request->confirmsRelink());

        return redirect()
            ->route('settings.doctors.account-links.index')
            ->with('status', 'Akun login berhasil dihubungkan ke data dokter.');
    }

    public function destroy(Doctor $doctor): RedirectResponse
    {
        $this->authorize('manageAccountLink', Doctor::class);

        $this->accountLinks->unlink($doctor);

        return redirect()
            ->route('settings.doctors.account-links.index')
            ->with('status', 'Hubungan akun login dengan data dokter telah diputus.');
    }
}
