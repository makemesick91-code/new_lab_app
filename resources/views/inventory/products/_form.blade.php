@php($product = $product ?? null)

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label class="block text-sm font-medium text-gray-700">Kode</label>
        <input type="text" name="code" value="{{ old('code', $product?->code) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" required>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Nama</label>
        <input type="text" name="name" value="{{ old('name', $product?->name) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" required>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Kategori</label>
        <select name="product_category_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" required>
            <option value="">Pilih kategori</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected(old('product_category_id', $product?->product_category_id) == $category->id)>{{ $category->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Satuan</label>
        <select name="product_unit_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" required>
            <option value="">Pilih satuan</option>
            @foreach ($units as $unit)
                <option value="{{ $unit->id }}" @selected(old('product_unit_id', $product?->product_unit_id) == $unit->id)>{{ $unit->name }} ({{ $unit->symbol }})</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-gray-700">Biaya Rata-rata</label>
        <input type="number" step="0.01" min="0" name="average_cost" value="{{ old('average_cost', $product?->average_cost ?? 0) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
    </div>
    <div class="flex items-center pt-6">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $product?->is_active ?? true)) class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
        <label for="is_active" class="ml-2 text-sm text-gray-700">Aktif</label>
    </div>
    <div class="sm:col-span-2 rounded-lg border border-gray-200 bg-gray-50 p-4">
        <h3 class="text-sm font-semibold text-gray-900">Pengaturan Peringatan &amp; Pesanan Ulang</h3>
        <p class="mt-1 text-xs text-gray-500">Stok saat ini tetap dihitung dari ledger pergerakan. Nilai di bawah hanya mengatur kapan peringatan muncul.</p>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
                <label for="minimum_stock" class="block text-sm font-medium text-gray-700">Stok Minimum</label>
                <input id="minimum_stock" type="number" step="0.0001" min="0" name="minimum_stock" value="{{ old('minimum_stock', $product?->minimum_stock ?? 0) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                <p class="mt-1 text-xs text-gray-500">Digunakan sebagai titik pesan ulang jika titik pesan ulang kosong atau nol.</p>
            </div>
            <div>
                <label for="reorder_point" class="block text-sm font-medium text-gray-700">Titik Pesan Ulang</label>
                <input id="reorder_point" type="number" step="0.0001" min="0" name="reorder_point" value="{{ old('reorder_point', $product?->reorder_point ?? 0) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                <p class="mt-1 text-xs text-gray-500">Stok turun ke level ini → tindakan pesan ulang disarankan.</p>
            </div>
            <div>
                <label for="reorder_quantity" class="block text-sm font-medium text-gray-700">Jumlah Pesan Ulang</label>
                <input id="reorder_quantity" type="number" step="0.0001" min="0" name="reorder_quantity" value="{{ old('reorder_quantity', $product?->reorder_quantity ?? 0) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                <p class="mt-1 text-xs text-gray-500">Saran jumlah saat titik pesan ulang tercapai (informasi saja).</p>
            </div>
            <div class="flex items-center pt-6">
                <input type="hidden" name="alert_enabled" value="0">
                <input type="checkbox" id="alert_enabled" name="alert_enabled" value="1" @checked(old('alert_enabled', $product?->alert_enabled ?? true)) class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                <label for="alert_enabled" class="ml-2 text-sm text-gray-700">Aktifkan peringatan</label>
            </div>
        </div>
    </div>
    <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-gray-700">Deskripsi</label>
        <textarea name="description" rows="3" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description', $product?->description) }}</textarea>
    </div>
</div>
