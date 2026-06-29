<?php

namespace App\Modules\Doctor\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Doctor\Requests\StoreDoctorRequest;
use App\Modules\Doctor\Requests\UpdateDoctorRequest;
use App\Modules\Doctor\Services\DoctorService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DoctorController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly DoctorService $doctorService,
        private readonly BranchService $branchService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Doctor::class);

        return view('settings.doctors.index', [
            'doctors' => $this->doctorService->list([
                'search' => $request->string('search')->toString() ?: null,
                'branch_id' => $request->integer('branch_id') ?: null,
            ], 10),
            'search' => $request->string('search')->toString(),
            'branchId' => $request->integer('branch_id') ?: null,
            'rmeBranches' => $this->branchService->listRmeEnabled(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Doctor::class);

        return view('settings.doctors.create', [
            'rmeBranches' => $this->branchService->listRmeEnabled(),
        ]);
    }

    public function store(StoreDoctorRequest $request): RedirectResponse
    {
        $this->authorize('create', Doctor::class);

        $this->doctorService->create($request->validated());

        return redirect()->route('settings.doctors.index')->with('status', 'Dokter berhasil dibuat.');
    }

    public function edit(Doctor $doctor): View
    {
        $this->authorize('update', $doctor);

        $doctor->load('branches');

        return view('settings.doctors.edit', [
            'doctor' => $doctor,
            'rmeBranches' => $this->branchService->listRmeEnabled(),
        ]);
    }

    public function update(UpdateDoctorRequest $request, Doctor $doctor): RedirectResponse
    {
        $this->authorize('update', $doctor);

        $this->doctorService->update($doctor, $request->validated());

        return redirect()->route('settings.doctors.index')->with('status', 'Dokter berhasil diperbarui.');
    }

    public function destroy(Doctor $doctor): RedirectResponse
    {
        $this->authorize('delete', $doctor);

        $this->doctorService->delete($doctor);

        return redirect()->route('settings.doctors.index')->with('status', 'Dokter berhasil dihapus.');
    }

    public function activate(Doctor $doctor): RedirectResponse
    {
        $this->authorize('update', $doctor);

        $this->doctorService->activate($doctor);

        return redirect()->route('settings.doctors.index')->with('status', 'Dokter berhasil diaktifkan.');
    }

    public function deactivate(Doctor $doctor): RedirectResponse
    {
        $this->authorize('update', $doctor);

        $this->doctorService->deactivate($doctor);

        return redirect()->route('settings.doctors.index')->with('status', 'Dokter berhasil dinonaktifkan.');
    }
}
