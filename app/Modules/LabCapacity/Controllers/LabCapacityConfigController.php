<?php

namespace App\Modules\LabCapacity\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LabCapacity\Models\LabServiceWorkloadProfile;
use App\Modules\LabCapacity\Models\TechnicianAvailabilityOverride;
use App\Modules\LabCapacity\Models\TechnicianCapability;
use App\Modules\LabCapacity\Models\TechnicianCapacityProfile;
use App\Modules\LabCapacity\Requests\StoreAvailabilityOverrideRequest;
use App\Modules\LabCapacity\Requests\StoreCapabilityRequest;
use App\Modules\LabCapacity\Requests\StoreCapacityProfileRequest;
use App\Modules\LabCapacity\Requests\StoreWorkloadProfileRequest;
use App\Modules\LabCapacity\Services\LabCapacityConfigService;
use App\Modules\LabService\Models\LabService;
use App\Modules\Technician\Services\TechnicianAssignmentEligibility;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * LAB-PROD-3 — Capacity configuration management (manage_lab_technician_capacity).
 *
 * Manages technician capacity profiles, service workload profiles, capability
 * mapping and availability overrides. Thin: all writes go through the config
 * service (transactional, overlap-validated, audited). No PII rendered.
 */
class LabCapacityConfigController extends Controller
{
    public function __construct(
        private LabCapacityConfigService $config,
        private TechnicianAssignmentEligibility $eligibility,
    ) {}

    public function index(): View
    {
        return view('lab.capacity-planning.configuration', [
            'capacityProfiles' => TechnicianCapacityProfile::with('technician:id,name')->latest('id')->limit(500)->get(),
            'workloadProfiles' => LabServiceWorkloadProfile::with('labService:id,name')->latest('id')->limit(500)->get(),
            'capabilities' => TechnicianCapability::with(['technician:id,name', 'labService:id,name'])->latest('id')->limit(1000)->get(),
            'overrides' => TechnicianAvailabilityOverride::with('technician:id,name')->latest('override_date')->limit(500)->get(),
            'technicians' => $this->eligibility->listForAssignment()->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values(),
            'services' => LabService::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'planningUnits' => config('lab_technician_capacity.allowed_planning_units'),
            'reasonCategories' => config('lab_technician_capacity.availability_reason_categories'),
            'defaultWorkingDays' => config('lab_technician_capacity.default_working_days'),
        ]);
    }

    public function storeCapacityProfile(StoreCapacityProfileRequest $request): RedirectResponse
    {
        $this->config->createCapacityProfile($request->payload(), $request->user());

        return back()->with('success', 'Profil kapasitas teknisi disimpan.');
    }

    public function updateCapacityProfile(StoreCapacityProfileRequest $request, TechnicianCapacityProfile $capacityProfile): RedirectResponse
    {
        $this->config->updateCapacityProfile($capacityProfile, $request->payload(), $request->user());

        return back()->with('success', 'Profil kapasitas teknisi diperbarui.');
    }

    public function deactivateCapacityProfile(TechnicianCapacityProfile $capacityProfile): RedirectResponse
    {
        $this->config->deactivateCapacityProfile($capacityProfile, request()->user());

        return back()->with('success', 'Profil kapasitas dinonaktifkan.');
    }

    public function storeWorkloadProfile(StoreWorkloadProfileRequest $request): RedirectResponse
    {
        $this->config->createWorkloadProfile($request->payload(), $request->user());

        return back()->with('success', 'Profil workload layanan disimpan.');
    }

    public function updateWorkloadProfile(StoreWorkloadProfileRequest $request, LabServiceWorkloadProfile $workloadProfile): RedirectResponse
    {
        $this->config->updateWorkloadProfile($workloadProfile, $request->payload(), $request->user());

        return back()->with('success', 'Profil workload layanan diperbarui.');
    }

    public function deactivateWorkloadProfile(LabServiceWorkloadProfile $workloadProfile): RedirectResponse
    {
        $this->config->deactivateWorkloadProfile($workloadProfile, request()->user());

        return back()->with('success', 'Profil workload dinonaktifkan.');
    }

    public function storeCapability(StoreCapabilityRequest $request): RedirectResponse
    {
        $this->config->setCapability($request->payload(), $request->user());

        return back()->with('success', 'Kapabilitas teknisi disimpan.');
    }

    public function removeCapability(TechnicianCapability $capability): RedirectResponse
    {
        $this->config->removeCapability($capability);

        return back()->with('success', 'Kapabilitas teknisi dihapus.');
    }

    public function storeAvailabilityOverride(StoreAvailabilityOverrideRequest $request): RedirectResponse
    {
        $this->config->upsertAvailabilityOverride($request->payload(), $request->user());

        return back()->with('success', 'Override ketersediaan disimpan.');
    }

    public function removeAvailabilityOverride(TechnicianAvailabilityOverride $availabilityOverride): RedirectResponse
    {
        $this->config->removeAvailabilityOverride($availabilityOverride);

        return back()->with('success', 'Override ketersediaan dihapus.');
    }
}
