@php
    $purchaseOrder = $purchaseOrder ?? null;
    $purchaseRequest = $purchaseRequest ?? null;
    $prefillItems = $prefillItems ?? [];
    $initialItems = old('items', $purchaseOrder
        ? $purchaseOrder->items->map(fn ($item) => [
            'product_id' => $item->product_id,
            'supplier_id' => $item->supplier_id,
            'inventory_location_id' => $item->inventory_location_id,
            'purchase_request_item_id' => $item->purchase_request_item_id,
            'quantity_ordered' => (float) $item->quantity_ordered,
            'unit_price' => $item->unit_price !== null ? (float) $item->unit_price : '',
            'estimated_arrival_date' => optional($item->estimated_arrival_date)->format('Y-m-d'),
            'notes' => $item->notes,
        ])->values()->all()
        : ($prefillItems !== [] ? $prefillItems : [['product_id' => '', 'supplier_id' => '', 'inventory_location_id' => '', 'purchase_request_item_id' => '', 'quantity_ordered' => 1, 'unit_price' => '', 'estimated_arrival_date' => '', 'notes' => '']]));

    $supplierMap = $suppliers->mapWithKeys(fn ($supplier) => [$supplier->id => $supplier->name]);
@endphp

<div x-data="{
        items: @js($initialItems),
        supplierNames: @js($supplierMap),
        addItem() { this.items.push({ product_id: '', supplier_id: '', inventory_location_id: '', purchase_request_item_id: '', quantity_ordered: 1, unit_price: '', estimated_arrival_date: '', notes: '' }); },
        removeItem(index) { if (this.items.length > 1) { this.items.splice(index, 1); } },
        lineTotal(item) {
            const qty = parseFloat(item.quantity_ordered) || 0;
            const price = parseFloat(item.unit_price) || 0;
            return qty * price;
        },
        get vendorSummary() {
            const groups = {};
            this.items.forEach((item) => {
                const key = item.supplier_id || 'none';
                if (!groups[key]) { groups[key] = { name: item.supplier_id ? (this.supplierNames[item.supplier_id] || 'Supplier') : 'Belum dipilih', count: 0, total: 0 }; }
                groups[key].count += 1;
                groups[key].total += this.lineTotal(item);
            });
            return Object.values(groups);
        },
        get grandTotal() { return this.items.reduce((sum, item) => sum + this.lineTotal(item), 0); },
        formatCurrency(value) { return 'Rp ' + (Number(value) || 0).toLocaleString('id-ID'); }
    }" class="space-y-6">
    @if ($purchaseRequest)
        <input type="hidden" name="purchase_request_id" value="{{ $purchaseRequest->id }}">
    @endif

    <x-ui.alert variant="info" title="Supplier dipilih per item">
        Satu pesanan pembelian dapat memuat beberapa supplier. Pilih supplier dan estimasi tanggal barang datang pada setiap baris item.
    </x-ui.alert>

    <section class="rounded-lg border border-gray-200 bg-gray-50/50">
        <div class="border-b border-gray-200 px-4 py-3">
            <h3 class="text-base font-semibold text-gray-900">Informasi Pesanan</h3>
            <p class="mt-1 text-sm text-gray-500">Tanggal, nomor referensi, dan catatan pesanan pembelian.</p>
        </div>
        <div class="grid gap-6 p-4 md:grid-cols-2">
            <div>
                <label for="order-date" class="block text-sm font-medium text-gray-700">Tanggal Pesanan <span class="text-red-600">*</span></label>
                <input id="order-date" type="date" name="order_date" required
                       value="{{ old('order_date', optional($purchaseOrder?->order_date)->format('Y-m-d') ?? now()->toDateString()) }}"
                       class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                @error('order_date')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="supplier-reference" class="block text-sm font-medium text-gray-700">Nomor Referensi Supplier</label>
                <input id="supplier-reference" type="text" name="supplier_reference_number"
                       value="{{ old('supplier_reference_number', $purchaseOrder?->supplier_reference_number) }}"
                       class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                @error('supplier_reference_number')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="currency" class="block text-sm font-medium text-gray-700">Mata Uang</label>
                <input id="currency" type="text" name="currency" readonly
                       value="{{ old('currency', $purchaseOrder?->currency ?? 'IDR') }}"
                       class="mt-1 block w-full rounded-lg border-gray-300 bg-gray-50 text-sm focus:border-brand-500 focus:ring-brand-500">
                @error('currency')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="md:col-span-2">
                <label for="order-notes" class="block text-sm font-medium text-gray-700">Catatan</label>
                <textarea id="order-notes" name="notes" rows="3" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">{{ old('notes', $purchaseOrder?->notes) }}</textarea>
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
                <p class="mt-1 text-sm text-gray-500">Tambahkan produk, supplier, jumlah, harga satuan, dan estimasi tanggal barang datang untuk setiap baris.</p>
            </div>
            <button type="button" @click="addItem()"
                    class="inline-flex items-center rounded-lg border border-brand-200 bg-brand-50 px-3 py-1.5 text-sm font-medium text-brand-700 hover:bg-brand-100 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
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
                        <button type="button" @click="removeItem(index)" x-show="items.length > 1"
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
                            <label :for="'supplier-' + index" class="block text-sm font-medium text-gray-700">Supplier <span class="text-red-600">*</span></label>
                            <select :id="'supplier-' + index" :name="'items[' + index + '][supplier_id]'" x-model="item.supplier_id" required
                                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                                <option value="">Pilih supplier</option>
                                @foreach ($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label :for="'quantity-' + index" class="block text-sm font-medium text-gray-700">Jumlah Dipesan <span class="text-red-600">*</span></label>
                            <input :id="'quantity-' + index" type="number" step="0.0001" min="0.0001" :name="'items[' + index + '][quantity_ordered]'" x-model.number="item.quantity_ordered" required
                                   class="mt-1 block w-full rounded-lg border-gray-300 text-sm tabular-nums focus:border-brand-500 focus:ring-brand-500">
                        </div>
                        <div>
                            <label :for="'price-' + index" class="block text-sm font-medium text-gray-700">Harga Satuan</label>
                            <input :id="'price-' + index" type="number" step="0.01" min="0" :name="'items[' + index + '][unit_price]'" x-model="item.unit_price"
                                   class="mt-1 block w-full rounded-lg border-gray-300 text-sm tabular-nums focus:border-brand-500 focus:ring-brand-500">
                        </div>
                        <div>
                            <label :for="'arrival-' + index" class="block text-sm font-medium text-gray-700">Estimasi Barang Datang <span class="text-red-600">*</span></label>
                            <input :id="'arrival-' + index" type="date" :name="'items[' + index + '][estimated_arrival_date]'" x-model="item.estimated_arrival_date" required
                                   class="mt-1 block w-full rounded-lg border-gray-300 text-sm tabular-nums focus:border-brand-500 focus:ring-brand-500">
                        </div>
                        <div>
                            <label :for="'location-' + index" class="block text-sm font-medium text-gray-700">Lokasi Persediaan</label>
                            <select :id="'location-' + index" :name="'items[' + index + '][inventory_location_id]'" x-model="item.inventory_location_id"
                                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                                <option value="">Tanpa lokasi spesifik</option>
                                @foreach ($locations as $location)
                                    <option value="{{ $location->id }}">{{ $location->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="md:col-span-2 lg:col-span-2">
                            <label :for="'item-notes-' + index" class="block text-sm font-medium text-gray-700">Catatan Item</label>
                            <input :id="'item-notes-' + index" type="text" :name="'items[' + index + '][notes]'" x-model="item.notes"
                                   class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                        </div>
                        <div class="flex items-end">
                            <div class="w-full rounded-lg bg-gray-50 px-3 py-2 ring-1 ring-gray-100">
                                <p class="text-xs text-gray-500">Subtotal Baris</p>
                                <p class="mt-0.5 text-sm font-semibold tabular-nums text-gray-900" x-text="formatCurrency(lineTotal(item))"></p>
                            </div>
                        </div>
                    </div>
                    <template x-if="item.purchase_request_item_id">
                        <input type="hidden" :name="'items[' + index + '][purchase_request_item_id]'" :value="item.purchase_request_item_id">
                    </template>
                </div>
            </template>
        </div>

        <div class="border-t border-gray-200 bg-gray-50/60 px-4 py-3">
            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Ringkasan Supplier</h4>
            <ul class="mt-2 space-y-1 text-sm">
                <template x-for="group in vendorSummary" :key="group.name">
                    <li class="flex items-center justify-between gap-3">
                        <span class="text-gray-700"><span x-text="group.name"></span> — <span x-text="group.count"></span> item</span>
                        <span class="font-semibold tabular-nums text-gray-900" x-text="formatCurrency(group.total)"></span>
                    </li>
                </template>
            </ul>
            <div class="mt-2 flex items-center justify-between border-t border-gray-200 pt-2 text-sm">
                <span class="font-semibold text-gray-900">Grand Total</span>
                <span class="font-semibold tabular-nums text-brand-700" x-text="formatCurrency(grandTotal)"></span>
            </div>
        </div>
    </section>

    @error('items')
        <p class="text-sm text-red-600">{{ $message }}</p>
    @enderror
    @error('items.*')
        <p class="text-sm text-red-600">{{ $message }}</p>
    @enderror
    @error('items.*.supplier_id')
        <p class="text-sm text-red-600">{{ $message }}</p>
    @enderror
    @error('items.*.estimated_arrival_date')
        <p class="text-sm text-red-600">{{ $message }}</p>
    @enderror
    @error('purchase_request_id')
        <p class="text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>
