<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Inventory\Interfaces\InventoryBatchRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryLocationRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryMovementRepositoryInterface;
use App\Modules\Inventory\Interfaces\ProductCategoryRepositoryInterface;
use App\Modules\Inventory\Interfaces\ProductRepositoryInterface;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Requests\InventoryReportFilterRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryReportService
{
    private const EXPORT_ROW_CAP = 5000;

    public function __construct(
        private readonly BranchContext $branchContext,
        private readonly BranchService $branchService,
        private readonly InventoryMovementRepositoryInterface $movements,
        private readonly ProductRepositoryInterface $products,
        private readonly ProductCategoryRepositoryInterface $categories,
        private readonly InventoryLocationRepositoryInterface $locations,
        private readonly InventoryBatchRepositoryInterface $batches,
    ) {}

    public function getCurrentStockReport(array $filters): LengthAwarePaginatorContract
    {
        $branchId = $this->reportBranchId($filters);
        $perPage = (int) ($filters['per_page'] ?? 15);

        return $this->movements->getCurrentStockReport($branchId, $filters, $perPage);
    }

    /**
     * @return array{
     *     rows: LengthAwarePaginatorContract,
     *     opening_balance: float,
     *     date_from: string,
     *     date_to: string,
     *     requires_product: bool,
     *     message: string|null
     * }
     */
    public function getStockCardReport(array $filters): array
    {
        $branchId = $this->reportBranchId($filters);
        $dateFrom = (string) ($filters['date_from'] ?? now()->startOfMonth()->toDateString());
        $dateTo = (string) ($filters['date_to'] ?? now()->toDateString());
        $perPage = (int) ($filters['per_page'] ?? 15);

        if (empty($filters['product_id'])) {
            return [
                'rows' => $this->emptyPaginator($filters, 'stock_card_page'),
                'opening_balance' => 0.0,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'requires_product' => true,
                'message' => 'Pilih produk terlebih dahulu untuk melihat Kartu Stok.',
            ];
        }

        $productId = (int) $filters['product_id'];
        $locationId = isset($filters['inventory_location_id']) ? (int) $filters['inventory_location_id'] : null;
        $batchId = isset($filters['inventory_batch_id']) ? (int) $filters['inventory_batch_id'] : null;
        $rows = $this->movements->getStockCardReport($branchId, $filters, $dateFrom, $dateTo, $perPage);
        $openingBalance = $this->movements->getStockCardOpeningBalance($branchId, $productId, $locationId, $dateFrom, $batchId);
        $periodBalanceBeforePage = $this->movements->getStockCardPeriodBalanceBeforePage(
            $branchId,
            $filters,
            $dateFrom,
            $dateTo,
            $perPage,
            $rows->currentPage(),
        );

        $runningBalance = $openingBalance + $periodBalanceBeforePage;
        $rows->setCollection($rows->getCollection()->map(function (InventoryMovement $movement) use (&$runningBalance) {
            $runningBalance += (float) $movement->quantity_in - (float) $movement->quantity_out;
            $movement->setAttribute('running_balance', $runningBalance);
            $movement->setAttribute('reference_label', $this->formatReference($movement));

            return $movement;
        }));

        return [
            'rows' => $rows,
            'opening_balance' => $openingBalance,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'requires_product' => false,
            'message' => null,
        ];
    }

    public function getLowStockReport(array $filters): LengthAwarePaginatorContract
    {
        $branchId = $this->reportBranchId($filters);
        $perPage = (int) ($filters['per_page'] ?? 15);
        $rows = $this->movements->getLowStockReport($branchId, $filters, $perPage);
        $productIds = $rows->getCollection()->pluck('product_id')->unique()->values()->all();
        $stockSources = $this->movements
            ->getStockAvailabilityForProducts($branchId, $productIds)
            ->groupBy('product_id');

        $rows->setCollection($rows->getCollection()->map(function ($row) use ($stockSources) {
            $currentStock = (float) $row->current_stock;
            $minimumStock = (float) $row->minimum_stock;
            $shortageQty = max($minimumStock - $currentStock, 0);

            $row->setAttribute('shortage_qty', $shortageQty);
            $row->setAttribute('recommendation', $this->recommendLowStockAction($row, $stockSources->get($row->product_id, collect())));

            return $row;
        }));

        return $rows;
    }

    public function getStockMutationReport(array $filters): LengthAwarePaginatorContract
    {
        $branchId = $this->reportBranchId($filters);
        $dateFrom = (string) ($filters['date_from'] ?? now()->startOfMonth()->toDateString());
        $dateTo = (string) ($filters['date_to'] ?? now()->toDateString());
        $perPage = (int) ($filters['per_page'] ?? 15);
        $rows = $this->movements->getStockMutationReport($branchId, $filters, $dateFrom, $dateTo, $perPage);

        $rows->setCollection($rows->getCollection()->map(function ($row) use ($dateFrom, $dateTo) {
            $row->period_label = $dateFrom.' - '.$dateTo;

            return $row;
        }));

        return $rows;
    }

    public function getInventoryValuationReport(array $filters): LengthAwarePaginatorContract
    {
        $branchId = $this->reportBranchId($filters);
        $perPage = (int) ($filters['per_page'] ?? 15);

        return $this->movements->getInventoryValuationReport($branchId, $filters, $perPage);
    }

    public function getRoomStockReport(array $filters): LengthAwarePaginatorContract
    {
        $branchId = $this->reportBranchId($filters);
        $perPage = (int) ($filters['per_page'] ?? 15);
        $rows = $this->movements->getRoomStockReport($branchId, $filters, $perPage);
        $productIds = $rows->getCollection()->pluck('product_id')->unique()->values()->all();
        $stockSources = $this->movements
            ->getStockAvailabilityForProducts($branchId, $productIds)
            ->groupBy('product_id');

        $rows->setCollection($rows->getCollection()->map(function ($row) use ($stockSources) {
            // Rows are stdClass (DB::query fromSub), so assign directly rather than setAttribute().
            $row->recommendation = $this->recommendRoomStockRefill($row, $stockSources->get($row->product_id, collect()));

            return $row;
        }));

        return $rows;
    }

    /**
     * Threshold-aware room_stock rows that need a refill, for the printable checklist.
     *
     * Reuses getRoomStockReport() so the checklist never diverges from the on-screen
     * report. A row needs refill when current_stock < effective_minimum_stock. Filtering
     * on the numeric comparison (not stock_status) is deliberate: zero-stock rows that
     * have a configured room threshold are classified 'empty' before 'low', so a
     * status-only filter would drop them. The numeric test catches both 'empty' (with a
     * threshold) and 'low' rows. Normal and overstock rows fall out naturally. Rows are
     * read-only query aliases — no stock is mutated and no movement is created.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object>
     */
    public function getRoomStockRefillChecklist(array $filters): Collection
    {
        $checklistFilters = array_merge($filters, ['per_page' => self::EXPORT_ROW_CAP]);
        // Do not force a single stock_status: empty-with-threshold and low both need refill.
        unset($checklistFilters['stock_status']);

        return $this->getRoomStockReport($checklistFilters)
            ->getCollection()
            ->filter(fn ($row) => (float) $row->current_stock < (float) $row->effective_minimum_stock)
            ->values();
    }

    public function exportCsv(array $filters): StreamedResponse
    {
        $reportType = (string) $filters['report_type'];
        $export = $this->buildExportPayload($reportType, $filters);
        $filename = 'inventory-'.$reportType.'-'.now()->toDateString().'.csv';

        return response()->streamDownload(function () use ($export) {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $export['headers']);

            foreach ($export['rows'] as $row) {
                fputcsv($handle, $row);
            }

            if ($export['capped']) {
                fputcsv($handle, []);
                fputcsv($handle, ['CATATAN', 'Export dibatasi '.self::EXPORT_ROW_CAP.' baris. Persempit filter untuk melihat sisa data.']);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * RME-enabled branches the current user may select on Inventory Reports.
     *
     * @return Collection<int, Branch>
     */
    public function reportBranchOptions(?User $user = null): Collection
    {
        $user ??= Auth::user();
        $rmeBranches = $this->branchService->listRmeEnabled();

        if ($this->userCanSelectCrossBranchReport($user)) {
            return $rmeBranches;
        }

        $activeBranchId = $this->branchContext->id();
        $scoped = $rmeBranches->filter(fn (Branch $branch) => $branch->id === $activeBranchId);

        if ($scoped->isNotEmpty()) {
            return $scoped->values();
        }

        return $rmeBranches->take(1)->values();
    }

    /**
     * Resolve the validated report branch from request filters and allowed options.
     *
     * Unauthorized or missing branch_id falls back to the active branch when allowed,
     * otherwise the first allowed RME-enabled branch.
     */
    public function resolveReportBranchId(array $filters, ?User $user = null): int
    {
        $allowedIds = $this->reportBranchOptions($user)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($allowedIds === []) {
            throw new RuntimeException('No RME-enabled branch is available for inventory reports.');
        }

        $requested = isset($filters['branch_id']) ? (int) $filters['branch_id'] : null;

        if ($requested !== null && in_array($requested, $allowedIds, true)) {
            return $requested;
        }

        $activeBranchId = $this->branchContext->id();

        if ($activeBranchId !== null && in_array($activeBranchId, $allowedIds, true)) {
            return $activeBranchId;
        }

        return $allowedIds[0];
    }

    /**
     * RME-enabled branch locations for report dependent filters.
     *
     * @return Collection<int, InventoryLocation>
     */
    public function reportLocationOptions(int $branchId): Collection
    {
        return $this->locations->listActive($branchId);
    }

    /**
     * Active batches for the selected branch, optionally narrowed to a product.
     *
     * @return Collection<int, InventoryBatch>
     */
    public function reportBatchOptions(int $branchId, ?int $productId = null): Collection
    {
        return $this->batches
            ->paginateForBranch($branchId, array_filter([
                'product_id' => $productId,
                'is_active' => true,
            ]), 500)
            ->getCollection();
    }

    /**
     * Branch-scoped active products for report filters.
     *
     * @return Collection<int, Product>
     */
    public function reportProductOptions(int $branchId): Collection
    {
        return $this->products->listActive($branchId);
    }

    /**
     * Drop dependent filters that do not belong to the validated report branch.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function sanitizeReportFilters(array $filters, ?User $user = null): array
    {
        $branchId = $this->resolveReportBranchId($filters, $user);
        $filters['branch_id'] = $branchId;

        $allowedLocationIds = $this->reportLocationOptions($branchId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (isset($filters['inventory_location_id']) && ! in_array((int) $filters['inventory_location_id'], $allowedLocationIds, true)) {
            unset($filters['inventory_location_id']);
        }

        $allowedProductIds = $this->reportProductOptions($branchId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (isset($filters['product_id']) && ! in_array((int) $filters['product_id'], $allowedProductIds, true)) {
            unset($filters['product_id'], $filters['inventory_batch_id']);
        }

        $allowedCategoryIds = $this->categories->listActive($branchId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (isset($filters['category_id']) && ! in_array((int) $filters['category_id'], $allowedCategoryIds, true)) {
            unset($filters['category_id']);
        }

        if (isset($filters['inventory_batch_id'])) {
            $productId = isset($filters['product_id']) ? (int) $filters['product_id'] : null;
            $batch = $this->batches->findForBranch($branchId, (int) $filters['inventory_batch_id']);

            if (! $batch instanceof InventoryBatch
                || ! $batch->is_active
                || ($productId !== null && (int) $batch->product_id !== $productId)) {
                unset($filters['inventory_batch_id']);
            }
        }

        return $filters;
    }

    /**
     * Normalize report filters for index, export, and print using validated branch context.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function prepareReportFilters(array $filters, ?User $user = null, ?string $activeTab = null): array
    {
        $filters = $this->sanitizeReportFilters($filters, $user);

        $reportType = $this->resolveReportType($filters, $activeTab);
        if ($reportType !== null) {
            $filters['report_type'] = $reportType;
        }

        if (in_array($reportType, ['stock_card', 'mutation'], true)) {
            $filters['date_from'] = (string) ($filters['date_from'] ?? now()->startOfMonth()->toDateString());
            $filters['date_to'] = (string) ($filters['date_to'] ?? now()->toDateString());
        }

        return $filters;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function resolveReportType(array $filters, ?string $activeTab = null): ?string
    {
        $reportType = $filters['report_type'] ?? null;

        if (is_string($reportType) && in_array($reportType, InventoryReportFilterRequest::REPORT_TYPES, true)) {
            return $reportType;
        }

        if ($activeTab !== null && in_array($activeTab, InventoryReportFilterRequest::REPORT_TABS, true)) {
            return $activeTab;
        }

        $reportTab = $filters['report_tab'] ?? null;
        if (is_string($reportTab) && in_array($reportTab, InventoryReportFilterRequest::REPORT_TABS, true)) {
            return $reportTab;
        }

        $tab = $filters['tab'] ?? null;
        if (is_string($tab) && isset(InventoryReportFilterRequest::TAB_KEBAB_ALIASES[$tab])) {
            return InventoryReportFilterRequest::TAB_KEBAB_ALIASES[$tab];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getReportFilterOptions(array $filters, ?Collection $branchOptions = null): array
    {
        $branchId = $this->reportBranchId($filters);
        $branchOptions ??= $this->reportBranchOptions();
        $productId = isset($filters['product_id']) ? (int) $filters['product_id'] : null;

        return [
            'branches' => $branchOptions,
            'products' => $this->reportProductOptions($branchId),
            'categories' => $this->categories->listActive($branchId),
            'locations' => $this->reportLocationOptions($branchId),
            'batches' => $this->reportBatchOptions($branchId, $productId),
            'movementTypes' => InventoryMovement::TYPES,
            'stockStatuses' => [
                'normal' => 'Normal',
                'low' => 'Low',
                'empty' => 'Kosong',
                'overstock' => 'Overstock',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function reportBranchId(array $filters): int
    {
        return (int) ($filters['branch_id'] ?? $this->branchContext->requireId());
    }

    private function userCanSelectCrossBranchReport(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return $user->can('view_inventory_cross_branch_analytics')
            || $user->can('view_owner_dashboard')
            || $user->hasRole('Super Admin');
    }

    private function emptyPaginator(array $filters, string $pageName): LengthAwarePaginatorContract
    {
        $perPage = (int) ($filters['per_page'] ?? 15);

        return new LengthAwarePaginator(
            items: [],
            total: 0,
            perPage: $perPage,
            currentPage: LengthAwarePaginator::resolveCurrentPage($pageName),
            options: [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ],
        );
    }

    private function formatReference(InventoryMovement $movement): string
    {
        if (! $movement->reference_type && ! $movement->reference_id) {
            return '-';
        }

        return trim(($movement->reference_type ?: 'Reference').' #'.($movement->reference_id ?: '-'));
    }

    private function recommendLowStockAction(object $row, mixed $stockSources): string
    {
        $baseRecommendation = (float) $row->current_stock <= 0
            ? 'Segera restock'
            : 'Perlu restock';

        $otherSources = collect($stockSources)
            ->reject(fn ($source) => (int) $source->inventory_location_id === (int) $row->inventory_location_id);

        $mainWarehouse = $otherSources->first(function ($source) {
            return (float) $source->current_stock > 0
                && preg_match('/\b(gudang utama|main warehouse)\b/i', (string) $source->inventory_location_name);
        });

        if ($mainWarehouse) {
            return $baseRecommendation.' - Refill dari Gudang Utama';
        }

        $surplusSource = $otherSources->first(
            fn ($source) => (float) $source->current_stock > (float) $source->minimum_stock
        );

        if ($surplusSource) {
            return $baseRecommendation.' - Pertimbangkan transfer dari lokasi lain';
        }

        return $baseRecommendation.' - Buat permintaan pembelian';
    }

    /**
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>, capped: bool}
     */
    private function buildExportPayload(string $reportType, array $filters): array
    {
        $exportFilters = array_merge($filters, ['per_page' => self::EXPORT_ROW_CAP]);

        return match ($reportType) {
            'current_stock' => $this->exportFromPaginator(
                $this->getCurrentStockReport($exportFilters),
                [
                    'Cabang',
                    'Kode Produk',
                    'Produk',
                    'Kategori',
                    'Satuan',
                    'Lokasi/Ruangan',
                    'Stok Saat Ini',
                    'Minimum',
                    'Status',
                    'Movement Terakhir',
                ],
                fn ($row) => [
                    (string) $row->branch_name,
                    (string) $row->product_code,
                    (string) $row->product_name,
                    (string) $row->category_name,
                    (string) ($row->unit_symbol ?: $row->unit_name),
                    (string) $row->inventory_location_name,
                    $this->formatQuantity($row->current_stock),
                    $this->formatQuantity($row->minimum_stock),
                    $this->stockStatusLabel((string) $row->stock_status),
                    $this->formatDate($row->last_movement_date),
                ],
            ),
            'stock_card' => $this->exportStockCard($exportFilters),
            'low_stock' => $this->exportFromPaginator(
                $this->getLowStockReport($exportFilters),
                [
                    'Cabang',
                    'Kode Produk',
                    'Produk',
                    'Kategori',
                    'Satuan',
                    'Lokasi/Ruangan',
                    'Stok Saat Ini',
                    'Minimum',
                    'Kekurangan',
                    'Status',
                    'Rekomendasi',
                    'Movement Terakhir',
                ],
                fn ($row) => [
                    (string) $row->branch_name,
                    (string) $row->product_code,
                    (string) $row->product_name,
                    (string) $row->category_name,
                    (string) ($row->unit_symbol ?: $row->unit_name),
                    (string) $row->inventory_location_name,
                    $this->formatQuantity($row->current_stock),
                    $this->formatQuantity($row->minimum_stock),
                    $this->formatQuantity($row->shortage_qty),
                    $this->stockStatusLabel((string) $row->stock_status),
                    (string) $row->recommendation,
                    $this->formatDate($row->last_movement_date),
                ],
            ),
            'mutation' => $this->exportFromPaginator(
                $this->getStockMutationReport($exportFilters),
                [
                    'Cabang',
                    'Kode Produk',
                    'Produk',
                    'Kategori',
                    'Satuan',
                    'Lokasi/Ruangan',
                    'Saldo Awal',
                    'Masuk',
                    'Keluar',
                    'Saldo Akhir',
                    'Periode',
                ],
                fn ($row) => [
                    (string) $row->branch_name,
                    (string) $row->product_code,
                    (string) $row->product_name,
                    (string) $row->category_name,
                    (string) ($row->unit_symbol ?: $row->unit_name),
                    (string) $row->inventory_location_name,
                    $this->formatQuantity($row->opening_balance),
                    $this->formatQuantity($row->total_in),
                    $this->formatQuantity($row->total_out),
                    $this->formatQuantity($row->ending_balance),
                    (string) $row->period_label,
                ],
            ),
            'valuation' => $this->exportFromPaginator(
                $this->getInventoryValuationReport($exportFilters),
                [
                    'Cabang',
                    'Kode Produk',
                    'Produk',
                    'Kategori',
                    'Satuan',
                    'Lokasi/Ruangan',
                    'Stok Saat Ini',
                    'Unit Cost',
                    'Total Nilai',
                    'Sumber',
                    'Movement Terakhir',
                ],
                fn ($row) => [
                    (string) $row->branch_name,
                    (string) $row->product_code,
                    (string) $row->product_name,
                    (string) $row->category_name,
                    (string) ($row->unit_symbol ?: $row->unit_name),
                    (string) $row->inventory_location_name,
                    $this->formatQuantity($row->current_stock),
                    $this->formatMoney($row->unit_cost),
                    $this->formatMoney($row->total_value),
                    (string) $row->valuation_source,
                    $this->formatDate($row->last_movement_date),
                ],
            ),
            'room_stock' => $this->exportFromPaginator(
                $this->getRoomStockReport($exportFilters),
                [
                    'Cabang',
                    'Ruangan/Lokasi',
                    'Kode Produk',
                    'Produk',
                    'Kategori',
                    'Satuan',
                    'Stok Saat Ini',
                    'Minimum Stok Ruangan',
                    'Maksimum Stok Ruangan',
                    'Saran Refill',
                    'Status',
                    'Rekomendasi Refill',
                    'Movement Terakhir',
                ],
                fn ($row) => [
                    (string) $row->branch_name,
                    (string) $row->inventory_location_name,
                    (string) $row->product_code,
                    (string) $row->product_name,
                    (string) $row->category_name,
                    (string) ($row->unit_symbol ?: $row->unit_name),
                    $this->formatQuantity($row->current_stock),
                    $this->formatQuantity($row->minimum_stock),
                    $row->maximum_stock === null ? '' : $this->formatQuantity($row->maximum_stock),
                    $this->formatQuantity($row->suggested_refill_qty),
                    $this->stockStatusLabel((string) $row->stock_status),
                    (string) $row->recommendation,
                    $this->formatDate($row->last_movement_date),
                ],
            ),
        };
    }

    /**
     * @param  array<int, string>  $headers
     * @param  callable(object): array<int, string>  $mapper
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>, capped: bool}
     */
    private function exportFromPaginator(LengthAwarePaginatorContract $paginator, array $headers, callable $mapper): array
    {
        return [
            'headers' => $headers,
            'rows' => $paginator->getCollection()
                ->map(fn ($row) => $mapper($row))
                ->values()
                ->all(),
            'capped' => $paginator->total() > $paginator->count(),
        ];
    }

    /**
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>, capped: bool}
     */
    private function exportStockCard(array $filters): array
    {
        $stockCard = $this->getStockCardReport($filters);

        return $this->exportFromPaginator(
            $stockCard['rows'],
            [
                'Tanggal',
                'Cabang',
                'Kode Produk',
                'Produk',
                'Lokasi/Ruangan',
                'Tipe Movement',
                'Masuk',
                'Keluar',
                'Saldo',
                'Referensi',
                'Dibuat Oleh',
                'Catatan',
            ],
            fn (InventoryMovement $movement) => [
                $this->formatDate($movement->movement_date),
                (string) ($movement->branch?->name ?? ''),
                (string) ($movement->product?->code ?? ''),
                (string) ($movement->product?->name ?? ''),
                (string) ($movement->inventoryLocation?->name ?? ''),
                (string) $movement->movement_type,
                $this->formatQuantity($movement->quantity_in),
                $this->formatQuantity($movement->quantity_out),
                $this->formatQuantity($movement->running_balance),
                (string) $movement->reference_label,
                (string) ($movement->createdBy?->name ?? ''),
                (string) ($movement->notes ?? ''),
            ],
        );
    }

    private function stockStatusLabel(string $status): string
    {
        return match ($status) {
            'empty' => 'Kosong',
            'low' => 'Low Stock',
            'overstock' => 'Overstock',
            default => 'Normal',
        };
    }

    private function formatQuantity(mixed $value): string
    {
        return number_format((float) $value, 4, '.', '');
    }

    private function formatMoney(mixed $value): string
    {
        return format_currency_id($value);
    }

    private function formatDate(mixed $value): string
    {
        if (! $value) {
            return '';
        }

        return Carbon::parse($value)->format('d M Y');
    }

    private function recommendRoomStockRefill(object $row, mixed $stockSources): string
    {
        $currentStock = (float) $row->current_stock;
        $minimumStock = (float) $row->minimum_stock;

        if ($currentStock > 0 && $currentStock >= $minimumStock) {
            return 'Stok ruangan cukup';
        }

        $baseRecommendation = $currentStock <= 0
            ? 'Segera refill ruangan'
            : 'Perlu refill';

        $otherSources = collect($stockSources)
            ->reject(fn ($source) => (int) $source->inventory_location_id === (int) $row->inventory_location_id);

        $mainWarehouse = $otherSources->first(function ($source) {
            return (float) $source->current_stock > 0
                && preg_match('/\b(gudang utama|main warehouse)\b/i', (string) $source->inventory_location_name);
        });

        if ($mainWarehouse) {
            return $baseRecommendation.' - Refill dari Gudang Utama';
        }

        $surplusSource = $otherSources->first(
            fn ($source) => (float) $source->current_stock > (float) $source->minimum_stock
        );

        if ($surplusSource) {
            return $baseRecommendation.' - Pertimbangkan transfer dari lokasi lain';
        }

        return $baseRecommendation.' - Buat permintaan pembelian';
    }
}
