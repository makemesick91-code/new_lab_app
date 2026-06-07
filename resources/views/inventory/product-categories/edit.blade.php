<x-settings-shell title="Ubah Kategori Produk">
    <div class="space-y-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Kategori Produk</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">Ubah Kategori Produk</h2>
            <p class="mt-1 text-sm text-gray-500">Perubahan kategori berlaku untuk produk dalam cabang aktif yang menggunakan kategori ini.</p>
        </div>

        <section class="max-w-3xl rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <form method="POST" action="{{ route('inventory.product-categories.update', $category) }}" class="space-y-5">
                @csrf
                @method('PUT')
                @include('inventory.product-categories._form', ['category' => $category])
                <div class="flex items-center justify-end gap-2 border-t border-gray-200 pt-4">
                    <a href="{{ route('inventory.product-categories.index') }}" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">Batal</a>
                    <button class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">Perbarui Kategori</button>
                </div>
            </form>
        </section>
    </div>
</x-settings-shell>
