<x-settings-shell title="Buat Penerimaan Barang">
    <div class="space-y-6">
        <x-ui.page-header title="Buat Penerimaan Barang" subtitle="Buat draft penerimaan dari pesanan pembelian. Stok bertambah setelah posting.">
            <x-slot:breadcrumb>Persediaan / Penerimaan Barang</x-slot:breadcrumb>
        </x-ui.page-header>

        <x-ui.alert variant="info" title="Stok berbasis ledger">
            Draft penerimaan tidak menambah stok. Stok bertambah saat dokumen diposting melalui pergerakan PURCHASE.
        </x-ui.alert>

        <x-ui.card>
            <form method="POST" action="{{ route('inventory.goods-receipts.store') }}">
                @csrf
                @include('inventory.goods-receipts._form', [
                    'purchaseOrder' => $purchaseOrder,
                    'prefillItems' => $prefillItems,
                    'receivablePurchaseOrders' => $receivablePurchaseOrders,
                    'batchesByProduct' => $batchesByProduct ?? [],
                ])

                <div class="mt-6 flex flex-wrap items-center gap-3 border-t border-hairline pt-4">
                    <x-ui.button variant="secondary" :href="route('inventory.goods-receipts.index')">Kembali</x-ui.button>
                    <x-ui.button type="submit" :disabled="$purchaseOrder === null">Simpan Draft</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-settings-shell>
