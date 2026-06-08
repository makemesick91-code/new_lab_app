@php($minimum = $minimum ?? null)

<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label for="minimum-location" class="block text-sm font-medium text-gray-700">Ruangan / Lokasi <span class="text-rose-600">*</span></label>
        <select id="minimum-location" name="inventory_location_id" required
                class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
            <option value="">Pilih ruangan / lokasi</option>
            @foreach ($locations as $location)
                <option value="{{ $location->id }}" @selected((int) old('inventory_location_id', $minimum?->inventory_location_id) === (int) $location->id)>
                    {{ $location->name }}{{ $location->code ? ' ('.$location->code.')' : '' }}{{ $location->type ? ' - '.str_replace('_', ' ', $location->type) : '' }}
                </option>
            @endforeach
        </select>
        @error('inventory_location_id')
            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="minimum-product" class="block text-sm font-medium text-gray-700">Produk <span class="text-rose-600">*</span></label>
        <select id="minimum-product" name="product_id" required
                class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
            <option value="">Pilih produk</option>
            @foreach ($products as $product)
                <option value="{{ $product->id }}" @selected((int) old('product_id', $minimum?->product_id) === (int) $product->id)>
                    {{ $product->name }}{{ $product->code ? ' ('.$product->code.')' : '' }}
                </option>
            @endforeach
        </select>
        @error('product_id')
            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="minimum-stock" class="block text-sm font-medium text-gray-700">Minimum Stok <span class="text-rose-600">*</span></label>
        <input id="minimum-stock" type="number" step="0.0001" min="0.0001" name="minimum_stock"
               value="{{ old('minimum_stock', $minimum?->minimum_stock) }}" required
               class="mt-1 block w-full rounded-lg border-gray-300 text-sm tabular-nums focus:border-teal-500 focus:ring-teal-500">
        <p class="mt-1 text-xs text-gray-500">Ambang batas minimum stok yang harus dijaga di ruangan ini. Harus lebih dari 0.</p>
        @error('minimum_stock')
            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="maximum-stock" class="block text-sm font-medium text-gray-700">Maksimum Stok</label>
        <input id="maximum-stock" type="number" step="0.0001" min="0" name="maximum_stock"
               value="{{ old('maximum_stock', $minimum?->maximum_stock) }}"
               class="mt-1 block w-full rounded-lg border-gray-300 text-sm tabular-nums focus:border-teal-500 focus:ring-teal-500">
        <p class="mt-1 text-xs text-gray-500">Opsional. Jika diisi, tidak boleh kurang dari minimum stok.</p>
        @error('maximum_stock')
            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
        @enderror
    </div>

    <div class="flex items-center sm:col-span-2">
        <input type="hidden" name="is_active" value="0">
        <input id="minimum-is-active" type="checkbox" name="is_active" value="1" @checked(old('is_active', $minimum?->is_active ?? true))
               class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
        <label for="minimum-is-active" class="ml-2 text-sm text-gray-700">Aktif</label>
        @error('is_active')
            <p class="ml-3 text-xs text-rose-600">{{ $message }}</p>
        @enderror
    </div>
</div>
