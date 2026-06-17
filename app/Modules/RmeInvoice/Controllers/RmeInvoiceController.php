<?php

namespace App\Modules\RmeInvoice\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeReceivableFollowUp;
use App\Modules\RmeInvoice\Requests\CreateRmeInvoiceRequest;
use App\Modules\RmeInvoice\Services\RmeControlReceivableService;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\Treatment\Services\TreatmentService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RmeInvoiceController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly RmeInvoiceService $service,
        private readonly TreatmentService $treatments,
        private readonly ClinicVisitService $visits,
        private readonly RmeControlReceivableService $carryOver,
    ) {}

    /** @return array<int, string> */
    private function cashierVisitRelations(): array
    {
        return [
            'patient',
            'doctor',
            'branch',
            'initialTreatment',
            'medicalRecord.handwriting',
            'odontogram',
        ];
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', RmeInvoice::class);

        $filters = [
            'search' => $request->string('search')->toString() ?: null,
        ];

        return view('rme.cashier.index', [
            'visits' => $this->service->paginatePendingVisits($filters),
            'filters' => $filters,
        ]);
    }

    /**
     * Aging buckets for active RME receivables (days since invoice date).
     *
     * @var array<int, string>
     */
    public const AGING_BUCKETS = ['0-7', '8-14', '15-30', '>30'];

    // Sprint 24 Phase 24.10: Owner Dashboard follow-up KPI drilldown filters.
    public const FOLLOW_UP_FILTERS = ['overdue', 'today', 'never', 'scheduled', 'escalated'];

    public function receivables(Request $request): View
    {
        $this->authorize('viewAny', RmeInvoice::class);

        $branches = $this->rmeBranches();
        $activeBranchIds = $branches->pluck('id')->all();
        $filters = $this->receivableFilters($request, $activeBranchIds);
        $query = $this->receivableQuery($activeBranchIds, $filters);

        $summaryInvoices = (clone $query)->get();

        $summary = [
            'invoice_count' => $summaryInvoices->count(),
            'grand_total' => $summaryInvoices->sum(fn (RmeInvoice $invoice) => (float) $invoice->grand_total),
            'paid_total' => $summaryInvoices->sum(fn (RmeInvoice $invoice) => (float) $invoice->payments->sum('amount')),
            'remaining_total' => $summaryInvoices->sum(fn (RmeInvoice $invoice) => $this->receivableRemaining($invoice)),
        ];

        $aging = $this->agingSummary($summaryInvoices);

        $invoices = $query
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [RmeInvoice::STATUS_PARTIAL])
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('rme.cashier.receivables', [
            'branches' => $branches,
            'filters' => $filters,
            'invoices' => $invoices,
            'summary' => $summary,
            'aging' => $aging,
            'agingBuckets' => self::AGING_BUCKETS,
        ]);
    }

    public function exportReceivables(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', RmeInvoice::class);

        $branches = $this->rmeBranches();
        $activeBranchIds = $branches->pluck('id')->all();
        $filters = $this->receivableFilters($request, $activeBranchIds);

        $invoices = $this->receivableQuery($activeBranchIds, $filters)
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [RmeInvoice::STATUS_PARTIAL])
            ->latest()
            ->get();

        $filename = 'piutang-rme-'.now()->format('Ymd-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        $columns = [
            'No Invoice', 'Pasien', 'No Kunjungan', 'Cabang', 'Status',
            'Tanggal Invoice', 'Umur Hari', 'Bucket Aging', 'Grand Total', 'Sudah Dibayar', 'Sisa Piutang',
        ];

        return response()->stream(function () use ($invoices, $columns): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            foreach ($invoices as $invoice) {
                $paidAmount = (float) $invoice->payments->sum('amount');
                $days = $this->invoiceAgeDays($invoice);

                fputcsv($handle, [
                    $invoice->invoice_number,
                    $invoice->patient?->name ?? '-',
                    $invoice->clinicVisit?->visit_number ?? '-',
                    trim(($invoice->branch?->code ?? '').' '.($invoice->branch?->name ?? '')),
                    $invoice->status,
                    $invoice->created_at?->format('Y-m-d H:i') ?? '-',
                    $days,
                    $this->agingBucketForDays($days),
                    number_format((float) $invoice->grand_total, 2, '.', ''),
                    number_format($paidAmount, 2, '.', ''),
                    number_format($this->receivableRemaining($invoice), 2, '.', ''),
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }

    private function rmeBranches()
    {
        return Branch::query()
            ->where('is_active', true)
            ->where('is_rme_enabled', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    /**
     * @param  array<int, int>  $activeBranchIds
     * @return array<string, mixed>
     */
    private function receivableFilters(Request $request, array $activeBranchIds): array
    {
        $requestedBranchId = $request->integer('branch_id') ?: null;
        $selectedBranchId = $requestedBranchId && in_array($requestedBranchId, $activeBranchIds, true)
            ? $requestedBranchId
            : null;

        $requestedStatus = $request->string('status')->toString();
        $selectedStatus = in_array($requestedStatus, [RmeInvoice::STATUS_UNPAID, RmeInvoice::STATUS_PARTIAL], true)
            ? $requestedStatus
            : null;

        $requestedBucket = $request->string('aging_bucket')->toString();
        $selectedBucket = in_array($requestedBucket, self::AGING_BUCKETS, true) ? $requestedBucket : null;

        $requestedFollowUp = $request->string('follow_up_filter')->toString();
        $selectedFollowUp = in_array($requestedFollowUp, self::FOLLOW_UP_FILTERS, true) ? $requestedFollowUp : null;

        $dateFrom = (string) $request->input('date_from', '');
        $dateTo = (string) $request->input('date_to', '');

        return [
            'search' => $request->string('search')->toString() ?: null,
            'branch_id' => $selectedBranchId,
            'status' => $selectedStatus,
            'aging_bucket' => $selectedBucket,
            'follow_up_filter' => $selectedFollowUp,
            'date_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom : null,
            'date_to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? $dateTo : null,
        ];
    }

    /**
     * Sprint 27 Phase 27.4.2 — only invoices with an actual outstanding balance
     * count as active receivables. Excludes zero-grand-total invoices (e.g. free
     * control visits) and any invoice already settled by payments, regardless of
     * a stale UNPAID status. Applied at query level so listing, count, pagination,
     * aging summary, and export all share the same rule.
     */
    private function applyActiveReceivableConstraint(Builder $query): Builder
    {
        return $query
            ->where('grand_total', '>', 0)
            ->whereRaw('trx_rme_invoices.grand_total > (SELECT COALESCE(SUM(amount), 0) FROM trx_rme_payments WHERE trx_rme_payments.rme_invoice_id = trx_rme_invoices.id)');
    }

    /**
     * @param  array<int, int>  $activeBranchIds
     * @param  array<string, mixed>  $filters
     */
    private function receivableQuery(array $activeBranchIds, array $filters): Builder
    {
        return $this->applyActiveReceivableConstraint(
            RmeInvoice::query()
                ->with(['branch', 'patient', 'clinicVisit', 'payments', 'latestFollowUp.user'])
                ->whereIn('status', [RmeInvoice::STATUS_UNPAID, RmeInvoice::STATUS_PARTIAL])
        )
            ->whereIn('branch_id', $activeBranchIds)
            ->when($filters['branch_id'], fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
            ->when($filters['date_from'], fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'], fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->when($filters['aging_bucket'], fn ($query, $bucket) => $this->applyAgingBucket($query, $bucket))
            ->when($filters['follow_up_filter'], fn ($query, $filter) => $this->applyFollowUpFilter($query, $filter))
            ->when($filters['search'], function ($query, string $search): void {
                $term = '%'.mb_strtolower($search).'%';

                $query->where(function ($query) use ($term): void {
                    $query->whereRaw('LOWER(invoice_number) LIKE ?', [$term])
                        ->orWhereHas('patient', fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', [$term]))
                        ->orWhereHas('clinicVisit', fn ($query) => $query->whereRaw('LOWER(visit_number) LIKE ?', [$term]));
                });
            });
    }

    /** Days from the invoice date (created_at) to today. */
    private function invoiceAgeDays(RmeInvoice $invoice): int
    {
        if (! $invoice->created_at) {
            return 0;
        }

        return (int) $invoice->created_at->copy()->startOfDay()->diffInDays(Carbon::today());
    }

    private function agingBucketForDays(int $days): string
    {
        return match (true) {
            $days <= 7 => '0-7',
            $days <= 14 => '8-14',
            $days <= 30 => '15-30',
            default => '>30',
        };
    }

    private function applyAgingBucket(Builder $query, string $bucket): Builder
    {
        $today = Carbon::today();

        return match ($bucket) {
            '0-7' => $query->whereDate('created_at', '>=', $today->copy()->subDays(7)),
            '8-14' => $query->whereDate('created_at', '<=', $today->copy()->subDays(8))
                ->whereDate('created_at', '>=', $today->copy()->subDays(14)),
            '15-30' => $query->whereDate('created_at', '<=', $today->copy()->subDays(15))
                ->whereDate('created_at', '>=', $today->copy()->subDays(30)),
            '>30' => $query->whereDate('created_at', '<', $today->copy()->subDays(30)),
            default => $query,
        };
    }

    /**
     * Sprint 24 Phase 24.10: filter active receivables by latest follow-up state.
     * Counts/filters invoices, using the latest follow-up (latestOfMany) only.
     */
    private function applyFollowUpFilter(Builder $query, string $filter): Builder
    {
        $today = Carbon::today()->toDateString();

        return match ($filter) {
            'overdue' => $query->whereHas('latestFollowUp', fn (Builder $q) => $q->whereDate('next_follow_up_date', '<', $today)),
            'today' => $query->whereHas('latestFollowUp', fn (Builder $q) => $q->whereDate('next_follow_up_date', '=', $today)),
            'never' => $query->whereDoesntHave('followUps'),
            'scheduled' => $query->whereHas('latestFollowUp', fn (Builder $q) => $q->whereDate('next_follow_up_date', '>', $today)),
            'escalated' => $query->whereHas('latestFollowUp', fn (Builder $q) => $q->where('status', RmeReceivableFollowUp::STATUS_ESCALATED)),
            default => $query,
        };
    }

    private function receivableRemaining(RmeInvoice $invoice): float
    {
        $paidAmount = (float) $invoice->payments->sum('amount');

        return max(0, round((float) $invoice->grand_total - $paidAmount, 2));
    }

    /**
     * @param  Collection<int, RmeInvoice>  $invoices
     * @return array<string, array{count: int, remaining: float}>
     */
    private function agingSummary($invoices): array
    {
        $summary = [];
        foreach (self::AGING_BUCKETS as $bucket) {
            $summary[$bucket] = ['count' => 0, 'remaining' => 0.0];
        }

        foreach ($invoices as $invoice) {
            $bucket = $this->agingBucketForDays($this->invoiceAgeDays($invoice));
            $summary[$bucket]['count']++;
            $summary[$bucket]['remaining'] += $this->receivableRemaining($invoice);
        }

        return $summary;
    }

    public function create(Request $request, ClinicVisit $clinicVisit): View
    {
        $this->authorize('create', RmeInvoice::class);

        $existingInvoice = $this->service->findInvoiceForVisit($clinicVisit);
        if ($existingInvoice) {
            return redirect()
                ->route('rme.cashier.show', [$clinicVisit, $existingInvoice])
                ->with('status', 'Tagihan sudah ada untuk kunjungan ini.');
        }

        return view('rme.cashier.create', [
            'visit' => $clinicVisit->load($this->cashierVisitRelations()),
            'treatments' => $this->treatments->listActive(),
        ]);
    }

    public function store(CreateRmeInvoiceRequest $request, ClinicVisit $clinicVisit): RedirectResponse
    {
        $this->authorize('create', RmeInvoice::class);

        $invoice = $this->service->create($clinicVisit, $request->user(), $request->validated());

        return redirect()
            ->route('rme.cashier.show', [$clinicVisit, $invoice])
            ->with('status', 'Tagihan berhasil dibuat.');
    }

    public function show(ClinicVisit $clinicVisit, RmeInvoice $rmeInvoice): View
    {
        $this->authorize('view', $rmeInvoice);

        $invoice = $rmeInvoice->load([
            'items.treatment',
            'cashier',
            'medicalRecord',
            'payments.paymentMethod',
            'payments.cashier',
            'labCaseCandidates.convertedLabOrder',
            'labCaseCandidates.treatment',
        ]);

        return view('rme.cashier.show', [
            'visit' => $clinicVisit->load(array_merge($this->cashierVisitRelations(), ['followUpOf'])),
            'invoice' => $invoice,
            'labCaseCandidates' => $invoice->labCaseCandidates,
            'payableSummary' => $this->carryOver->getControlPayableSummary($rmeInvoice),
        ]);
    }
}
