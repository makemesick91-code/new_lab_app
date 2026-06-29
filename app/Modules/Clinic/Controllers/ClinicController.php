<?php

namespace App\Modules\Clinic\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\Clinic\Requests\StoreClinicRequest;
use App\Modules\Clinic\Requests\UpdateClinicRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sprint 66.1 — Legacy clinic master is deprecated; all routes redirect to Cabang RME.
 */
class ClinicController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', Clinic::class);

        return $this->redirectToRmeBranches('Master Klinik legacy sudah digantikan oleh Master Cabang RME.');
    }

    public function create(): RedirectResponse
    {
        $this->authorize('create', Clinic::class);

        return $this->redirectToRmeBranches('Master Klinik legacy sudah digantikan oleh Master Cabang RME.');
    }

    public function store(StoreClinicRequest $request): RedirectResponse
    {
        $this->authorize('create', Clinic::class);

        return $this->redirectDeprecatedWrite();
    }

    public function edit(Clinic $clinic): RedirectResponse
    {
        $this->authorize('update', $clinic);

        return $this->redirectToRmeBranches('Master Klinik legacy sudah digantikan oleh Master Cabang RME.');
    }

    public function update(UpdateClinicRequest $request, Clinic $clinic): RedirectResponse
    {
        $this->authorize('update', $clinic);

        return $this->redirectDeprecatedWrite();
    }

    public function destroy(Clinic $clinic): RedirectResponse
    {
        $this->authorize('delete', $clinic);

        return $this->redirectDeprecatedWrite();
    }

    public function activate(Clinic $clinic): RedirectResponse
    {
        $this->authorize('update', $clinic);

        return $this->redirectDeprecatedWrite();
    }

    public function deactivate(Clinic $clinic): RedirectResponse
    {
        $this->authorize('update', $clinic);

        return $this->redirectDeprecatedWrite();
    }

    private function redirectToRmeBranches(string $message): RedirectResponse
    {
        return redirect()
            ->route('settings.branches.index')
            ->with('status', $message);
    }

    private function redirectDeprecatedWrite(): RedirectResponse
    {
        return redirect()
            ->route('settings.branches.index')
            ->with('error', 'Tidak dapat membuat atau mengubah data Klinik legacy. Gunakan Master Cabang RME.');
    }
}
