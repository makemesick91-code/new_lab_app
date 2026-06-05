<x-settings-shell title="Tambah Lokasi Persediaan">
    <div class="bg-white shadow-sm sm:rounded-lg p-6">
        <form method="POST" action="{{ route('inventory.locations.store') }}" class="space-y-5">
            @csrf
            @include('inventory.locations._form')
            <div class="flex items-center justify-end gap-2">
                <a href="{{ route('inventory.locations.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Batal</a>
                <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Simpan Lokasi</button>
            </div>
        </form>
    </div>
</x-settings-shell>
