<x-settings-shell title="Buat Permintaan Pembelian">
    <div class="space-y-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Permintaan Pembelian Baru</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">Buat Permintaan Pembelian</h2>
            <p class="mt-1 text-sm text-gray-500">Ajukan kebutuhan pembelian material. Permintaan disimpan sebagai draft hingga diajukan.</p>
        </div>

        <div class="rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-800">
            <p class="font-semibold">Tidak mengubah stok</p>
            <p class="mt-1">Permintaan pembelian hanya mencatat kebutuhan. Stok ledger tidak berubah sampai penerimaan barang diimplementasikan.</p>
        </div>

        <form method="POST" action="{{ route('inventory.purchase-requests.store') }}" class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            @include('inventory.purchase-requests._form', ['prefillItem' => $prefillItem ?? null])

            <div class="mt-6 flex flex-wrap items-center gap-3 border-t border-gray-200 pt-4">
                <a href="{{ route('inventory.purchase-requests.index') }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Batal
                </a>
                <button type="submit" class="inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Simpan Draft
                </button>
            </div>
        </form>
    </div>
</x-settings-shell>
