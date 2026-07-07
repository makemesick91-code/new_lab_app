<x-settings-shell title="Ubah Pesanan Pembelian">
    <div class="space-y-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">Ubah Pesanan Pembelian</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">{{ $purchaseOrder->purchase_order_number }}</h2>
            <p class="mt-1 text-sm text-gray-500">Hanya pesanan berstatus Draft yang dapat diubah.</p>
        </div>

        <form method="POST" action="{{ route('inventory.purchase-orders.update', $purchaseOrder) }}" class="max-w-5xl space-y-6">
            @csrf
            @method('PUT')
            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                @include('inventory.purchase-orders._form', ['purchaseOrder' => $purchaseOrder])
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <a href="{{ route('inventory.purchase-orders.show', $purchaseOrder) }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                    Batal
                </a>
                <button type="submit" class="inline-flex items-center rounded-lg bg-brand-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                    Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
</x-settings-shell>
