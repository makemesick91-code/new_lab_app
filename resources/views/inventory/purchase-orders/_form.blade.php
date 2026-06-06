@php
    $purchaseOrder = $purchaseOrder ?? null;
    $purchaseRequest = $purchaseRequest ?? null;
    $prefillItems = $prefillItems ?? [];
    $initialItems = old('items', $purchaseOrder
        ? $purchaseOrder->items->map(fn ($item) => [
            'product_id' => $item->product_id,
            'inventory_location_id' => $item->inventory_location_id,
            'purchase_request_item_id' => $item->purchase_request_item_id,
            'quantity_ordered' => (float) $item->quantity_ordered,
            'unit_price' => $item->unit_price !== null ? (float) $item->unit_price : '',
            'notes' => $item->notes,
        ])->values()->all()
        : ($prefillItems !== [] ? $prefillItems : [['product_id' => '', 'inventory_location_id' => '', 'purchase_request_item_id' => '', 'quantity_ordered' => 1, 'unit_price' => '', 'notes' => '']]));
@endphp

<div x-data="{ items: @js($initialItems) }" class="space-y-6">
    @if ($purchaseRequest)
        <input type="hidden" name="purchase_request_id" value="{{ $purchaseRequest->id }}">
    @endif

    <div class="grid gap-6 md:grid-cols-2">
        <div>
            <label for="supplier-id" class="block text-sm font-medium text-gray-700">Pemasok</label>
            <select id="supplier-id" name="supplier_id"
                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                <option value="">Pilih pemasok</option>
                @foreach ($suppliers as $supplier)
                    <option value="{{ $supplier->id }}" @selected((string) old('supplier_id', $purchaseOrder?->supplier_id) === (string) $supplier->id)>{{ $supplier->name }}</option>
                @endforeach
            </select>
            @error('supplier_id')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="supplier-reference" class="block text-sm font-medium text-gray-700">Nomor Referensi Supplier</label>
            <input id="supplier-reference" type="text" name="supplier_reference_number"
                   value="{{ old('supplier_reference_number', $purchaseOrder?->supplier_reference_number) }}"
                   class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
            @error('supplier_reference_number')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="order-date" class="block text-sm font-medium text-gray-700">Tanggal Pesanan <span class="text-red-600">*</span></label>
            <input id="order-date" type="date" name="order_date" required
                   value="{{ old('order_date', optional($purchaseOrder?->order_date)->format('Y-m-d') ?? now()->toDateString()) }}"
                   class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
            @error('order_date')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="expected-delivery-date" class="block text-sm font-medium text-gray-700">Perkiraan Tanggal Kirim</label>
            <input id="expected-delivery-date" type="date" name="expected_delivery_date"
                   value="{{ old('expected_delivery_date', optional($purchaseOrder?->expected_delivery_date)->format('Y-m-d')) }}"
                   class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
            @error('expected_delivery_date')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="currency" class="block text-sm font-medium text-gray-700">Mata Uang</label>
            <input id="currency" type="text" name="currency" readonly
                   value="{{ old('currency', $purchaseOrder?->currency ?? 'IDR') }}"
                   class="mt-1 block w-full rounded-lg border-gray-300 bg-gray-50 text-sm focus:border-teal-500 focus:ring-teal-500">
            @error('currency')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="md:col-span-2">
            <label for="order-notes" class="block text-sm font-medium text-gray-700">Catatan</label>
            <textarea id="order-notes" name="notes" rows="3" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">{{ old('notes', $purchaseOrder?->notes) }}</textarea>
            @error('notes')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="rounded-lg border border-gray-200">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
            <h3 class="text-base font-semibold text-gray-900">Item Pesanan</h3>
            <button type="button" @click="items.push({ product_id: '', inventory_location_id: '', purchase_request_item_id: '', quantity_ordered: 1, unit_price: '', notes: '' })"
                    class="inline-flex items-center rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                Tambah Item
            </button>
        </div>

        <div class="divide-y divide-gray-100">
            <template x-for="(item, index) in items" :key="index">
                <div class="grid gap-4 p-4 md:grid-cols-2 lg:grid-cols-6">
                    <div>
                        <label :for="'product-' + index" class="block text-sm font-medium text-gray-700">Produk <span class="text-red-600">*</span></label>
                        <select :id="'product-' + index" :name="'items[' + index + '][product_id]'" x-model="item.product_id" required
                                class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="">Pilih produk</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}">{{ $product->name }} ({{ $product->code }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label :for="'location-' + index" class="block text-sm font-medium text-gray-700">Lokasi Persediaan</label>
                        <select :id="'location-' + index" :name="'items[' + index + '][inventory_location_id]'" x-model="item.inventory_location_id"
                                class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="">Tanpa lokasi spesifik</option>
                            @foreach ($locations as $location)
                                <option value="{{ $location->id }}">{{ $location->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label :for="'quantity-' + index" class="block text-sm font-medium text-gray-700">Jumlah Dipesan <span class="text-red-600">*</span></label>
                        <input :id="'quantity-' + index" type="number" step="0.01" min="0.01" :name="'items[' + index + '][quantity_ordered]'" x-model.number="item.quantity_ordered" required
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                    </div>
                    <div>
                        <label :for="'price-' + index" class="block text-sm font-medium text-gray-700">Harga Satuan</label>
                        <input :id="'price-' + index" type="number" step="0.01" min="0" :name="'items[' + index + '][unit_price]'" x-model="item.unit_price"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                    </div>
                    <div class="flex items-end gap-2 lg:col-span-2">
                        <div class="flex-1">
                            <label :for="'item-notes-' + index" class="block text-sm font-medium text-gray-700">Catatan Item</label>
                            <input :id="'item-notes-' + index" type="text" :name="'items[' + index + '][notes]'" x-model="item.notes"
                                   class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        </div>
                        <button type="button" @click="items.length > 1 ? items.splice(index, 1) : null" x-show="items.length > 1"
                                class="mb-0.5 rounded-lg border border-red-200 px-2 py-2 text-sm font-medium text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                            Hapus
                        </button>
                    </div>
                    <template x-if="item.purchase_request_item_id">
                        <input type="hidden" :name="'items[' + index + '][purchase_request_item_id]'" :value="item.purchase_request_item_id">
                    </template>
                </div>
            </template>
        </div>
    </div>

    @error('items')
        <p class="text-sm text-red-600">{{ $message }}</p>
    @enderror
    @error('items.*')
        <p class="text-sm text-red-600">{{ $message }}</p>
    @enderror
    @error('purchase_request_id')
        <p class="text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>
