<x-settings-shell title="Tambah Pemasok">
    <div class="space-y-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Pemasok Persediaan</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">Tambah Pemasok Baru</h2>
        </div>

        <x-ui.card>
            <form method="POST" action="{{ route('inventory.suppliers.store') }}" class="space-y-5">
                @csrf
                @include('inventory.suppliers._form')
                <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-5">
                    <x-ui.button variant="secondary" :href="route('inventory.suppliers.index')">Batal</x-ui.button>
                    <x-ui.button type="submit" variant="primary">Simpan Pemasok</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-settings-shell>
