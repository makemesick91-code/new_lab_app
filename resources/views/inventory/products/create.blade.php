<x-settings-shell title="Tambah Produk">
    <div class="space-y-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Produk Persediaan</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">Tambah Produk Baru</h2>
            <p class="mt-1 text-sm text-gray-500">Lengkapi data produk sebelum menerima stok atau mencatat stok awal.</p>
        </div>

        <x-ui.card>
            <form method="POST" action="{{ route('inventory.products.store') }}" class="space-y-5">
                @csrf
                @include('inventory.products._form')
                <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-5">
                    <x-ui.button variant="secondary" :href="route('inventory.products.index')">Batal</x-ui.button>
                    <x-ui.button type="submit" variant="primary">Simpan Produk</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-settings-shell>
