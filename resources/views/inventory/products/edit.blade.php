<x-settings-shell title="Ubah Produk">
    <div class="bg-white shadow-sm sm:rounded-lg p-6">
        <form method="POST" action="{{ route('inventory.products.update', $product) }}" class="space-y-5">
            @csrf
            @method('PUT')
            @include('inventory.products._form', ['product' => $product])
            <div class="flex items-center justify-end gap-2">
                <a href="{{ route('inventory.products.show', $product) }}" class="text-sm text-gray-500 hover:text-gray-700">Batal</a>
                <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Perbarui Produk</button>
            </div>
        </form>
    </div>
</x-settings-shell>
