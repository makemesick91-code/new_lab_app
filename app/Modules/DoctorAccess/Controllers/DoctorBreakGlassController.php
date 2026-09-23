<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\DoctorAccess\Interfaces\DoctorBreakGlassGrantRepositoryInterface;
use App\Modules\DoctorAccess\Models\DoctorBreakGlassGrant;
use App\Modules\DoctorAccess\Requests\RevokeDoctorBreakGlassGrantRequest;
use App\Modules\DoctorAccess\Requests\StoreDoctorBreakGlassGrantRequest;
use App\Modules\DoctorAccess\Services\DoctorBreakGlassService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * REVISION-DOCTOR-PWA-WEBAUTHN-ONLY-ACCESS-1 Stage 2 — the emergency-access
 * console.
 *
 * Thin on purpose. Every rule — who may grant, what a usable reason is, how
 * long a window may be, whether a second grant may overlap — lives in
 * DoctorBreakGlassService, because a console command or a future caller that
 * never passes through this controller must obey exactly the same rules.
 */
class DoctorBreakGlassController extends Controller
{
    public function __construct(
        private readonly DoctorBreakGlassService $breakGlass,
        private readonly DoctorBreakGlassGrantRepositoryInterface $grants,
    ) {}

    public function index(Request $request): View
    {
        return view('rme.doctor-break-glass.index', [
            'grants' => $this->grants->paginate(20),
            // Doctor ACCOUNTS, because a grant admits a login. Ordered by name
            // so an operator picking under pressure is not reading ids.
            'doctorAccounts' => User::query()
                ->whereHas('roles', fn ($q) => $q->where('name', 'Doctor'))
                ->orderBy('name')
                ->get(['id', 'name']),
            'maxHours' => (int) config('doctor_access.break_glass.max_hours', 12),
            'reasonMin' => (int) config('doctor_access.reason.min_length', 10),
        ]);
    }

    public function store(StoreDoctorBreakGlassGrantRequest $request): RedirectResponse
    {
        $target = User::query()->findOrFail((int) $request->integer('user_id'));

        $grant = $this->breakGlass->grant(
            $target,
            $request->user(),
            (string) $request->string('reason'),
            (int) $request->integer('hours'),
        );

        return redirect()
            ->route('rme.doctor-break-glass.index')
            ->with('success', 'Akses darurat diberikan untuk '.$target->name
                .' sampai '.$grant->expires_at->format('d/m/Y H:i').'.');
    }

    public function revoke(
        RevokeDoctorBreakGlassGrantRequest $request,
        DoctorBreakGlassGrant $grant,
    ): RedirectResponse {
        $this->breakGlass->revoke($grant, $request->user(), (string) $request->string('reason'));

        return redirect()
            ->route('rme.doctor-break-glass.index')
            ->with('success', 'Akses darurat dicabut. Sesi dokter tersebut berhenti pada permintaan berikutnya.');
    }
}
