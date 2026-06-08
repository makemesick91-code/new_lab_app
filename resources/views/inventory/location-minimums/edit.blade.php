<x-settings-shell title="Ubah Minimum Stok Ruangan">
    <div class="space-y-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Minimum Stok Ruangan</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">Ubah Minimum Stok</h2>
            <p class="mt-1 text-sm text-gray-500">Perbarui ambang batas minimum dan maksimum stok untuk konfigurasi ini di cabang aktif.</p>
        </div>

        <section class="max-w-3xl rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <form method="POST" action="{{ route('inventory.location-minimums.update', $minimum) }}" class="space-y-5">
                @csrf
                @method('PUT')
                @include('inventory.location-minimums._form', ['minimum' => $minimum])
                <div class="flex items-center justify-end gap-2 border-t border-gray-200 pt-4">
                    <a href="{{ route('inventory.location-minimums.index') }}" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">Batal</a>
                    <button class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">Simpan Perubahan</button>
                </div>
            </form>
        </section>
    </div>
</x-settings-shell>
