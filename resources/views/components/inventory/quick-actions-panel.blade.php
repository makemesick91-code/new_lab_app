@props([
    'reportQuery' => [],
])

<x-inventory.dashboard-section
    id="inventory-quick-actions"
    data-testid="inventory-quick-actions"
    title="Aksi Cepat Harian Gudang"
    description="Langkah operasional harian Admin Warehouse ke alur yang sudah ada di sistem."
    density="compact"
>
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        @can('create', \App\Modules\Inventory\Models\PurchaseRequest::class)
            <a
                href="{{ route('inventory.purchase-requests.create') }}"
                class="rounded-lg border border-gray-200 p-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2"
                data-quick-action="purchase-request"
            >
                Buat Permintaan Pembelian
            </a>
        @endcan
        @can('create', \App\Modules\Inventory\Models\PurchaseOrder::class)
            <a
                href="{{ route('inventory.purchase-orders.create') }}"
                class="rounded-lg border border-gray-200 p-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2"
                data-quick-action="purchase-order"
            >
                Buat Pesanan Pembelian
            </a>
        @endcan
        @can('create', \App\Modules\Inventory\Models\GoodsReceipt::class)
            <a
                href="{{ route('inventory.goods-receipts.create') }}"
                class="rounded-lg border border-gray-200 p-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2"
                data-quick-action="goods-receipt"
            >
                Terima Barang
            </a>
        @endcan
        @can('create', \App\Modules\Inventory\Models\StockTransfer::class)
            <a
                href="{{ route('inventory.stock-transfers.create') }}"
                class="rounded-lg border border-gray-200 p-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2"
                data-quick-action="stock-transfer"
            >
                Transfer Stok
            </a>
        @endcan
        @can('create', \App\Modules\Inventory\Models\StockOpname::class)
            <a
                href="{{ route('inventory.stock-opnames.create') }}"
                class="rounded-lg border border-gray-200 p-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2"
                data-quick-action="stock-opname"
            >
                Mulai Stok Opname
            </a>
        @endcan
        @can('viewAny', \App\Modules\Inventory\Models\InventoryMovement::class)
            <a
                href="{{ route('inventory.reports.index', $reportQuery) }}"
                class="rounded-lg border border-teal-200 bg-teal-50 p-3 text-sm font-medium text-teal-800 hover:bg-teal-100 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2"
                data-quick-action="inventory-reports"
            >
                Buka Laporan Inventory
            </a>
            <a
                href="{{ route('inventory.alerts.index') }}"
                class="rounded-lg border border-gray-200 p-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2"
                data-quick-action="inventory-alerts"
            >
                Peringatan Stok
            </a>
            <a
                href="{{ route('inventory.analytics.index') }}"
                class="rounded-lg border border-gray-200 p-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2"
                data-quick-action="inventory-analytics"
            >
                Analitik Persediaan
            </a>
        @endcan
    </div>
</x-inventory.dashboard-section>
