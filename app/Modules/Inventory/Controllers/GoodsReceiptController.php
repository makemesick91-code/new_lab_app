<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Controllers\Concerns\RendersInventoryViews;
use App\Modules\Inventory\Interfaces\GoodsReceiptRepositoryInterface;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Requests\CancelGoodsReceiptRequest;
use App\Modules\Inventory\Requests\PostGoodsReceiptRequest;
use App\Modules\Inventory\Requests\StoreGoodsReceiptRequest;
use App\Modules\Inventory\Requests\SubmitGoodsReceiptRequest;
use App\Modules\Inventory\Requests\UpdateGoodsReceiptRequest;
use App\Modules\Inventory\Services\GoodsReceiptService;
use App\Modules\Inventory\Services\InventoryLocationService;
use App\Modules\Inventory\Services\InventoryStockService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class GoodsReceiptController extends Controller
{
    use AuthorizesRequests;
    use RendersInventoryViews;

    public function __construct(
        private readonly GoodsReceiptRepositoryInterface $goodsReceipts,
        private readonly GoodsReceiptService $goodsReceiptService,
        private readonly InventoryLocationService $locations,
        private readonly InventoryStockService $inventoryStock,
        private readonly BranchContext $branchContext,
    ) {}

    public function index(Request $request): View|Response
    {
        $this->authorize('viewAny', GoodsReceipt::class);

        $branchId = $this->branchContext->requireId();

        return $this->renderInventoryView('inventory.goods-receipts.index', [
            'goodsReceipts' => $this->goodsReceiptService->listForBranch($branchId, $request->all()),
            'statuses' => GoodsReceipt::STATUSES,
            'purchaseOrders' => $this->goodsReceipts->findFilterablePurchaseOrders($branchId),
            'filters' => $request->only([
                'search',
                'status',
                'purchase_order_id',
                'date_from',
                'date_to',
            ]),
        ]);
    }

    public function create(Request $request): View|Response|RedirectResponse
    {
        $this->authorize('create', GoodsReceipt::class);

        $branchId = $this->branchContext->requireId();
        $purchaseOrder = null;
        $prefillItems = [];

        if ($request->filled('purchase_order_id')) {
            try {
                $purchaseOrder = $this->goodsReceiptService->findEligiblePurchaseOrderForCreate(
                    (int) $request->query('purchase_order_id'),
                );
                $prefillItems = $this->goodsReceiptService->buildPrefillItemsFromPurchaseOrder($purchaseOrder);
            } catch (ValidationException $exception) {
                return redirect()
                    ->route('inventory.goods-receipts.create')
                    ->withErrors($exception->errors());
            }
        }

        return $this->renderInventoryView('inventory.goods-receipts.create', [
            'locations' => $this->locations->listActive(),
            'receivablePurchaseOrders' => $this->goodsReceipts->findReceivablePurchaseOrders($branchId),
            'purchaseOrder' => $purchaseOrder,
            'prefillItems' => $prefillItems,
            'batchesByProduct' => $this->batchesByProductIds($this->productIdsFromPrefill($prefillItems, $purchaseOrder)),
        ]);
    }

    public function store(StoreGoodsReceiptRequest $request): RedirectResponse
    {
        $this->authorize('create', GoodsReceipt::class);

        $goodsReceipt = $this->goodsReceiptService->createFromPurchaseOrder(
            $request->validated(),
            $request->user(),
        );

        return redirect()->route('inventory.goods-receipts.show', $goodsReceipt)
            ->with('status', 'Penerimaan barang berhasil dibuat.');
    }

    public function show(GoodsReceipt $goodsReceipt): View|Response
    {
        $this->authorize('view', $goodsReceipt);

        $goodsReceipt = $this->goodsReceipts->findForBranch(
            $this->branchContext->requireId(),
            $goodsReceipt->id,
        ) ?? abort(404);

        $ledgerMovements = $goodsReceipt->isPosted()
            ? InventoryMovement::query()
                ->with(['product', 'inventoryLocation', 'inventoryBatch'])
                ->where('branch_id', $goodsReceipt->branch_id)
                ->where('reference_type', $goodsReceipt->getTable())
                ->where('reference_id', $goodsReceipt->id)
                ->orderBy('id')
                ->get()
            : collect();

        return $this->renderInventoryView('inventory.goods-receipts.show', [
            'goodsReceipt' => $goodsReceipt,
            'ledgerMovements' => $ledgerMovements,
        ]);
    }

    public function edit(GoodsReceipt $goodsReceipt): View|Response
    {
        $this->authorize('update', $goodsReceipt);

        $branchId = $this->branchContext->requireId();
        $goodsReceipt = $this->goodsReceipts->findForBranch($branchId, $goodsReceipt->id) ?? abort(404);

        return $this->renderInventoryView('inventory.goods-receipts.edit', [
            'goodsReceipt' => $goodsReceipt,
            'locations' => $this->locations->listActive(),
            'batchesByProduct' => $this->batchesByProductIds(
                $goodsReceipt->items->pluck('product_id')->unique()->filter()->all(),
            ),
        ]);
    }

    public function update(UpdateGoodsReceiptRequest $request, GoodsReceipt $goodsReceipt): RedirectResponse
    {
        $this->authorize('update', $goodsReceipt);

        $goodsReceipt = $this->goodsReceiptService->updateDraft(
            $goodsReceipt,
            $request->validated(),
            $request->user(),
        );

        return redirect()->route('inventory.goods-receipts.show', $goodsReceipt)
            ->with('status', 'Penerimaan barang berhasil diperbarui.');
    }

    public function submit(SubmitGoodsReceiptRequest $request, GoodsReceipt $goodsReceipt): RedirectResponse
    {
        $this->authorize('submit', $goodsReceipt);

        $goodsReceipt = $this->goodsReceiptService->submit($goodsReceipt, $request->user());

        return redirect()->route('inventory.goods-receipts.show', $goodsReceipt)
            ->with('status', 'Penerimaan barang berhasil diajukan.');
    }

    public function post(PostGoodsReceiptRequest $request, GoodsReceipt $goodsReceipt): RedirectResponse
    {
        $this->authorize('post', $goodsReceipt);

        $goodsReceipt = $this->goodsReceiptService->post($goodsReceipt, $request->user());

        return redirect()->route('inventory.goods-receipts.show', $goodsReceipt)
            ->with('status', 'Penerimaan barang berhasil diposting. Stok persediaan telah diperbarui melalui ledger.');
    }

    public function cancel(CancelGoodsReceiptRequest $request, GoodsReceipt $goodsReceipt): RedirectResponse
    {
        $this->authorize('cancel', $goodsReceipt);

        $this->goodsReceiptService->cancel($goodsReceipt, $request->user());

        return redirect()->route('inventory.goods-receipts.index')
            ->with('status', 'Penerimaan barang berhasil dibatalkan.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $prefillItems
     * @return array<int>
     */
    private function productIdsFromPrefill(array $prefillItems, mixed $purchaseOrder): array
    {
        $ids = collect($prefillItems)->pluck('product_id');

        if ($purchaseOrder !== null) {
            $ids = $ids->merge($purchaseOrder->items->pluck('product_id'));
        }

        return $ids->unique()->filter()->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * @param  array<int>  $productIds
     * @return array<int, array<int, array{id: int, batch_number: string, lot_number: string|null, expiry_date: string|null}>>
     */
    private function batchesByProductIds(array $productIds): array
    {
        $map = [];

        foreach ($productIds as $productId) {
            $map[(int) $productId] = $this->inventoryStock
                ->listActiveBatchesForProduct((int) $productId)
                ->map(fn ($batch) => [
                    'id' => (int) $batch->id,
                    'batch_number' => (string) $batch->batch_number,
                    'lot_number' => $batch->lot_number,
                    'expiry_date' => $batch->expiry_date?->format('Y-m-d'),
                ])
                ->values()
                ->all();
        }

        return $map;
    }
}
