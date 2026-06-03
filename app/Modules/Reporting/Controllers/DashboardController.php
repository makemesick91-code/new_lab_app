<?php

namespace App\Modules\Reporting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Clinic\Services\ClinicService;
use App\Modules\Reporting\Requests\DashboardRequest;
use App\Modules\Reporting\Services\DashboardService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;

class DashboardController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly ClinicService $clinicService,
    ) {}

    public function index(DashboardRequest $request): View
    {
        $this->authorize('reporting.dashboard');

        $filters = $request->filters();

        return view('reports.dashboard', [
            'cards' => $this->dashboardService->cards($filters),
            'charts' => $this->dashboardService->charts($filters),
            'filters' => $filters,
            'clinics' => $this->clinicService->listAll(),
        ]);
    }
}
