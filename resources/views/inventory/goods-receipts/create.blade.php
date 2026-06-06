<x-settings-shell title="Buat Penerimaan Barang">
    <div class="space-y-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Penerimaan Barang Baru</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">Buat Penerimaan Barang</h2>
            <p class="mt-1 text-sm text-gray-500">Buat draft penerimaan dari pesanan pembelian. Stok bertambah setelah posting.</p>
        </div>

        <div class="rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-800">
            <p class="font-semibold">Stok berbasis ledger</p>
            <p class="mt-1">Draft penerimaan tidak menambah stok. Stok bertambah saat dokumen diposting melalui pergerakan PURCHASE.</p>
        </div>

        <form method="POST" action="{{ route('inventory.goods-receipts.store') }}" class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            @include('inventory.goods-receipts._form', [
                'purchaseOrder' => $purchaseOrder,
                'prefillItems' => $prefillItems,
                'receivablePurchaseOrders' => $receivablePurchaseOrders,
            ])

            <div class="mt-6 flex flex-wrap items-center gap-3 border-t border-gray-200 pt-4">
                <a href="{{ route('inventory.goods-receipts.index') }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Kembali
                </a>
                <button type="submit" @disabled($purchaseOrder === null) class="inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50">
                    Simpan Draft
                </button>
            </div>
        </form>
    </div>
</x-settings-shell>
