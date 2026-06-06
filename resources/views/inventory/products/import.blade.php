<x-settings-shell title="Impor Produk CSV">
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Produk Persediaan</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">Impor Produk dari CSV</h2>
                <p class="mt-1 text-sm text-gray-500">Unggah file CSV sesuai template sistem. Semua baris divalidasi sebelum produk disimpan.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('inventory.products.import.template') }}" class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Unduh Template CSV
                </a>
                <a href="{{ route('inventory.products.index') }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Kembali
                </a>
            </div>
        </div>

        <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h3 class="text-base font-semibold text-gray-900">Format Template</h3>
            <p class="mt-1 text-sm text-gray-500">Header wajib: <span class="font-mono text-xs text-gray-700">sku,name,category,unit,supplier,minimum_stock,reorder_point,reorder_quantity,is_active</span></p>
            <p class="mt-2 text-sm text-gray-500">Kategori dan satuan harus sudah ada di master data. Supplier bersifat opsional dan hanya divalidasi jika diisi (tidak disimpan pada produk).</p>

            <form method="POST" action="{{ route('inventory.products.import.store') }}" enctype="multipart/form-data" class="mt-6 space-y-4">
                @csrf
                <div>
                    <label for="csv_file" class="block text-sm font-medium text-gray-700">File CSV</label>
                    <input id="csv_file" type="file" name="csv_file" accept=".csv,.txt,text/csv,text/plain" required
                           class="mt-1 block w-full rounded-lg border border-gray-300 text-sm text-gray-700 file:mr-4 file:rounded-md file:border-0 file:bg-teal-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-teal-700 hover:file:bg-teal-100 focus:border-teal-500 focus:ring-teal-500">
                    @error('csv_file')
                        <p class="mt-1 text-sm text-rose-700">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center justify-end gap-2">
                    <a href="{{ route('inventory.products.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Batal</a>
                    <button type="submit" class="inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                        Impor Produk
                    </button>
                </div>
            </form>
        </section>

        @if (session('import_errors'))
            <section class="rounded-lg border border-rose-200 bg-rose-50 p-4 shadow-sm">
                <h3 class="text-base font-semibold text-rose-800">Validasi Gagal</h3>
                <p class="mt-1 text-sm text-rose-700">Tidak ada produk yang disimpan. Perbaiki error berikut lalu unggah ulang file CSV.</p>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-rose-200 text-sm">
                        <thead>
                            <tr class="text-left text-rose-800">
                                <th scope="col" class="px-3 py-2 font-medium">Baris</th>
                                <th scope="col" class="px-3 py-2 font-medium">Field</th>
                                <th scope="col" class="px-3 py-2 font-medium">Pesan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-rose-100">
                            @foreach (session('import_errors') as $error)
                                <tr>
                                    <td class="px-3 py-2 tabular-nums text-rose-900">{{ $error['row'] }}</td>
                                    <td class="px-3 py-2 font-mono text-xs text-rose-900">{{ $error['field'] }}</td>
                                    <td class="px-3 py-2 text-rose-800">{{ $error['message'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
</x-settings-shell>
