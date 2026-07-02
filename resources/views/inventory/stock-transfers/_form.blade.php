@php
    $stockTransfer = $stockTransfer ?? null;
    $batchOptions = $batchOptions ?? [];
    $productBatchFlags = $productBatchFlags ?? [];
    $initialItems = old('items', $stockTransfer
        ? $stockTransfer->items->map(fn ($item) => [
            'product_id' => $item->product_id,
            'inventory_batch_id' => $item->inventory_batch_id,
            'quantity' => (float) $item->quantity,
            'notes' => $item->notes,
        ])->values()->all()
        : [['product_id' => '', 'inventory_batch_id' => '', 'quantity' => 1, 'notes' => '']]);
    $initialSourceLocationId = (int) old('source_inventory_location_id', $stockTransfer?->source_inventory_location_id ?? 0);
@endphp

<div
    x-data="{
        items: @js($initialItems),
        sourceLocationId: @js($initialSourceLocationId),
        batchOptions: @js($batchOptions),
        productBatchFlags: @js($productBatchFlags),
        productRequiresBatch(productId) {
            return Boolean(this.productBatchFlags[String(productId)] ?? this.productBatchFlags[productId]);
        },
        batchesForItem(item) {
            if (! this.sourceLocationId || ! item.product_id || ! this.productRequiresBatch(item.product_id)) {
                return [];
            }

            const productBatches = this.batchOptions[item.product_id] ?? {};
            return productBatches[this.sourceLocationId] ?? [];
        },
        batchLabel(batch) {
            return batch.label ?? batch.batch_number;
        },
        resetBatchIfInvalid(item) {
            if (! this.productRequiresBatch(item.product_id)) {
                item.inventory_batch_id = '';

                return;
            }

            const batches = this.batchesForItem(item);
            const stillValid = batches.some((batch) => String(batch.id) === String(item.inventory_batch_id));

            if (! stillValid) {
                item.inventory_batch_id = '';
            }
        },
        onSourceChange() {
            this.items.forEach((item) => this.resetBatchIfInvalid(item));
        },
        onProductChange(item) {
            item.inventory_batch_id = '';
        }
    }"
    class="space-y-6"
>
    <section class="rounded-lg border border-gray-200 bg-gray-50/50">
        <div class="border-b border-gray-200 px-4 py-3">
            <h3 class="text-base font-semibold text-gray-900">Informasi Transfer</h3>
            <p class="mt-1 text-sm text-gray-500">Lokasi asal, lokasi tujuan, tanggal, dan catatan transfer.</p>
        </div>
        <div class="grid gap-6 p-4 md:grid-cols-2">
            <div>
                <label for="source-location" class="block text-sm font-medium text-gray-700">Lokasi Sumber <span class="text-red-600">*</span></label>
                <select id="source-location" name="source_inventory_location_id" x-model.number="sourceLocationId" @change="onSourceChange()" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                    <option value="">Pilih lokasi sumber</option>
                    @foreach ($locations as $location)
                        <option value="{{ $location->id }}">{{ $location->name }}</option>
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
    </section>

    <section class="rounded-lg border border-gray-200 bg-gray-50/50">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
            <div>
                <h3 class="text-base font-semibold text-gray-900">Item Transfer</h3>
                <p class="mt-1 text-sm text-gray-500">Tambahkan produk dan jumlah yang akan dipindahkan antar lokasi.</p>
            </div>
            <button type="button" @click="items.push({ product_id: '', inventory_batch_id: '', quantity: 1, notes: '' })"
                    class="inline-flex items-center rounded-lg bg-teal-700 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                + Tambah Item
            </button>
        </div>

        <div class="space-y-4 p-4">
            @error('items')
                <p class="text-sm text-red-600">{{ $message }}</p>
            @enderror

            <template x-for="(item, index) in items" :key="index">
                <div class="grid gap-4 rounded-lg border border-gray-200 bg-white p-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_8rem_minmax(0,1fr)_auto] md:items-end">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Produk <span class="text-red-600">*</span></label>
                        <x-inventory.searchable-product-select
                            :products="$products"
                            alpine-name="`items[${index}][product_id]`"
                            model="item.product_id"
                            on-select="onProductChange(item)"
                            class="mt-1"
                            required
                        />
                    </div>
                    <div x-show="productRequiresBatch(item.product_id)">
                        <label class="block text-sm font-medium text-gray-700">Batch / Expired <span class="text-red-600">*</span></label>
                        <select :name="`items[${index}][inventory_batch_id]`" x-model="item.inventory_batch_id" :required="productRequiresBatch(item.product_id)" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="">Pilih batch</option>
                            <template x-for="batch in batchesForItem(item)" :key="batch.id">
                                <option :value="batch.id" x-text="batchLabel(batch)"></option>
                            </template>
                        </select>
                        <p class="mt-1 text-xs text-gray-500" x-show="!sourceLocationId">Pilih lokasi sumber untuk memuat batch.</p>
                        <p class="mt-1 text-xs text-gray-500" x-show="sourceLocationId && item.product_id && productRequiresBatch(item.product_id)">Pilih batch dari lokasi asal. Urutan mengikuti expired terdekat.</p>
                        <p class="mt-1 text-xs text-amber-700" x-show="sourceLocationId && item.product_id && productRequiresBatch(item.product_id) && batchesForItem(item).length === 0">Belum ada batch tersedia untuk produk ini di lokasi asal.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Jumlah <span class="text-red-600">*</span></label>
                        <input type="number" step="0.0001" min="0.0001" :name="`items[${index}][quantity]`" x-model="item.quantity" required
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm tabular-nums focus:border-teal-500 focus:ring-teal-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Catatan Item</label>
                        <input type="text" :name="`items[${index}][notes]`" x-model="item.notes" placeholder="Opsional"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                    </div>
                    <div class="flex items-end">
                        <button type="button" x-show="items.length > 1" @click="items.splice(index, 1)"
                                class="rounded-lg border border-rose-200 px-3 py-2 text-sm font-medium text-rose-700 hover:bg-rose-50">
                            Hapus
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </section>
</div>
