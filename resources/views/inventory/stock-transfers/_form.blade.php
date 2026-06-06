@php
    $stockTransfer = $stockTransfer ?? null;
    $initialItems = old('items', $stockTransfer
        ? $stockTransfer->items->map(fn ($item) => [
            'product_id' => $item->product_id,
            'quantity' => (float) $item->quantity,
            'notes' => $item->notes,
        ])->values()->all()
        : [['product_id' => '', 'quantity' => 1, 'notes' => '']]);
@endphp

<div class="space-y-6">
    <div class="grid gap-6 md:grid-cols-2">
        <div>
            <label for="source-location" class="block text-sm font-medium text-gray-700">Lokasi Sumber <span class="text-red-600">*</span></label>
            <select id="source-location" name="source_inventory_location_id" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                <option value="">Pilih lokasi sumber</option>
                @foreach ($locations as $location)
                    <option value="{{ $location->id }}" @selected((int) old('source_inventory_location_id', $stockTransfer?->source_inventory_location_id) === $location->id)>{{ $location->name }}</option>
                @endforeach
            </select>
            @error('source_inventory_location_id')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="destination-location" class="block text-sm font-medium text-gray-700">Lokasi Tujuan <span class="text-red-600">*</span></label>
            <select id="destination-location" name="destination_inventory_location_id" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                <option value="">Pilih lokasi tujuan</option>
                @foreach ($locations as $location)
                    <option value="{{ $location->id }}" @selected((int) old('destination_inventory_location_id', $stockTransfer?->destination_inventory_location_id) === $location->id)>{{ $location->name }}</option>
                @endforeach
            </select>
            @error('destination_inventory_location_id')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="transfer-date" class="block text-sm font-medium text-gray-700">Tanggal Transfer</label>
            <input id="transfer-date" type="date" name="transfer_date" value="{{ old('transfer_date', optional($stockTransfer?->transfer_date)->format('Y-m-d') ?? now()->toDateString()) }}"
                   class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
            @error('transfer_date')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="md:col-span-2">
            <label for="transfer-notes" class="block text-sm font-medium text-gray-700">Catatan</label>
            <textarea id="transfer-notes" name="notes" rows="3" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">{{ old('notes', $stockTransfer?->notes) }}</textarea>
            @error('notes')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div x-data="{ items: @js($initialItems) }" class="rounded-lg border border-gray-200 bg-gray-50 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-base font-semibold text-gray-900">Item Transfer</h3>
                <p class="mt-1 text-sm text-gray-500">Tambahkan produk dan jumlah yang akan dipindahkan antar lokasi.</p>
            </div>
            <button type="button" @click="items.push({ product_id: '', quantity: 1, notes: '' })"
                    class="inline-flex items-center rounded-lg bg-teal-700 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                + Tambah Item
            </button>
        </div>

        @error('items')
            <p class="mt-3 text-sm text-red-600">{{ $message }}</p>
        @enderror

        <template x-for="(item, index) in items" :key="index">
            <div class="mt-4 grid gap-4 rounded-lg border border-gray-200 bg-white p-4 md:grid-cols-[minmax(0,1fr)_8rem_minmax(0,1fr)_auto] md:items-end">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Produk <span class="text-red-600">*</span></label>
                    <select :name="`items[${index}][product_id]`" x-model="item.product_id" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">Pilih produk</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}">{{ $product->code }} - {{ $product->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Jumlah <span class="text-red-600">*</span></label>
                    <input type="number" step="0.01" min="0.01" :name="`items[${index}][quantity]`" x-model="item.quantity" required
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm tabular-nums focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Catatan Item</label>
                    <input type="text" :name="`items[${index}][notes]`" x-model="item.notes" placeholder="Opsional"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div class="flex items-end">
                    <button type="button" x-show="items.length > 1" @click="items.splice(index, 1)"
                            class="rounded-lg px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50 hover:text-red-700">
                        Hapus
                    </button>
                </div>
            </div>
        </template>
    </div>
</div>
