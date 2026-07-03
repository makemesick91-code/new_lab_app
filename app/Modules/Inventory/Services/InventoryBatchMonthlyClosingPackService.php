<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Inventory\Enums\InventoryBatchActionType;
use App\Modules\Inventory\Enums\InventoryBatchDisposalRequestStatus;
use App\Modules\Inventory\Enums\InventoryBatchDisposalRequestType;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryBatchActionLog;
use App\Modules\Inventory\Models\InventoryBatchDisposalRequest;
use App\Modules\Inventory\Models\InventoryMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryBatchMonthlyClosingPackService
{
    private const EXPORT_ROW_CAP = 5000;

    /**
     * @var list<array{key: string, label: string}>
     */
    public const CLOSING_CHECKLIST = [
        ['key' => 'expired_reviewed', 'label' => 'Daftar batch kedaluwarsa bersaldo telah direview'],
        ['key' => 'near_expiry_reviewed', 'label' => 'Daftar batch akan kedaluwarsa bersaldo telah direview'],
        ['key' => 'action_logs_reviewed', 'label' => 'Action log batch bulan ini telah direview'],
        ['key' => 'disposal_requests_reviewed', 'label' => 'Permintaan disposal/return supplier telah direview'],
        ['key' => 'pending_approvals_reviewed', 'label' => 'Permintaan menunggu approval telah direview'],
        ['key' => 'adjustment_evidence_checked', 'label' => 'Bukti ADJUSTMENT_OUT telah dicocokkan dengan request final'],
        ['key' => 'csv_archived', 'label' => 'Export CSV telah diarsipkan'],
        ['key' => 'print_signed', 'label' => 'Print pack telah ditandatangani manual'],
        ['key' => 'no_direct_stock_mutation', 'label' => 'Tidak ada mutasi stok di luar ledger resmi'],
    ];

    public function __construct(
        private readonly InventoryReportService $reports,
        private readonly InventoryBatchDisposalReportService $disposalReport,
        private readonly BatchExpiryStatusService $expiryStatus,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function prepareFilters(array $filters, ?User $user = null): array
    {
        $user ??= Auth::user();
        $year = (int) ($filters['year'] ?? now()->year);
        $month = (int) ($filters['month'] ?? now()->month);
        $period = Carbon::create($year, $month, 1);

        $filters['year'] = $year;
        $filters['month'] = $month;
        $filters['date_from'] = $period->copy()->startOfMonth()->toDateString();
        $filters['date_to'] = $period->copy()->endOfMonth()->toDateString();

        if (! empty($filters['include_all_branches'])) {
            unset($filters['branch_id']);
        }

        $scope = $this->disposalReport->resolveBranchScope($filters, $user);
        if ($scope['selected_branch_id'] !== null) {
            $filters['branch_id'] = $scope['selected_branch_id'];
        } else {
            unset($filters['branch_id']);
        }

        return $filters;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{branch_ids: list<int>, cross_branch: bool, selected_branch_id: ?int}
     */
    public function resolveBranchScope(array $filters, ?User $user = null): array
    {
        return $this->disposalReport->resolveBranchScope($filters, $user);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getClosingPack(array $filters, ?User $user = null, int $perPage = 15): array
    {
        $scope = $this->resolveBranchScope($filters, $user);
        $branchIds = $scope['branch_ids'];

        $expiryRisk = $this->buildExpiryRiskRows($branchIds, $filters);
        $actionLogs = $this->actionLogQuery($filters, $branchIds)->get();
        $disposalRequests = $this->disposalRequestQuery($filters, $branchIds)->get();
        $ledgerEvidence = $this->buildLedgerEvidenceRows($disposalRequests);
        $exceptions = $this->buildExceptions($expiryRisk, $actionLogs, $disposalRequests);

        return [
            'summary' => $this->buildSummary($expiryRisk, $actionLogs, $disposalRequests, $exceptions, $scope),
            'breakdowns' => $this->buildBreakdowns($actionLogs, $disposalRequests, $scope),
            'expiry_risk_rows' => $expiryRisk,
            'action_log_rows' => $actionLogs,
            'disposal_rows' => $disposalRequests,
            'ledger_evidence_rows' => $ledgerEvidence,
            'exception_rows' => $exceptions,
            'checklist' => self::CLOSING_CHECKLIST,
            'scope' => $scope,
            'period_label' => $this->periodLabel($filters),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getFilterOptions(array $filters, ?User $user = null): array
    {
        $scope = $this->resolveBranchScope($filters, $user);
        $branchId = $scope['selected_branch_id'] ?? $scope['branch_ids'][0];

        return [
            'branches' => $this->reports->reportBranchOptions($user),
            'locations' => $this->reports->getReportFilterOptions(['branch_id' => $branchId])['locations'],
            'statuses' => InventoryBatchDisposalRequestStatus::values(),
            'requestTypes' => InventoryBatchDisposalRequestType::values(),
            'actionTypes' => InventoryBatchActionType::values(),
            'months' => $this->monthOptions(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function exportCsv(array $filters, ?User $user = null): StreamedResponse
    {
        $pack = $this->getClosingPack($filters, $user, self::EXPORT_ROW_CAP);
        $period = $pack['period_label'];
        $headers = [
            'section',
            'period',
            'branch',
            'product',
            'batch_number',
            'expiry_date',
            'location',
            'ledger_quantity',
            'action_type',
            'action_note',
            'request_type',
            'request_status',
            'quantity_requested',
            'movement_type',
            'movement_quantity_out',
            'movement_reference',
            'evidence_reference',
            'actor_or_submitter',
            'approved_by',
            'finalized_by',
            'follow_up_flag',
            'created_or_event_date',
        ];

        $filename = 'closing-bulanan-governance-batch-'.($filters['year'] ?? now()->year).'-'.str_pad((string) ($filters['month'] ?? now()->month), 2, '0', STR_PAD_LEFT).'.csv';

        return response()->streamDownload(function () use ($pack, $period, $headers) {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers);

            foreach ($pack['expiry_risk_rows'] as $row) {
                fputcsv($handle, $this->mapExpiryExportRow($row, $period));
            }

            foreach ($pack['action_log_rows'] as $row) {
                fputcsv($handle, $this->mapActionLogExportRow($row, $period));
            }

            foreach ($pack['disposal_rows'] as $row) {
                fputcsv($handle, $this->mapDisposalExportRow($row, $period));
            }

            foreach ($pack['ledger_evidence_rows'] as $row) {
                fputcsv($handle, $this->mapLedgerExportRow($row, $period));
            }

            foreach ($pack['exception_rows'] as $row) {
                fputcsv($handle, $this->mapExceptionExportRow($row, $period));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  list<int>  $branchIds
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function buildExpiryRiskRows(array $branchIds, array $filters): Collection
    {
        $batchStock = $this->batchLedgerStockSubquery($branchIds);
        $locationStock = $this->batchLocationStockSubquery($branchIds);

        $query = InventoryBatch::query()
            ->with(['branch', 'product'])
            ->joinSub($batchStock, 'batch_stock', function ($join) {
                $join->on('batch_stock.inventory_batch_id', '=', 'inv_inventory_batches.id');
            })
            ->whereIn('inv_inventory_batches.branch_id', $branchIds)
            ->select([
                'inv_inventory_batches.*',
                'batch_stock.ledger_qty',
            ]);

        if (! empty($filters['product'])) {
            $search = '%'.$filters['product'].'%';
            $query->whereHas('product', fn (Builder $q) => $q
                ->where('name', 'ilike', $search)
                ->orWhere('code', 'ilike', $search));
        }

        if (! empty($filters['batch'])) {
            $query->where('inv_inventory_batches.batch_number', 'ilike', '%'.$filters['batch'].'%');
        }

        $batches = $query->get();

        $batchIds = $batches->pluck('id')->all();
        $latestActions = $this->latestActionLogsByBatch($batchIds);
        $topLocations = $this->topLocationByBatch($locationStock, $batchIds);

        return $batches->map(function (InventoryBatch $batch) use ($latestActions, $topLocations) {
            $expiryStatus = $this->expiryStatus->status($batch->expiry_date);
            if (! in_array($expiryStatus, [BatchExpiryStatusService::STATUS_EXPIRED, BatchExpiryStatusService::STATUS_NEAR_EXPIRY], true)) {
                return null;
            }

            $location = $topLocations[$batch->id] ?? null;
            $latestAction = $latestActions[$batch->id] ?? null;

            return [
                'branch' => $batch->branch,
                'product' => $batch->product,
                'batch' => $batch,
                'expiry_date' => $batch->expiry_date,
                'expiry_status' => $expiryStatus,
                'expiry_label' => $this->expiryStatus->label($batch->expiry_date),
                'location_name' => $location['name'] ?? '—',
                'ledger_qty' => (float) $batch->ledger_qty,
                'latest_action' => $latestAction,
                'is_fefo_risk' => $expiryStatus === BatchExpiryStatusService::STATUS_NEAR_EXPIRY,
            ];
        })->filter()->values();
    }

    /**
     * @param  list<int>  $branchIds
     */
    private function batchLedgerStockSubquery(array $branchIds): QueryBuilder
    {
        return DB::table('trx_inventory_movements')
            ->select('inventory_batch_id')
            ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as ledger_qty')
            ->whereIn('branch_id', $branchIds)
            ->whereNotNull('inventory_batch_id')
            ->groupBy('inventory_batch_id')
            ->havingRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) > 0');
    }

    /**
     * @param  list<int>  $branchIds
     */
    private function batchLocationStockSubquery(array $branchIds): QueryBuilder
    {
        return DB::table('trx_inventory_movements as m')
            ->join('inv_inventory_locations as l', 'l.id', '=', 'm.inventory_location_id')
            ->select('m.inventory_batch_id', 'm.inventory_location_id', 'l.name as location_name')
            ->selectRaw('COALESCE(SUM(m.quantity_in) - SUM(m.quantity_out), 0) as ledger_qty')
            ->whereIn('m.branch_id', $branchIds)
            ->whereNotNull('m.inventory_batch_id')
            ->groupBy('m.inventory_batch_id', 'm.inventory_location_id', 'l.name')
            ->havingRaw('COALESCE(SUM(m.quantity_in) - SUM(m.quantity_out), 0) > 0');
    }

    /**
     * @param  list<int>  $batchIds
     * @return array<int, InventoryBatchActionLog>
     */
    private function latestActionLogsByBatch(array $batchIds): array
    {
        if ($batchIds === []) {
            return [];
        }

        return InventoryBatchActionLog::query()
            ->with('actor')
            ->whereIn('inventory_batch_id', $batchIds)
            ->orderByDesc('acted_at')
            ->orderByDesc('id')
            ->get()
            ->unique('inventory_batch_id')
            ->keyBy('inventory_batch_id')
            ->all();
    }

    /**
     * @param  list<int>  $batchIds
     * @return array<int, array{name: string, ledger_qty: float}>
     */
    private function topLocationByBatch(QueryBuilder $locationStock, array $batchIds): array
    {
        if ($batchIds === []) {
            return [];
        }

        $rows = DB::query()
            ->fromSub($locationStock, 'loc_stock')
            ->whereIn('inventory_batch_id', $batchIds)
            ->orderByDesc('ledger_qty')
            ->get()
            ->groupBy('inventory_batch_id')
            ->map(fn (Collection $group) => [
                'name' => (string) $group->first()->location_name,
                'ledger_qty' => (float) $group->first()->ledger_qty,
            ]);

        return $rows->all();
    }

    /**
     * @param  list<int>  $branchIds
     */
    private function actionLogQuery(array $filters, array $branchIds): Builder
    {
        $dateFrom = Carbon::parse((string) $filters['date_from'])->startOfDay();
        $dateTo = Carbon::parse((string) $filters['date_to'])->endOfDay();

        $query = InventoryBatchActionLog::query()
            ->with(['branch', 'batch', 'batch.product', 'actor'])
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('acted_at', [$dateFrom, $dateTo])
            ->orderByDesc('acted_at')
            ->orderByDesc('id');

        if (! empty($filters['action_type'])) {
            $query->where('action_type', $filters['action_type']);
        }

        if (! empty($filters['product'])) {
            $search = '%'.$filters['product'].'%';
            $query->whereHas('batch.product', fn (Builder $q) => $q
                ->where('name', 'ilike', $search)
                ->orWhere('code', 'ilike', $search));
        }

        if (! empty($filters['batch'])) {
            $query->whereHas('batch', fn (Builder $q) => $q->where('batch_number', 'ilike', '%'.$filters['batch'].'%'));
        }

        return $query;
    }

    /**
     * @param  list<int>  $branchIds
     */
    private function disposalRequestQuery(array $filters, array $branchIds): Builder
    {
        $dateFrom = Carbon::parse((string) $filters['date_from'])->startOfDay();
        $dateTo = Carbon::parse((string) $filters['date_to'])->endOfDay();

        $query = InventoryBatchDisposalRequest::query()
            ->with([
                'branch',
                'batch',
                'product',
                'location',
                'actionLog',
                'movement',
                'submittedBy',
                'approvedBy',
                'finalizedBy',
            ])
            ->whereIn('branch_id', $branchIds)
            ->orderByDesc('submitted_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $query->where(function (Builder $q) use ($dateFrom, $dateTo) {
            $q->whereBetween('submitted_at', [$dateFrom, $dateTo])
                ->orWhere(function (Builder $inner) use ($dateFrom, $dateTo) {
                    $inner->whereNull('submitted_at')
                        ->whereBetween('created_at', [$dateFrom, $dateTo]);
                });
        });

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['request_type'])) {
            $query->where('request_type', $filters['request_type']);
        }

        if (! empty($filters['location_id'])) {
            $query->where('inventory_location_id', (int) $filters['location_id']);
        }

        if (! empty($filters['product'])) {
            $search = '%'.$filters['product'].'%';
            $query->whereHas('product', fn (Builder $q) => $q
                ->where('name', 'ilike', $search)
                ->orWhere('code', 'ilike', $search));
        }

        if (! empty($filters['batch'])) {
            $search = '%'.$filters['batch'].'%';
            $query->whereHas('batch', fn (Builder $q) => $q->where('batch_number', 'ilike', $search));
        }

        return $query;
    }

    /**
     * @param  Collection<int, InventoryBatchDisposalRequest>  $disposalRequests
     * @return Collection<int, array<string, mixed>>
     */
    private function buildLedgerEvidenceRows(Collection $disposalRequests): Collection
    {
        return $disposalRequests
            ->filter(fn (InventoryBatchDisposalRequest $row) => $row->status === InventoryBatchDisposalRequestStatus::ADJUSTMENT_RECORDED
                && $row->movement !== null
                && $row->movement->movement_type === InventoryMovement::TYPE_ADJUSTMENT_OUT)
            ->map(fn (InventoryBatchDisposalRequest $row) => [
                'movement' => $row->movement,
                'request' => $row,
                'branch' => $row->branch,
                'product' => $row->product,
                'batch' => $row->batch,
                'location' => $row->location,
            ])
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $expiryRisk
     * @param  Collection<int, InventoryBatchActionLog>  $actionLogs
     * @param  Collection<int, InventoryBatchDisposalRequest>  $disposalRequests
     * @return Collection<int, array<string, mixed>>
     */
    private function buildExceptions(
        Collection $expiryRisk,
        Collection $actionLogs,
        Collection $disposalRequests,
    ): Collection {
        $exceptions = collect();

        $actionBatchIds = $actionLogs->pluck('inventory_batch_id')->unique()->all();
        $disposalBatchIds = $disposalRequests->pluck('inventory_batch_id')->unique()->all();

        foreach ($expiryRisk as $row) {
            if ($row['expiry_status'] !== BatchExpiryStatusService::STATUS_EXPIRED) {
                continue;
            }

            $batchId = $row['batch']->id;
            if (! in_array($batchId, $actionBatchIds, true)) {
                $exceptions->push([
                    'type' => 'expired_no_action_log',
                    'label' => 'Batch kedaluwarsa bersaldo tanpa action log bulan ini',
                    'batch' => $row['batch'],
                    'product' => $row['product'],
                    'branch' => $row['branch'],
                ]);
            }
        }

        $disposalPlannedLogs = $actionLogs->where('action_type', InventoryBatchActionType::DISPOSAL_PLANNED);
        foreach ($disposalPlannedLogs as $log) {
            if (! in_array($log->inventory_batch_id, $disposalBatchIds, true)) {
                $exceptions->push([
                    'type' => 'disposal_planned_no_request',
                    'label' => 'Action disposal_planned tanpa permintaan disposal',
                    'batch' => $log->batch,
                    'product' => $log->batch?->product,
                    'branch' => $log->branch,
                    'action_log' => $log,
                ]);
            }
        }

        foreach ($disposalRequests->where('status', InventoryBatchDisposalRequestStatus::APPROVED) as $request) {
            $exceptions->push([
                'type' => 'approved_not_finalized',
                'label' => 'Permintaan disetujui belum difinalisasi adjustment',
                'request' => $request,
                'batch' => $request->batch,
                'product' => $request->product,
                'branch' => $request->branch,
            ]);
        }

        foreach ($disposalRequests->where('status', InventoryBatchDisposalRequestStatus::ADJUSTMENT_RECORDED) as $request) {
            if ($request->inventory_movement_id === null) {
                $exceptions->push([
                    'type' => 'adjustment_missing_movement',
                    'label' => 'Adjustment dicatat tanpa movement tertaut',
                    'request' => $request,
                    'batch' => $request->batch,
                    'product' => $request->product,
                    'branch' => $request->branch,
                ]);
            } elseif ($request->movement?->movement_type !== InventoryMovement::TYPE_ADJUSTMENT_OUT) {
                $exceptions->push([
                    'type' => 'movement_not_adjustment_out',
                    'label' => 'Movement tertaut bukan ADJUSTMENT_OUT',
                    'request' => $request,
                    'batch' => $request->batch,
                    'product' => $request->product,
                    'branch' => $request->branch,
                    'movement' => $request->movement,
                ]);
            }
        }

        return $exceptions->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $expiryRisk
     * @param  Collection<int, InventoryBatchActionLog>  $actionLogs
     * @param  Collection<int, InventoryBatchDisposalRequest>  $disposalRequests
     * @param  Collection<int, array<string, mixed>>  $exceptions
     * @param  array{branch_ids: list<int>, cross_branch: bool, selected_branch_id: ?int}  $scope
     * @return array<string, int|float|bool>
     */
    private function buildSummary(
        Collection $expiryRisk,
        Collection $actionLogs,
        Collection $disposalRequests,
        Collection $exceptions,
        array $scope,
    ): array {
        $expired = $expiryRisk->where('expiry_status', BatchExpiryStatusService::STATUS_EXPIRED);
        $nearExpiry = $expiryRisk->where('expiry_status', BatchExpiryStatusService::STATUS_NEAR_EXPIRY);
        $adjustmentRecorded = $disposalRequests->where('status', InventoryBatchDisposalRequestStatus::ADJUSTMENT_RECORDED);

        return [
            'total_expired_batches_with_positive_stock' => $expired->count(),
            'total_near_expiry_batches_with_positive_stock' => $nearExpiry->count(),
            'total_action_logs' => $actionLogs->count(),
            'total_disposal_requests' => $disposalRequests->count(),
            'pending_approval_requests' => $disposalRequests->where('status', InventoryBatchDisposalRequestStatus::SUBMITTED)->count(),
            'approved_requests' => $disposalRequests->where('status', InventoryBatchDisposalRequestStatus::APPROVED)->count(),
            'rejected_requests' => $disposalRequests->where('status', InventoryBatchDisposalRequestStatus::REJECTED)->count(),
            'adjustment_recorded_requests' => $adjustmentRecorded->count(),
            'movement_linked_requests' => $disposalRequests->whereNotNull('inventory_movement_id')->count(),
            'total_quantity_requested' => (float) $disposalRequests->sum(fn ($row) => (float) $row->quantity_requested),
            'total_quantity_adjustment_recorded' => (float) $adjustmentRecorded->sum(fn ($row) => (float) $row->quantity_requested),
            'return_supplier_requests' => $disposalRequests->where('request_type', InventoryBatchDisposalRequestType::RETURN_SUPPLIER)->count(),
            'quarantine_related_requests' => $actionLogs->where('action_type', InventoryBatchActionType::QUARANTINE)->count(),
            'exception_count' => $exceptions->count(),
            'cross_branch' => $scope['cross_branch'],
        ];
    }

    /**
     * @param  Collection<int, InventoryBatchActionLog>  $actionLogs
     * @param  Collection<int, InventoryBatchDisposalRequest>  $disposalRequests
     * @param  array{branch_ids: list<int>, cross_branch: bool, selected_branch_id: ?int}  $scope
     * @return array<string, Collection>
     */
    private function buildBreakdowns(Collection $actionLogs, Collection $disposalRequests, array $scope): array
    {
        $byBranch = collect();
        if ($scope['cross_branch'] && $scope['selected_branch_id'] === null) {
            $byBranch = $disposalRequests->groupBy(fn ($row) => $row->branch?->name ?? '—')->map->count()->sortDesc();
        }

        return [
            'by_status' => $disposalRequests->groupBy('status')->map->count()->sortDesc(),
            'by_request_type' => $disposalRequests->groupBy('request_type')->map->count()->sortDesc(),
            'by_action_type' => $actionLogs->groupBy('action_type')->map->count()->sortDesc(),
            'by_branch' => $byBranch,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function monthOptions(): array
    {
        return [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function periodLabel(array $filters): string
    {
        $year = (int) ($filters['year'] ?? now()->year);
        $month = (int) ($filters['month'] ?? now()->month);
        $months = $this->monthOptions();

        return ($months[$month] ?? (string) $month).' '.$year;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string|int|float|null>
     */
    private function mapExpiryExportRow(array $row, string $period): array
    {
        return [
            'expiry_risk',
            $period,
            $row['branch']?->name,
            $row['product']?->name,
            $row['batch']?->batch_number,
            $row['expiry_date'],
            $row['location_name'],
            $row['ledger_qty'],
            $row['latest_action']?->action_type,
            $row['latest_action']?->note,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $row['latest_action']?->actor?->name,
            null,
            null,
            $row['is_fefo_risk'] ? 'fefo_risk' : null,
            $row['expiry_date'],
        ];
    }

    /**
     * @return list<string|int|float|null>
     */
    private function mapActionLogExportRow(InventoryBatchActionLog $row, string $period): array
    {
        return [
            'action_log',
            $period,
            $row->branch?->name,
            $row->batch?->product?->name,
            $row->batch?->batch_number,
            $row->batch?->expiry_date,
            null,
            $row->ledger_quantity_snapshot,
            $row->action_type,
            $row->note,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $row->actor?->name,
            null,
            null,
            null,
            $row->acted_at?->toDateString(),
        ];
    }

    /**
     * @return list<string|int|float|null>
     */
    private function mapDisposalExportRow(InventoryBatchDisposalRequest $row, string $period): array
    {
        $eventDate = $row->submitted_at ?? $row->created_at;

        return [
            'disposal_workflow',
            $period,
            $row->branch?->name,
            $row->product?->name,
            $row->batch?->batch_number,
            $row->batch?->expiry_date,
            $row->location?->name,
            $row->available_quantity_snapshot,
            $row->actionLog?->action_type,
            $row->evidence_note,
            $row->request_type,
            $row->status,
            (float) $row->quantity_requested,
            $row->movement?->movement_type,
            (float) ($row->movement?->quantity_out ?? 0),
            $row->movement?->reference_number,
            $row->evidence_reference,
            $row->submittedBy?->name,
            $row->approvedBy?->name,
            $row->finalizedBy?->name,
            null,
            $eventDate?->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string|int|float|null>
     */
    private function mapLedgerExportRow(array $row, string $period): array
    {
        $movement = $row['movement'];
        $request = $row['request'];

        return [
            'ledger_evidence',
            $period,
            $row['branch']?->name,
            $row['product']?->name,
            $row['batch']?->batch_number,
            $row['batch']?->expiry_date,
            $row['location']?->name,
            null,
            null,
            null,
            $request->request_type,
            $request->status,
            (float) $request->quantity_requested,
            $movement->movement_type,
            (float) $movement->quantity_out,
            $movement->reference_number,
            $request->evidence_reference,
            $request->submittedBy?->name,
            $request->approvedBy?->name,
            $request->finalizedBy?->name,
            null,
            $movement->movement_date,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string|int|float|null>
     */
    private function mapExceptionExportRow(array $row, string $period): array
    {
        return [
            'exception',
            $period,
            $row['branch']?->name,
            $row['product']?->name,
            $row['batch']?->batch_number,
            $row['batch']?->expiry_date ?? null,
            null,
            null,
            $row['action_log']?->action_type ?? null,
            $row['label'],
            $row['request']?->request_type ?? null,
            $row['request']?->status ?? null,
            isset($row['request']) ? (float) $row['request']->quantity_requested : null,
            $row['movement']?->movement_type ?? null,
            isset($row['movement']) ? (float) $row['movement']->quantity_out : null,
            $row['movement']?->reference_number ?? null,
            $row['request']?->evidence_reference ?? null,
            null,
            $row['request']?->approvedBy?->name ?? null,
            $row['request']?->finalizedBy?->name ?? null,
            $row['type'],
            null,
        ];
    }
}
