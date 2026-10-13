<?php

namespace App\Modules\ClinicOversight\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicOversight\Requests\OversightDashboardFilterRequest;
use App\Modules\ClinicOversight\Services\OversightAuditService;
use App\Modules\ClinicOversight\Services\OversightCsvExporter;
use App\Modules\ClinicOversight\Services\OversightDashboardService;
use App\Modules\ClinicOversight\Support\ClinicalDateRange;
use App\Modules\ClinicOversight\Support\ClinicOversightScope;
use App\Support\Clinical\ClinicalClock;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 — Dashboard Finance & Data
 * Analyst. GET only; thin: the service owns every definition.
 *
 * REVISION-OVERSIGHT-DASHBOARD-DATE-RANGE-1 (rule 187): every surface — page,
 * polling feed, CSV and print — resolves ONE ClinicalDateRange from the same
 * request rule, so the four never disagree about the period.
 */
class OversightDashboardController extends Controller
{
    use AuthorizesRequests;

    /**
     * The query keys that carry the dashboard filter forward (refresh feed,
     * export and print links). Anything else on the page URL is dropped.
     */
    private const FILTER_KEYS = ['start_date', 'end_date', 'date', 'branch_id'];

    public function __construct(
        private readonly OversightDashboardService $dashboard,
        private readonly OversightAuditService $audit,
        private readonly OversightCsvExporter $csv,
        private readonly ClinicalClock $clock,
    ) {}

    public function index(OversightDashboardFilterRequest $request): View
    {
        $this->authorize('clinic-oversight.view-dashboard');

        $filters = $request->filters();
        $payload = $this->dashboard->build($request->user(), $request->dateRange(), $filters['branch_id'] ?? null);

        // The refresh feed / export / print carry exactly the page's own
        // filter: an explicit range stays pinned across refreshes, while the
        // default (no dates) keeps following the clinical today.
        $carried = Arr::only($filters, self::FILTER_KEYS);

        return view('oversight.dashboard.index', [
            'payload' => $payload,
            'branchOptions' => $this->dashboard->branchOptions($request->user()),
            'filters' => $filters,
            'carriedFilters' => $carried,
            'presets' => ClinicalDateRange::presets($this->clock),
            'resetUrl' => route('oversight.dashboard', array_filter(['branch_id' => $payload['selected_branch_id']])),
            'canExport' => $request->user()->can('clinic-oversight.export', ClinicOversightScope::PERMISSION_DASHBOARD),
        ]);
    }

    /**
     * The polling feed. Authorized on the server on every call — the page
     * having rendered once is never a credential for the next refresh.
     */
    public function metrics(OversightDashboardFilterRequest $request): JsonResponse
    {
        $this->authorize('clinic-oversight.view-dashboard');

        $filters = $request->filters();

        return response()
            ->json($this->dashboard->build($request->user(), $request->dateRange(), $filters['branch_id'] ?? null))
            ->header('Cache-Control', 'private, no-store');
    }

    public function export(OversightDashboardFilterRequest $request): StreamedResponse
    {
        $this->authorize('clinic-oversight.export', ClinicOversightScope::PERMISSION_DASHBOARD);

        $filters = $request->filters();
        $payload = $this->dashboard->build($request->user(), $request->dateRange(), $filters['branch_id'] ?? null);

        $this->audit->log(OversightAuditService::DATA_EXPORTED, null, [
            'surface' => 'dashboard',
            'format' => 'csv',
            'date_from' => $payload['start_date'],
            'date_to' => $payload['end_date'],
            'branch_filter' => $payload['selected_branch_id'],
            'row_count' => count($payload['branches']),
        ], $request->user());

        $start = $payload['start_date'];
        $end = $payload['end_date'];

        $rows = [];
        foreach ($payload['branches'] as $b) {
            $rows[] = [
                $start,
                $end,
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
        $rows[] = [$start, $end, 'TOTAL', 'Semua cabang terpilih', $t['patients'], $t['transactions'], self::money($t['revenue_cents']), self::money($t['receivable_cents']), $t['receivable_invoices'], $t['cancellations']];

        // A one-day range keeps the original single-day file name.
        $period = $start === $end ? $start : $start.'_sd_'.$end;

        return $this->csv->download(
            'dashboard-finance-data-analyst-'.$period.'.csv',
            ['Tanggal Mulai (WITA)', 'Tanggal Akhir (WITA)', 'Kode Cabang', 'Cabang', 'Jumlah Pasien (check-in, unik)', 'Jumlah Transaksi (batch kasir)', 'Omzet (pembayaran RME)', 'Piutang Aktif Per Saat Ini (saldo saat ini, tidak mengikuti periode)', 'Invoice Piutang', 'Kunjungan Dibatalkan'],
            $rows,
        );
    }

    public function print(OversightDashboardFilterRequest $request): View
    {
        $this->authorize('clinic-oversight.export', ClinicOversightScope::PERMISSION_DASHBOARD);

        $filters = $request->filters();
        $payload = $this->dashboard->build($request->user(), $request->dateRange(), $filters['branch_id'] ?? null);

        $this->audit->log(OversightAuditService::DATA_PRINTED, null, [
            'surface' => 'dashboard',
            'format' => 'print',
            'date_from' => $payload['start_date'],
            'date_to' => $payload['end_date'],
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
