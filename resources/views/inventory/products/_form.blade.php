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
        <label class="block text-sm font-medium text-gray-700">Stok Minimum</label>
        <input type="number" step="0.01" min="0" name="minimum_stock" value="{{ old('minimum_stock', $product?->minimum_stock ?? 0) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
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
    <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-gray-700">Deskripsi</label>
        <textarea name="description" rows="3" class="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('description', $product?->description) }}</textarea>
    </div>
</div>
