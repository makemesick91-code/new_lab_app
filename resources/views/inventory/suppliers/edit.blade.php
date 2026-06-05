<x-settings-shell title="Ubah Pemasok">
    <div class="bg-white shadow-sm sm:rounded-lg p-6">
        <form method="POST" action="{{ route('inventory.suppliers.update', $supplier) }}" class="space-y-5">
            @csrf
            @method('PUT')
            @include('inventory.suppliers._form', ['supplier' => $supplier])
            <div class="flex items-center justify-end gap-2">
                <a href="{{ route('inventory.suppliers.show', $supplier) }}" class="text-sm text-gray-500 hover:text-gray-700">Batal</a>
                <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Perbarui Pemasok</button>
            </div>
        </form>
    </div>
</x-settings-shell>
