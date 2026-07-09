<?php

namespace App\Modules\RmeInvoice\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\RmeInvoice\Requests\DoctorPerformanceReportRequest;
use App\Modules\RmeInvoice\Services\DoctorPerformanceReportService;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * FIX-PRE-68-45 Scope C — Doctor Performance / Income report page.
 *
 * Thin controller: the route permission middleware gates entry; the service
 * resolves the caller's visibility tier and forces a doctor's own scope. A user
 * whose only claim is a doctor tier but who is NOT a linked doctor gets 403.
 *
 * HOTFIX-FIX-PRE-68-45-DOCTOR-PERFORMANCE-403: an unlinked doctor account now
 * gets a clear, diagnosable 403 message instead of a bare 403, and any other
 * caller without a doctor-report permission still gets a plain 403.
 */
class DoctorPerformanceReportController extends Controller
{
    public function __construct(
        private readonly DoctorPerformanceReportService $service,
    ) {}

    public function index(DoctorPerformanceReportRequest $request): View
    {
        $access = $this->service->resolveAccess($request->user());

        abort_if(
            $access['mode'] === 'unlinked',
            Response::HTTP_FORBIDDEN,
            'Akun dokter belum terhubung ke data dokter. Hubungi admin untuk menghubungkan user ke master dokter.',
        );

        abort_if($access['mode'] === 'denied', Response::HTTP_FORBIDDEN);

        $report = $this->service->report($access, $request->filters());

        return view('rme.reports.doctor-performance', [
            'access' => $access,
            'report' => $report,
            'filters' => $request->filters(),
            'doctorOptions' => $access['can_pick_doctor'] ? $this->service->doctorOptions() : collect(),
            'branchOptions' => $access['can_pick_branch'] ? $this->service->branchOptions() : collect(),
            'treatmentOptions' => $this->service->treatmentOptionsForFilter(),
            'invoiceStatusOptions' => $this->service->invoiceStatusOptions(),
        ]);
    }
}
