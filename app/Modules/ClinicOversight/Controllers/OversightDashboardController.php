<?php

namespace App\Modules\ClinicOversight\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicOversight\Requests\OversightFilterRequest;
use App\Modules\ClinicOversight\Services\OversightAuditService;
use App\Modules\ClinicOversight\Services\OversightCsvExporter;
use App\Modules\ClinicOversight\Services\OversightDashboardService;
use App\Modules\ClinicOversight\Support\ClinicOversightScope;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 — Dashboard Finance & Data
 * Analyst. GET only; thin: the service owns every definition.
 */
class OversightDashboardController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly OversightDashboardService $dashboard,
        private readonly OversightAuditService $audit,
        private readonly OversightCsvExporter $csv,
    ) {}

    public function index(OversightFilterRequest $request): View
    {
        $this->authorize('clinic-oversight.view-dashboard');

        $filters = $request->filters();

        return view('oversight.dashboard.index', [
            'payload' => $this->dashboard->build($request->user(), $filters['date'] ?? null, $filters['branch_id'] ?? null),
            'branchOptions' => $this->dashboard->branchOptions($request->user()),
            'filters' => $filters,
            'canExport' => $request->user()->can('clinic-oversight.export', ClinicOversightScope::PERMISSION_DASHBOARD),
        ]);
    }

    /**
     * The polling feed. Authorized on the server on every call — the page
     * having rendered once is never a credential for the next refresh.
     */
    public function metrics(OversightFilterRequest $request): JsonResponse
    {
        $this->authorize('clinic-oversight.view-dashboard');

        $filters = $request->filters();

        return response()
            ->json($this->dashboard->build($request->user(), $filters['date'] ?? null, $filters['branch_id'] ?? null))
            ->header('Cache-Control', 'private, no-store');
    }

    public function export(OversightFilterRequest $request): StreamedResponse
    {
        $this->authorize('clinic-oversight.export', ClinicOversightScope::PERMISSION_DASHBOARD);

        $filters = $request->filters();
        $payload = $this->dashboard->build($request->user(), $filters['date'] ?? null, $filters['branch_id'] ?? null);

        $this->audit->log(OversightAuditService::DATA_EXPORTED, null, [
            'surface' => 'dashboard',
            'format' => 'csv',
            'date' => $payload['date'],
            'branch_filter' => $payload['selected_branch_id'],
            'row_count' => count($payload['branches']),
        ], $request->user());

        $rows = [];
        foreach ($payload['branches'] as $b) {
            $rows[] = [
                $b['branch_code'],
                $b['branch_name'],
                $b['patients'],
                $b['transactions'],
                self::money($b['revenue_cents']),
                self::money($b['receivable_cents']),
                $b['receivable_invoices'],
                $b['cancellations'],
            ];
        }

        $t = $payload['totals'];
        $rows[] = ['TOTAL', 'Semua cabang terpilih', $t['patients'], $t['transactions'], self::money($t['revenue_cents']), self::money($t['receivable_cents']), $t['receivable_invoices'], $t['cancellations']];

        return $this->csv->download(
            'dashboard-finance-data-analyst-'.$payload['date'].'.csv',
            ['Kode Cabang', 'Cabang', 'Jumlah Pasien (check-in, unik)', 'Jumlah Transaksi (batch kasir)', 'Omzet (pembayaran RME)', 'Piutang Aktif Per Saat Ini', 'Invoice Piutang', 'Kunjungan Dibatalkan'],
            $rows,
        );
    }

    public function print(OversightFilterRequest $request): View
    {
        $this->authorize('clinic-oversight.export', ClinicOversightScope::PERMISSION_DASHBOARD);

        $filters = $request->filters();
        $payload = $this->dashboard->build($request->user(), $filters['date'] ?? null, $filters['branch_id'] ?? null);

        $this->audit->log(OversightAuditService::DATA_PRINTED, null, [
            'surface' => 'dashboard',
            'format' => 'print',
            'date' => $payload['date'],
            'branch_filter' => $payload['selected_branch_id'],
            'row_count' => count($payload['branches']),
        ], $request->user());

        return view('oversight.dashboard.print', ['payload' => $payload]);
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
