<?php

namespace App\Modules\Satusehat\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Satusehat\Models\SatusehatUatRun;
use App\Modules\Satusehat\Requests\SatusehatUatRequest;
use App\Modules\Satusehat\Services\Pilot\SatusehatUatService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * SATUSEHAT-4D — human operator UAT workflow. A run reaches SIGNED_OFF only when
 * every required role approves and no scenario failed; that signed-off state is
 * the mandatory precondition for an operational GO. Automated tests never
 * substitute for real human sign-off. Evidence stays synthetic / PII-safe.
 */
class SatusehatUatController extends Controller
{
    public function __construct(
        private readonly SatusehatUatService $service,
    ) {}

    public function index(): View
    {
        $runs = SatusehatUatRun::query()
            ->where('environment', (string) config('satusehat.environment'))
            ->withCount('scenarios', 'signoffs')
            ->orderByDesc('id')->paginate(25);

        return view('satusehat.uat.index', [
            'runs' => $runs,
            'requiredRoles' => (array) config('satusehat_pilot.uat.required_signoff_roles', []),
        ]);
    }

    public function show(int $run): View
    {
        $model = $this->find($run);
        $model->load(['scenarios', 'signoffs']);

        return view('satusehat.uat.show', [
            'run' => $model,
            'requiredRoles' => (array) config('satusehat_pilot.uat.required_signoff_roles', []),
        ]);
    }

    public function store(SatusehatUatRequest $request): RedirectResponse
    {
        $run = $this->service->createRun($request->validated(), $request->user());

        return redirect()->route('satusehat.uat.show', $run->id)->with('status', 'Sesi UAT dibuat.');
    }

    public function scenario(SatusehatUatRequest $request, int $run): RedirectResponse
    {
        $this->service->recordScenario($this->find($run), $request->validated(), $request->user());

        return back()->with('status', 'Hasil skenario UAT dicatat.');
    }

    public function signoff(SatusehatUatRequest $request, int $run): RedirectResponse
    {
        $this->service->recordSignoff($this->find($run), $request->validated(), $request->user());

        return back()->with('status', 'Sign-off UAT dicatat.');
    }

    public function finalize(SatusehatUatRequest $request, int $run): RedirectResponse
    {
        $this->service->finalize($this->find($run), $request->user());

        return back()->with('status', 'Sesi UAT disetujui penuh (signed off).');
    }

    public function reject(SatusehatUatRequest $request, int $run): RedirectResponse
    {
        $this->service->reject($this->find($run), (string) $request->input('reason', ''), $request->user());

        return back()->with('status', 'Sesi UAT ditolak.');
    }

    private function find(int $id): SatusehatUatRun
    {
        $model = SatusehatUatRun::query()
            ->where('environment', (string) config('satusehat.environment'))->find($id);
        abort_if($model === null, 404);

        return $model;
    }
}
