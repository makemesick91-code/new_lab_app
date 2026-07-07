<x-settings-shell title="Edit Penerimaan Barang {{ $goodsReceipt->receipt_number }}">
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">Ubah Penerimaan Barang</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">Edit Penerimaan Barang</h2>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $goodsReceipt->receipt_number }}
                    · @include('inventory.goods-receipts._status-badge', ['status' => $goodsReceipt->status])
                </p>
            </div>
            <a href="{{ route('inventory.goods-receipts.show', $goodsReceipt) }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                Kembali
            </a>
        </div>

        <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
            <p class="font-semibold">Status: Draft</p>
            <p class="mt-1">Hanya draft yang dapat diubah. Setelah diajukan atau diposting, dokumen tidak dapat diedit.</p>
        </div>

        <form method="POST" action="{{ route('inventory.goods-receipts.update', $goodsReceipt) }}" class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            @method('PUT')
            @include('inventory.goods-receipts._form', [
                'goodsReceipt' => $goodsReceipt,
                'purchaseOrder' => $goodsReceipt->purchaseOrder,
                'receivablePurchaseOrders' => collect(),
                'batchesByProduct' => $batchesByProduct ?? [],
            ])

            <div class="mt-6 flex flex-wrap items-center gap-3 border-t border-gray-200 pt-4">
                <a href="{{ route('inventory.goods-receipts.show', $goodsReceipt) }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                    Kembali
                </a>
                <button type="submit" class="inline-flex items-center rounded-lg bg-brand-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                    Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
</x-settings-shell>
