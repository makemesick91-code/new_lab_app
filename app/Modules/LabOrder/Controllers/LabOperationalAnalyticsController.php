<?php

namespace App\Modules\LabOrder\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LabOrder\Requests\LabOperationalAnalyticsFilterRequest;
use App\Modules\LabOrder\Services\LabOperationalAnalyticsService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * LAB-PROD-2 — Operational Analytics & KPI controller (thin).
 *
 * All aggregation/formulas live in LabOperationalAnalyticsService and the
 * repository. The controller resolves the caller's scope tier server-side and
 * aborts 403 when the caller has no analytics access. The CSV export uses the
 * IDENTICAL scope + filters as the screen and never emits PII.
 */
class LabOperationalAnalyticsController extends Controller
{
    public function __construct(
        private readonly LabOperationalAnalyticsService $analytics,
    ) {}

    public function index(LabOperationalAnalyticsFilterRequest $request)
    {
        $scope = $this->resolveScopeOrAbort($request);
        $data = $this->analytics->analytics($scope, $request->filters());

        return view('lab.analytics.index', [
            'data' => $data,
            'filters' => $request->filters(),
            'registry' => config('lab_operational_analytics.metrics'),
        ]);
    }

    public function export(LabOperationalAnalyticsFilterRequest $request): StreamedResponse
    {
        $scope = $this->resolveScopeOrAbort($request);
        $data = $this->analytics->analytics($scope, $request->filters());

        $filename = 'lab-operational-kpi-'.$data['period']['from'].'_'.$data['period']['to'].'.csv';

        return response()->streamDownload(function () use ($data): void {
            $out = fopen('php://output', 'w');
            $this->writeCsv($out, $data);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array{tier: string, sees_all: bool, branch_id: int|null, technician_id: int|null, technician_name: string|null}
     */
    private function resolveScopeOrAbort(LabOperationalAnalyticsFilterRequest $request): array
    {
        $scope = $this->analytics->resolveScope(
            $request->user(),
            $request->integer('branch_id') ?: null,
            $request->integer('technician_id') ?: null,
        );

        abort_if($scope['tier'] === 'denied', 403, 'Anda tidak memiliki akses ke analitik operasional lab.');

        return $scope;
    }

    /**
     * PII-free CSV: KPI summary + technician operational breakdown only.
     *
     * @param  resource  $out
     * @param  array<string, mixed>  $data
     */
    private function writeCsv($out, array $data): void
    {
        $k = $data['kpi'];
        $p = $data['period'];

        fputcsv($out, ['Laporan', 'KPI Operasional Lab (LAB-PROD-2)']);
        fputcsv($out, ['Periode', $p['from'].' s/d '.$p['to'].' ('.$p['label'].')']);
        fputcsv($out, ['Scope', $data['scope']['tier'].($data['scope']['branch_id'] ? ' / cabang '.$data['scope']['branch_id'] : '')]);
        fputcsv($out, ['Catatan', $data['note']]);
        fputcsv($out, []);

        fputcsv($out, ['Metrik', 'Nilai']);
        fputcsv($out, ['Order Masuk', $k['orders_received']]);
        fputcsv($out, ['WIP Terbuka', $k['open_wip']]);
        fputcsv($out, ['Rework Aktif', $k['rework_active']]);
        fputcsv($out, ['Overdue Terbuka', $k['open_overdue']]);
        fputcsv($out, ['Throughput Selesai', $k['throughput']]);
        fputcsv($out, ['Throughput Periode Sebelumnya', $k['throughput_prev']]);
        fputcsv($out, ['Selisih Throughput', $k['throughput_delta']]);
        fputcsv($out, ['SLA Eligible', $k['sla']['eligible']]);
        fputcsv($out, ['SLA Tepat Waktu', $k['sla']['on_time']]);
        fputcsv($out, ['SLA Terlambat', $k['sla']['late']]);
        fputcsv($out, ['SLA Kepatuhan %', $k['sla']['compliance_pct'] ?? 'N/A (tidak ada eligible)']);
        fputcsv($out, ['SLA Median Keterlambatan (hari)', $k['sla']['median_lateness_days'] ?? 'N/A']);
        fputcsv($out, ['QC Attempt', $k['qc']['attempts']]);
        fputcsv($out, ['QC First-Pass Yield %', $k['qc']['first_pass_yield_pct'] ?? 'N/A (tidak ada attempt)']);
        fputcsv($out, ['QC Rework Rate %', $k['qc']['rework_rate_pct'] ?? 'N/A']);
        fputcsv($out, ['Internal', $k['internal_vs_external']['internal']]);
        fputcsv($out, ['Eksternal', $k['internal_vs_external']['external']]);
        fputcsv($out, ['External Turnaround Median (hari)', $k['external_turnaround']['median_days'] ?? 'N/A']);
        fputcsv($out, []);

        fputcsv($out, ['Cakupan Data', 'Nilai']);
        fputcsv($out, ['Total Order (periode)', $data['data_quality']['total']]);
        fputcsv($out, ['Dengan Due Date', $data['data_quality']['with_due_date']]);
        fputcsv($out, ['Tanpa Due Date (dikecualikan SLA)', $data['data_quality']['without_due_date']]);
        fputcsv($out, ['Stuck (idle)', $data['data_quality']['stuck']]);
        fputcsv($out, []);

        fputcsv($out, ['Teknisi', 'WIP Aktif', 'Ditugaskan', 'Selesai', 'Median Menit', 'Sampel']);
        foreach ($data['technicians'] as $t) {
            fputcsv($out, [$t['name'], $t['active_wip'], $t['assigned'], $t['completed'], $t['median_minutes'] ?? 'N/A', $t['sample']]);
        }
    }
}
