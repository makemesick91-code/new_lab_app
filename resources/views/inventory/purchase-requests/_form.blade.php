@php
    $purchaseRequest = $purchaseRequest ?? null;
    $prefillItem = $prefillItem ?? null;
    $initialItems = old('items', $purchaseRequest
        ? $purchaseRequest->items->map(fn ($item) => [
            'product_id' => $item->product_id,
            'inventory_location_id' => $item->inventory_location_id,
            'quantity_requested' => (float) $item->quantity_requested,
            'estimated_unit_price' => $item->estimated_unit_price !== null ? (float) $item->estimated_unit_price : '',
            'notes' => $item->notes,
        ])->values()->all()
        : ($prefillItem ? [$prefillItem] : [['product_id' => '', 'inventory_location_id' => '', 'quantity_requested' => 1, 'estimated_unit_price' => '', 'notes' => '']]));
@endphp

<div x-data="{ items: @js($initialItems) }" class="space-y-6">
    <div class="grid gap-6 md:grid-cols-2">
        <div>
            <label for="request-date" class="block text-sm font-medium text-gray-700">Tanggal Permintaan <span class="text-red-600">*</span></label>
            <input id="request-date" type="date" name="request_date" required
                   value="{{ old('request_date', optional($purchaseRequest?->request_date)->format('Y-m-d') ?? now()->toDateString()) }}"
                   class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
            @error('request_date')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        {{-- FIX-PRE-68-45 Scope G — branch PR type (Reguler/Darurat). Defaults from
             the ?pr_type= query param used by the "Alur PR Cabang" quick-create. --}}
        <div>
            <label for="pr-type" class="block text-sm font-medium text-gray-700">Tipe Permintaan</label>
            @php $prTypeValue = old('pr_type', $purchaseRequest?->pr_type ?? request('pr_type')); @endphp
            <select id="pr-type" name="pr_type" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                <option value="reguler" @selected($prTypeValue !== 'darurat')>Reguler</option>
                <option value="darurat" @selected($prTypeValue === 'darurat')>Darurat</option>
            </select>
            @error('pr_type')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="md:col-span-2">
            <label for="request-notes" class="block text-sm font-medium text-gray-700">Catatan</label>
            <textarea id="request-notes" name="notes" rows="3" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">{{ old('notes', $purchaseRequest?->notes) }}</textarea>
            @error('notes')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="rounded-lg border border-gray-200">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
            <h3 class="text-base font-semibold text-gray-900">Item Permintaan</h3>
            <button type="button" @click="items.push({ product_id: '', inventory_location_id: '', quantity_requested: 1, estimated_unit_price: '', notes: '' })"
                    class="inline-flex items-center rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                Tambah Item
            </button>
        </div>

        <div class="divide-y divide-gray-100">
            <template x-for="(item, index) in items" :key="index">
                <div class="grid gap-4 p-4 md:grid-cols-2 lg:grid-cols-5">
                    <div>
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
                                class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                            <option value="">Tanpa lokasi spesifik</option>
                            @foreach ($locations as $location)
                                <option value="{{ $location->id }}">{{ $location->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label :for="'quantity-' + index" class="block text-sm font-medium text-gray-700">Jumlah Diminta <span class="text-red-600">*</span></label>
                        <input :id="'quantity-' + index" type="number" step="0.0001" min="0.0001" :name="'items[' + index + '][quantity_requested]'" x-model.number="item.quantity_requested" required
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                    </div>
                    <div>
                        <label :for="'price-' + index" class="block text-sm font-medium text-gray-700">Perkiraan Harga Satuan</label>
                        <input :id="'price-' + index" type="number" step="0.01" min="0" :name="'items[' + index + '][estimated_unit_price]'" x-model="item.estimated_unit_price"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                    </div>
                    <div class="flex items-end gap-2">
                        <div class="flex-1">
                            <label :for="'item-notes-' + index" class="block text-sm font-medium text-gray-700">Catatan Item</label>
                            <input :id="'item-notes-' + index" type="text" :name="'items[' + index + '][notes]'" x-model="item.notes"
                                   class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                        </div>
                        <button type="button" @click="items.length > 1 ? items.splice(index, 1) : null" x-show="items.length > 1"
                                class="mb-0.5 rounded-lg border border-red-200 px-2 py-2 text-sm font-medium text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                            Hapus
                        </button>
                    </div>
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
</div>
