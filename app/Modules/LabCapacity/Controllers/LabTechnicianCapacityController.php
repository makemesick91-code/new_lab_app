<?php

namespace App\Modules\LabCapacity\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LabCapacity\Requests\LabCapacityPlanFilterRequest;
use App\Modules\LabCapacity\Services\LabTechnicianCapacityPlanningService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * LAB-PROD-3 — Technician Capacity Planning (read-only decision-support).
 *
 * Thin controller: resolves the server-side scope (IDOR-safe), delegates all
 * computation to the planning service, and renders. Export reuses the SAME
 * scope + filters and is PII-free.
 */
class LabTechnicianCapacityController extends Controller
{
    public function __construct(
        private LabTechnicianCapacityPlanningService $service,
    ) {}

    public function index(LabCapacityPlanFilterRequest $request): View|RedirectResponse
    {
        if (! $this->service->featureEnabled()) {
            return view('lab.capacity-planning.disabled');
        }

        $scope = $this->service->resolveScope(
            $request->user(),
            $request->integer('branch_id') ?: null,
            $request->integer('technician_id') ?: null,
        );
        abort_if($scope['tier'] === 'denied', 403);

        $plan = $this->service->plan($scope, $request->filters());
        $options = $this->service->filterOptions($scope);
        $configured = $this->service->isConfigured();

        return view('lab.capacity-planning.index', compact('plan', 'scope', 'options', 'configured'));
    }

    public function export(LabCapacityPlanFilterRequest $request): StreamedResponse
    {
        abort_unless($this->service->featureEnabled(), 404);

        $scope = $this->service->resolveScope(
            $request->user(),
            $request->integer('branch_id') ?: null,
            $request->integer('technician_id') ?: null,
        );
        abort_if($scope['tier'] === 'denied', 403);
        abort_unless($scope['can_export'], 403);

        $plan = $this->service->plan($scope, $request->filters());
        $cap = (int) config('lab_technician_capacity.export_row_cap', 5000);
        $filename = 'capacity-planning-'.$plan['period']['from'].'-'.$plan['period']['to'].'.csv';

        return response()->streamDownload(function () use ($plan, $cap) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Periode', $plan['period']['from'].' s/d '.$plan['period']['to']]);
            fputcsv($out, ['Unit Perencanaan', $plan['planning_unit']]);
            fputcsv($out, []);
            fputcsv($out, [
                'Teknisi', 'Kapasitas Tersedia', 'Beban Ditugaskan', 'Selisih Kapasitas',
                'Utilisasi (%)', 'Order Aktif', 'Order Berisiko', 'Status Perencanaan', 'Cakupan Data',
            ]);
            $rows = 0;
            foreach ($plan['technicians'] as $t) {
                if ($rows++ >= $cap) {
                    break;
                }
                fputcsv($out, [
                    $this->csvSafe($t['name']),
                    $t['available'] ?? 'N/A',
                    $t['assigned_load'],
                    $t['capacity_gap'] ?? 'N/A',
                    $t['utilization'] ?? 'N/A',
                    $t['active_orders'],
                    $t['due_risk_count'],
                    $t['band'],
                    $t['coverage'],
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /** Neutralise CSV/formula injection in free-text cells. */
    private function csvSafe(?string $value): string
    {
        $value = (string) $value;
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
