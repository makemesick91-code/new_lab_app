<x-settings-shell title="Buat Transfer Stok">
    <div class="space-y-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Transfer Stok Baru</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">Buat Transfer Stok</h2>
            <p class="mt-1 text-sm text-gray-500">Rencanakan pemindahan stok antar lokasi persediaan. Transfer disimpan sebagai draft hingga diajukan.</p>
        </div>

        <div class="rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-800">
            <p class="font-semibold">Stok berbasis ledger</p>
            <p class="mt-1">Pergerakan stok baru dibuat saat transfer diselesaikan. Draft dan pengajuan tidak mengubah stok terhitung.</p>
        </div>

        <form method="POST" action="{{ route('inventory.stock-transfers.store') }}" class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            @include('inventory.stock-transfers._form')

            <div class="mt-6 flex flex-wrap items-center gap-3 border-t border-gray-200 pt-4">
                <a href="{{ route('inventory.stock-transfers.index') }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Batal
                </a>
                <button type="submit" class="inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Simpan Draft Transfer
                </button>
            </div>
        </form>
    </div>
</x-settings-shell>
