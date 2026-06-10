<x-settings-shell title="Ubah Pemasok">
    <div class="space-y-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Pemasok Persediaan</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">Ubah Pemasok</h2>
            <p class="mt-1 text-sm text-gray-500">{{ $supplier->name }}</p>
        </div>

        <x-ui.card>
            <form method="POST" action="{{ route('inventory.suppliers.update', $supplier) }}" class="space-y-5">
                @csrf
                @method('PUT')
                @include('inventory.suppliers._form', ['supplier' => $supplier])
                <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-5">
                    <x-ui.button variant="secondary" :href="route('inventory.suppliers.show', $supplier)">Batal</x-ui.button>
                    <x-ui.button type="submit" variant="primary">Perbarui Pemasok</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-settings-shell>
