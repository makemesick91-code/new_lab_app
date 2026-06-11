<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Reporting\Services\OwnerDashboardRmeLabKpiService;
use Illuminate\View\View;

class HomeDashboardController extends Controller
{
    public function __construct(
        private readonly OwnerDashboardRmeLabKpiService $ownerRmeLabKpis,
    ) {}

    public function index(): View
    {
        $user = auth()->user();
        $ownerRmeLabPilot = null;

        if ($user instanceof User && $this->shouldLoadOwnerRmeLabPilot($user)) {
            $ownerRmeLabPilot = $this->ownerRmeLabKpis->metrics();
        }

        return view('dashboard', [
            'ownerRmeLabPilot' => $ownerRmeLabPilot,
        ]);
    }

    private function shouldLoadOwnerRmeLabPilot(User $user): bool
    {
        if ($this->hasBranchOperationalDashboard($user)) {
            return false;
        }

        return $user->can('view_owner_dashboard') || $user->can('manage_report');
    }

    private function hasBranchOperationalDashboard(User $user): bool
    {
        return $user->canAny([
            'view_lab_orders',
            'manage_lab_orders',
            'view_production',
            'manage_production',
            'view_quality_control',
            'manage_quality_control',
            'view_delivery',
            'manage_delivery',
            'view_inventory',
            'manage_inventory',
            'view_invoice',
            'manage_invoice',
        ]);
    }
}
