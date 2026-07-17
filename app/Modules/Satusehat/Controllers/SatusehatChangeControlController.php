<?php

namespace App\Modules\Satusehat\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Satusehat\Models\SatusehatChangeRequest;
use App\Modules\Satusehat\Requests\SatusehatChangeControlRequest;
use App\Modules\Satusehat\Services\Pilot\SatusehatChangeControlService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * SATUSEHAT-4D — governance change-control. Blocked categories
 * (production_guard_config, credential_state) can never be approved/applied;
 * separation of duties (requester != approver) is enforced by the service.
 */
class SatusehatChangeControlController extends Controller
{
    public function __construct(
        private readonly SatusehatChangeControlService $service,
    ) {}

    public function index(): View
    {
        $requests = SatusehatChangeRequest::query()
            ->where('environment', (string) config('satusehat.environment'))
            ->orderByDesc('id')->paginate(25);

        return view('satusehat.change-control.index', [
            'requests' => $requests,
            'categories' => (array) config('satusehat_pilot.change_control.categories', []),
            'blocked' => (array) config('satusehat_pilot.change_control.blocked_categories', []),
        ]);
    }

    public function store(SatusehatChangeControlRequest $request): RedirectResponse
    {
        $this->service->create($request->validated(), $request->user());

        return back()->with('status', 'Change request dibuat.');
    }

    public function review(Request $request, int $changeRequest): RedirectResponse
    {
        $this->service->review($this->find($changeRequest), $request->user());

        return back()->with('status', 'Change request ditinjau.');
    }

    public function approve(Request $request, int $changeRequest): RedirectResponse
    {
        $this->service->approve($this->find($changeRequest), $request->user());

        return back()->with('status', 'Change request disetujui.');
    }

    public function reject(SatusehatChangeControlRequest $request, int $changeRequest): RedirectResponse
    {
        $this->service->reject($this->find($changeRequest), (string) $request->input('reason', ''), $request->user());

        return back()->with('status', 'Change request ditolak.');
    }

    public function apply(Request $request, int $changeRequest): RedirectResponse
    {
        $this->service->markApplied($this->find($changeRequest), $request->user());

        return back()->with('status', 'Change request diterapkan.');
    }

    private function find(int $id): SatusehatChangeRequest
    {
        $model = SatusehatChangeRequest::query()
            ->where('environment', (string) config('satusehat.environment'))->find($id);
        abort_if($model === null, 404);

        return $model;
    }
}
