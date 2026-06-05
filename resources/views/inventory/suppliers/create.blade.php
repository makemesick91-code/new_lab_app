<x-settings-shell title="Tambah Pemasok">
    <div class="bg-white shadow-sm sm:rounded-lg p-6">
        <form method="POST" action="{{ route('inventory.suppliers.store') }}" class="space-y-5">
            @csrf
            @include('inventory.suppliers._form')
            <div class="flex items-center justify-end gap-2">
                <a href="{{ route('inventory.suppliers.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Batal</a>
                <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Simpan Pemasok</button>
            </div>
        </form>
    </div>
</x-settings-shell>
