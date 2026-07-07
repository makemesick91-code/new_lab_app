<x-settings-shell title="Buat Pesanan Pembelian">
    <div class="space-y-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">Pesanan Pembelian Baru</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">Buat Pesanan Pembelian</h2>
            <p class="mt-1 text-sm text-gray-500">Buat pesanan ke supplier. Pesanan disimpan sebagai draft hingga diajukan.</p>
        </div>

        @if ($purchaseRequest ?? null)
            <div class="rounded-lg border border-brand-200 bg-brand-50 p-4 text-sm text-brand-800">
                <p class="font-semibold">Dibuat dari Permintaan Pembelian</p>
                <p class="mt-1">
                    PR terkait:
                    <a href="{{ route('inventory.purchase-requests.show', $purchaseRequest) }}" class="font-medium underline hover:text-brand-700">
                        {{ $purchaseRequest->purchase_request_number }}
                    </a>
                    · {{ format_date_id($purchaseRequest->request_date) }}
                </p>
                <p class="mt-1 text-brand-800">Item di bawah telah diisi dari permintaan pembelian yang disetujui.</p>
            </div>
        @endif

        <div class="rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-800">
            <p class="font-semibold">Tidak menambah stok</p>
            <p class="mt-1">Pesanan pembelian tidak menambah stok. Stok bertambah hanya melalui penerimaan barang.</p>
        </div>

        <form method="POST" action="{{ route('inventory.purchase-orders.store') }}" class="max-w-5xl space-y-6">
            @csrf
            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                @include('inventory.purchase-orders._form', [
                    'purchaseRequest' => $purchaseRequest ?? null,
                    'prefillItems' => $prefillItems ?? [],
                ])
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <a href="{{ route('inventory.purchase-orders.index') }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                    Batal
                </a>
                <button type="submit" class="inline-flex items-center rounded-lg bg-brand-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                    Simpan Draft
                </button>
            </div>
        </form>
    </div>
</x-settings-shell>
