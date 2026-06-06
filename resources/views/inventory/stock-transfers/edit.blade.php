<x-settings-shell title="Ubah Transfer Stok {{ $stockTransfer->transfer_number }}">
    <div class="space-y-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Ubah Transfer Stok</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">{{ $stockTransfer->transfer_number }}</h2>
            <p class="mt-1 text-sm text-gray-500">Perbarui detail transfer selama status masih draft.</p>
        </div>

        <form method="POST" action="{{ route('inventory.stock-transfers.update', $stockTransfer) }}" class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            @csrf
            @method('PUT')
            @include('inventory.stock-transfers._form', ['stockTransfer' => $stockTransfer])

            <div class="mt-6 flex flex-wrap items-center gap-3 border-t border-gray-200 pt-4">
                <a href="{{ route('inventory.stock-transfers.show', $stockTransfer) }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Batal
                </a>
                <button type="submit" class="inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
</x-settings-shell>
