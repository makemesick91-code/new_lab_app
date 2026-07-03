<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Enums\InventoryBatchActionType;
use App\Modules\Inventory\Enums\InventoryBatchDisposalRequestStatus;
use App\Modules\Inventory\Enums\InventoryBatchDisposalRequestType;
use App\Modules\Inventory\Models\InventoryBatchDisposalRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryBatchDisposalReportService
{
    private const EXPORT_ROW_CAP = 5000;

    public function __construct(
        private readonly InventoryReportService $reports,
        private readonly BranchContext $branchContext,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function prepareFilters(array $filters, ?User $user = null): array
    {
        $filters['date_from'] = (string) ($filters['date_from'] ?? now()->startOfMonth()->toDateString());
        $filters['date_to'] = (string) ($filters['date_to'] ?? now()->toDateString());

        $scope = $this->resolveBranchScope($filters, $user);
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
        $user ??= Auth::user();
        $allowedIds = $this->reports->reportBranchOptions($user)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($allowedIds === []) {
            $fallback = $this->branchContext->requireId();

            return [
                'branch_ids' => [$fallback],
                'cross_branch' => false,
                'selected_branch_id' => $fallback,
            ];
        }

        if (count($allowedIds) <= 1) {
            $selected = $this->reports->resolveReportBranchId($filters, $user);

            return [
                'branch_ids' => [$selected],
                'cross_branch' => false,
                'selected_branch_id' => $selected,
            ];
        }

        if (array_key_exists('branch_id', $filters) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $selected = $this->reports->resolveReportBranchId($filters, $user);

            return [
                'branch_ids' => [$selected],
                'cross_branch' => true,
                'selected_branch_id' => $selected,
            ];
        }

        return [
            'branch_ids' => $allowedIds,
            'cross_branch' => true,
            'selected_branch_id' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getReport(array $filters, ?User $user = null, int $perPage = 15): array
    {
        $scope = $this->resolveBranchScope($filters, $user);
        $baseQuery = $this->baseQuery($filters, $scope['branch_ids']);

        return [
            'summary' => $this->buildSummary(clone $baseQuery, $scope),
            'breakdowns' => $this->buildBreakdowns(clone $baseQuery, $scope),
            'rows' => $this->paginateRows($baseQuery, $perPage),
            'scope' => $scope,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function getDashboardKpis(int $branchId, ?User $user = null): array
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $today = now()->toDateString();
        $filters = [
            'date_from' => $monthStart,
            'date_to' => $today,
            'branch_id' => $branchId,
        ];

        $scope = [
            'branch_ids' => [$branchId],
            'cross_branch' => false,
            'selected_branch_id' => $branchId,
        ];

        $baseQuery = $this->baseQuery($filters, $scope['branch_ids']);
        $summary = $this->buildSummary(clone $baseQuery, $scope);

        return [
            'pending_disposal_approval' => $summary['pending_approval_count'],
            'adjustment_recorded_this_month' => $summary['adjustment_recorded_count'],
            'expired_disposal_requests' => $summary['expired_type_count'],
            'movement_linked_requests' => $summary['movement_linked_count'],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function exportCsv(array $filters, ?User $user = null): StreamedResponse
    {
        $scope = $this->resolveBranchScope($filters, $user);
        $rows = $this->baseQuery($filters, $scope['branch_ids'])
            ->limit(self::EXPORT_ROW_CAP + 1)
            ->get();

        $capped = $rows->count() > self::EXPORT_ROW_CAP;
        if ($capped) {
            $rows = $rows->take(self::EXPORT_ROW_CAP);
        }

        $headers = [
            'date',
            'branch',
            'product',
            'batch_number',
            'expiry_date',
            'location',
            'request_type',
            'status',
            'quantity_requested',
            'available_quantity_snapshot',
            'movement_type',
            'movement_quantity_out',
            'action_type',
            'evidence_reference',
            'submitted_by',
            'submitted_at',
            'approved_by',
            'approved_at',
            'rejected_by',
            'rejected_at',
            'finalized_by',
            'finalized_at',
        ];

        $filename = 'laporan-disposal-adjustment-batch-'.now()->toDateString().'.csv';

        return response()->streamDownload(function () use ($rows, $headers, $capped) {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, $this->mapExportRow($row));
            }

            if ($capped) {
                fputcsv($handle, []);
                fputcsv($handle, ['CATATAN', 'Export dibatasi '.self::EXPORT_ROW_CAP.' baris. Persempit filter untuk melihat sisa data.']);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
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
            'hasMovementOptions' => [
                '' => 'Semua',
                'yes' => 'Sudah Ada',
                'no' => 'Belum Ada',
            ],
        ];
    }

    /**
     * @param  list<int>  $branchIds
     */
    private function baseQuery(array $filters, array $branchIds): Builder
    {
        $query = InventoryBatchDisposalRequest::query()
            ->with([
                'branch',
                'batch',
                'product',
                'location',
                'actionLog.actor',
                'movement',
                'submittedBy',
                'approvedBy',
                'rejectedBy',
                'finalizedBy',
            ])
            ->whereIn('branch_id', $branchIds)
            ->orderByDesc('submitted_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $dateFrom = Carbon::parse((string) $filters['date_from'])->startOfDay();
        $dateTo = Carbon::parse((string) $filters['date_to'])->endOfDay();

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

        if (($filters['has_movement'] ?? null) === 'yes') {
            $query->whereNotNull('inventory_movement_id');
        } elseif (($filters['has_movement'] ?? null) === 'no') {
            $query->whereNull('inventory_movement_id');
        }

        if (! empty($filters['action_type'])) {
            $query->whereHas('actionLog', fn (Builder $q) => $q->where('action_type', $filters['action_type']));
        }

        return $query;
    }

    /**
     * @param  array{branch_ids: list<int>, cross_branch: bool, selected_branch_id: ?int}  $scope
     * @return array<string, int|float>
     */
    private function buildSummary(Builder $query, array $scope): array
    {
        $rows = (clone $query)->get();

        $adjustmentRecorded = $rows->where('status', InventoryBatchDisposalRequestStatus::ADJUSTMENT_RECORDED);

        return [
            'total_requests' => $rows->count(),
            'submitted_count' => $rows->where('status', InventoryBatchDisposalRequestStatus::SUBMITTED)->count(),
            'approved_count' => $rows->where('status', InventoryBatchDisposalRequestStatus::APPROVED)->count(),
            'rejected_count' => $rows->where('status', InventoryBatchDisposalRequestStatus::REJECTED)->count(),
            'adjustment_recorded_count' => $adjustmentRecorded->count(),
            'cancelled_count' => $rows->where('status', InventoryBatchDisposalRequestStatus::CANCELLED)->count(),
            'pending_approval_count' => $rows->where('status', InventoryBatchDisposalRequestStatus::SUBMITTED)->count(),
            'total_quantity_requested' => (float) $rows->sum(fn ($row) => (float) $row->quantity_requested),
            'total_quantity_adjustment_recorded' => (float) $adjustmentRecorded->sum(fn ($row) => (float) $row->quantity_requested),
            'movement_linked_count' => $rows->whereNotNull('inventory_movement_id')->count(),
            'expired_type_count' => $rows->filter(fn ($row) => $row->request_type === InventoryBatchDisposalRequestType::EXPIRED
                || ($row->batch?->expiry_date && Carbon::parse($row->batch->expiry_date)->isPast())
            )->count(),
            'return_supplier_count' => $rows->where('request_type', InventoryBatchDisposalRequestType::RETURN_SUPPLIER)->count(),
            'disposal_count' => $rows->where('request_type', InventoryBatchDisposalRequestType::DISPOSAL)->count(),
            'cross_branch' => $scope['cross_branch'],
        ];
    }

    /**
     * @param  array{branch_ids: list<int>, cross_branch: bool, selected_branch_id: ?int}  $scope
     * @return array<string, Collection>
     */
    private function buildBreakdowns(Builder $query, array $scope): array
    {
        $rows = (clone $query)->get();

        $byStatus = $rows->groupBy('status')->map->count()->sortDesc();
        $byType = $rows->groupBy('request_type')->map->count()->sortDesc();

        $byBranch = collect();
        if ($scope['cross_branch'] && $scope['selected_branch_id'] === null) {
            $byBranch = $rows->groupBy(fn ($row) => $row->branch?->name ?? '—')->map->count()->sortDesc();
        }

        $byMonth = $rows->groupBy(function ($row) {
            $date = $row->submitted_at ?? $row->created_at;

            return $date?->format('Y-m') ?? '—';
        })->map->count()->sortKeysDesc();

        return [
            'by_status' => $byStatus,
            'by_request_type' => $byType,
            'by_branch' => $byBranch,
            'by_month' => $byMonth,
        ];
    }

    private function paginateRows(Builder $query, int $perPage): LengthAwarePaginator
    {
        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * @return list<string|int|float|null>
     */
    private function mapExportRow(InventoryBatchDisposalRequest $row): array
    {
        $eventDate = $row->submitted_at ?? $row->created_at;

        return [
            $eventDate?->toDateString(),
            $row->branch?->name,
            $row->product?->name,
            $row->batch?->batch_number,
            $row->batch?->expiry_date,
            $row->location?->name,
            $row->requestTypeLabel(),
            $row->statusLabel(),
            (float) $row->quantity_requested,
            (float) $row->available_quantity_snapshot,
            $row->movement?->movement_type,
            (float) ($row->movement?->quantity_out ?? 0),
            $row->actionLog?->action_type,
            $row->evidence_reference,
            $row->submittedBy?->name,
            $row->submitted_at?->toDateTimeString(),
            $row->approvedBy?->name,
            $row->approved_at?->toDateTimeString(),
            $row->rejectedBy?->name,
            $row->rejected_at?->toDateTimeString(),
            $row->finalizedBy?->name,
            $row->finalized_at?->toDateTimeString(),
        ];
    }
}
