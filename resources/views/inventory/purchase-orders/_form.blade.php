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

    <section class="rounded-lg border border-gray-200 bg-gray-50/50">
        <div class="border-b border-gray-200 px-4 py-3">
            <h3 class="text-base font-semibold text-gray-900">Informasi Pesanan</h3>
            <p class="mt-1 text-sm text-gray-500">Data pemasok, tanggal, dan catatan pesanan pembelian.</p>
        </div>
        <div class="grid gap-6 p-4 md:grid-cols-2">
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
    </section>

    <section class="rounded-lg border border-gray-200">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
            <div>
                <h3 class="text-base font-semibold text-gray-900">Item Pesanan</h3>
                <p class="mt-1 text-sm text-gray-500">Tambahkan produk, jumlah, dan harga satuan untuk setiap baris pesanan.</p>
            </div>
            <button type="button" @click="items.push({ product_id: '', inventory_location_id: '', purchase_request_item_id: '', quantity_ordered: 1, unit_price: '', notes: '' })"
                    class="inline-flex items-center rounded-lg border border-teal-200 bg-teal-50 px-3 py-1.5 text-sm font-medium text-teal-700 hover:bg-teal-100 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                Tambah Item
            </button>
        </div>

        <div class="divide-y divide-gray-100">
            <template x-for="(item, index) in items" :key="index">
                <div class="p-4">
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                            Item <span x-text="index + 1"></span>
                        </p>
                        <button type="button" @click="items.length > 1 ? items.splice(index, 1) : null" x-show="items.length > 1"
                                class="inline-flex items-center rounded-lg border border-rose-200 px-2.5 py-1 text-xs font-medium text-rose-700 hover:bg-rose-50 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2">
                            Hapus Item
                        </button>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                        <div class="lg:col-span-2">
                            <label :for="'product-' + index" class="block text-sm font-medium text-gray-700">Produk <span class="text-red-600">*</span></label>
                            <x-inventory.searchable-product-select
                                :products="$products"
                                alpine-name="'items[' + index + '][product_id]'"
                                model="item.product_id"
                                class="mt-1"
                                required
                            />
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
                            <input :id="'quantity-' + index" type="number" step="0.0001" min="0.0001" :name="'items[' + index + '][quantity_ordered]'" x-model.number="item.quantity_ordered" required
                                   class="mt-1 block w-full rounded-lg border-gray-300 text-sm tabular-nums focus:border-teal-500 focus:ring-teal-500">
                        </div>
                        <div>
                            <label :for="'price-' + index" class="block text-sm font-medium text-gray-700">Harga Satuan</label>
                            <input :id="'price-' + index" type="number" step="0.01" min="0" :name="'items[' + index + '][unit_price]'" x-model="item.unit_price"
                                   class="mt-1 block w-full rounded-lg border-gray-300 text-sm tabular-nums focus:border-teal-500 focus:ring-teal-500">
                        </div>
                        <div class="md:col-span-2 lg:col-span-1">
                            <label :for="'item-notes-' + index" class="block text-sm font-medium text-gray-700">Catatan Item</label>
                            <input :id="'item-notes-' + index" type="text" :name="'items[' + index + '][notes]'" x-model="item.notes"
                                   class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        </div>
                    </div>
                    <template x-if="item.purchase_request_item_id">
                        <input type="hidden" :name="'items[' + index + '][purchase_request_item_id]'" :value="item.purchase_request_item_id">
                    </template>
                </div>
            </template>
        </div>
    </section>

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
