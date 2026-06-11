<x-settings-shell title="Tambah Lokasi Persediaan">
    <div class="space-y-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Lokasi Persediaan</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">Tambah Lokasi Baru</h2>
        </div>

        <x-ui.card>
            <form method="POST" action="{{ route('inventory.locations.store') }}" class="space-y-5">
                @csrf
                @include('inventory.locations._form')
                <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-5">
                    <x-ui.button variant="secondary" :href="route('inventory.locations.index')">Batal</x-ui.button>
                    <x-ui.button type="submit" variant="primary">Simpan Lokasi</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-settings-shell>
