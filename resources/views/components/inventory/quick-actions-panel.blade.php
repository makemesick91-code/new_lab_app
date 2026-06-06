<x-inventory.dashboard-section
    id="inventory-quick-actions"
    data-testid="inventory-quick-actions"
    title="Aksi Cepat Persediaan"
    description="Akses cepat ke alur kerja persediaan lanjutan tanpa membuka menu samping."
    density="compact"
>
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @can('viewAny', \App\Modules\Inventory\Models\InventoryMovement::class)
            <a
                href="{{ route('inventory.alerts.index') }}"
                class="rounded-lg border border-gray-200 p-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2"
            >
                Peringatan Stok
            </a>
            <a
                href="{{ route('inventory.analytics.index') }}"
                class="rounded-lg border border-teal-200 bg-teal-50 p-3 text-sm font-medium text-teal-800 hover:bg-teal-100 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2"
            >
                Analitik Persediaan
            </a>
        @endcan
        @can('create', \App\Modules\Inventory\Models\StockOpname::class)
            <a
                href="{{ route('inventory.stock-opnames.index') }}"
                class="rounded-lg border border-gray-200 p-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2"
            >
                Stok Opname
            </a>
        @endcan
        @can('create', \App\Modules\Inventory\Models\StockTransfer::class)
            <a
                href="{{ route('inventory.stock-transfers.index') }}"
                class="rounded-lg border border-gray-200 p-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2"
            >
                Transfer Stok
            </a>
        @endcan
        @can('create', \App\Modules\Inventory\Models\PurchaseRequest::class)
            <a
                href="{{ route('inventory.purchase-requests.create') }}"
                class="rounded-lg border border-gray-200 p-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2"
            >
                Buat Permintaan Pembelian
            </a>
        @endcan
        @can('create', \App\Modules\Inventory\Models\PurchaseOrder::class)
            <a
                href="{{ route('inventory.purchase-orders.create') }}"
                class="rounded-lg border border-gray-200 p-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2"
            >
                Buat Pesanan Pembelian
            </a>
        @endcan
    </div>
</x-inventory.dashboard-section>
