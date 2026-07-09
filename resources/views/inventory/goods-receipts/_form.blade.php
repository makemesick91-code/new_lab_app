@php
    use App\Modules\Inventory\Models\GoodsReceipt;

    $goodsReceipt = $goodsReceipt ?? null;
    $purchaseOrder = $purchaseOrder ?? ($goodsReceipt?->purchaseOrder);
    $isEdit = $goodsReceipt !== null;
    $receiptDateDefault = old('receipt_date', optional($goodsReceipt?->receipt_date)->format('Y-m-d') ?? now()->toDateString());
    $batchesByProduct = $batchesByProduct ?? [];

    $mapItem = function ($item) use ($receiptDateDefault) {
        if (is_array($item)) {
            $receivedQty = (float) ($item['received_qty'] ?? (($item['accepted_qty'] ?? 0) + ($item['rejected_qty'] ?? 0)));
            $acceptedQty = (float) ($item['accepted_qty'] ?? $receivedQty);
            $rejectedQty = (float) ($item['rejected_qty'] ?? 0);
            $unitCost = (float) ($item['unit_cost'] ?? 0);
            $batchMode = $item['batch_mode'] ?? (filled($item['inventory_batch_id'] ?? null) ? 'existing' : 'new');
            $autoBatch = array_key_exists('auto_batch', $item)
                ? filter_var($item['auto_batch'], FILTER_VALIDATE_BOOLEAN)
                : (! filled($item['batch_number'] ?? null) && (bool) ($item['requires_batch_tracking'] ?? ($item['product']['requires_batch_tracking'] ?? false)));

            return [
                'purchase_order_item_id' => $item['purchase_order_item_id'] ?? '',
                'product_id' => $item['product_id'] ?? '',
                'product_name' => $item['product_name'] ?? ($item['product']['name'] ?? ''),
                'product_code' => $item['product_code'] ?? ($item['product']['code'] ?? ''),
                'requires_batch_tracking' => (bool) ($item['requires_batch_tracking'] ?? ($item['product']['requires_batch_tracking'] ?? false)),
                'inventory_location_id' => $item['inventory_location_id'] ?? '',
                'quantity_ordered' => (float) ($item['quantity_ordered'] ?? $item['ordered_qty'] ?? 0),
                'previously_received_qty' => (float) ($item['previously_received_qty'] ?? 0),
                'remaining_qty' => (float) ($item['remaining_qty'] ?? max(0, ($item['quantity_ordered'] ?? $item['ordered_qty'] ?? 0) - ($item['previously_received_qty'] ?? 0))),
                'received_qty' => $receivedQty,
                'accepted_qty' => $acceptedQty,
                'rejected_qty' => $rejectedQty,
                'unit_cost' => $unitCost,
                'notes' => $item['notes'] ?? '',
                'batch_mode' => $batchMode,
                'auto_batch' => $autoBatch,
                'inventory_batch_id' => $item['inventory_batch_id'] ?? '',
                'batch_number' => $item['batch_number'] ?? '',
                'lot_number' => $item['lot_number'] ?? '',
                'batch_received_date' => $item['batch_received_date'] ?? $receiptDateDefault,
                'expiry_date' => $item['expiry_date'] ?? '',
            ];
        }

        return [
            'purchase_order_item_id' => $item->purchase_order_item_id,
            'product_id' => $item->product_id,
            'product_name' => $item->product?->name ?? '',
            'product_code' => $item->product?->code ?? '',
            'requires_batch_tracking' => (bool) ($item->product?->requires_batch_tracking ?? false),
            'inventory_location_id' => $item->inventory_location_id,
            'quantity_ordered' => (float) $item->ordered_qty,
            'previously_received_qty' => (float) $item->previously_received_qty,
            'remaining_qty' => max(0, (float) $item->ordered_qty - (float) $item->previously_received_qty),
            'received_qty' => (float) $item->received_qty,
            'accepted_qty' => (float) $item->accepted_qty,
            'rejected_qty' => (float) $item->rejected_qty,
            'unit_cost' => (float) ($item->unit_cost ?? $item->purchaseOrderItem?->unit_price ?? 0),
            'notes' => $item->notes ?? '',
            'batch_mode' => $item->inventory_batch_id ? 'existing' : 'new',
            'auto_batch' => (bool) ($item->product?->requires_batch_tracking ?? false) && ! filled($item->batch_number),
            'inventory_batch_id' => $item->inventory_batch_id ?? '',
            'batch_number' => $item->batch_number ?? '',
            'lot_number' => $item->lot_number ?? '',
            'batch_received_date' => optional($item->batch_received_date)->format('Y-m-d') ?? $receiptDateDefault,
            'expiry_date' => optional($item->expiry_date)->format('Y-m-d') ?? '',
        ];
    };

    $initialItems = old('items')
        ? collect(old('items'))->map(fn ($item) => $mapItem($item))->values()->all()
        : ($isEdit
            ? $goodsReceipt->items->map(fn ($item) => $mapItem($item))->values()->all()
            : collect($prefillItems ?? [])->map(fn ($item) => $mapItem($item))->values()->all());

    if ($initialItems === []) {
        $initialItems = [];
    }

    $selectedPurchaseOrderId = (int) old('purchase_order_id', $purchaseOrder?->id ?? 0);

    $itemValidationErrors = collect($errors->getMessages())
        ->filter(fn ($messages, $key) => is_string($key) && (str_starts_with($key, 'items.') || $key === 'items'))
        ->flatMap(fn ($messages) => $messages)
        ->unique()
        ->values();
@endphp

<div
    x-data="{
        items: @js($initialItems),
        batchesByProduct: @js($batchesByProduct),
        formatNumber(value) {
            const number = Number(value ?? 0);
            return Number.isFinite(number) ? number.toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 2 }) : '0';
        },
        formatCurrency(value) {
            const number = Number(value ?? 0);
            return Number.isFinite(number)
                ? number.toLocaleString('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0, maximumFractionDigits: 0 })
                : 'Rp0';
        },
        lineTotal(item) {
            return Number(item.accepted_qty || 0) * Number(item.unit_cost || 0);
        },
        syncReceived(item) {
            const accepted = Number(item.accepted_qty || 0);
            const rejected = Number(item.rejected_qty || 0);
            item.received_qty = accepted + rejected;
        },
        onAcceptedChange(item) {
            this.syncReceived(item);
        },
        onRejectedChange(item) {
            this.syncReceived(item);
        },
        isOverReceive(item) {
            return Number(item.accepted_qty || 0) > Number(item.remaining_qty || 0);
        },
        overReceiveMessage(item) {
            return 'Melebihi sisa pesanan (maks. ' + this.formatNumber(item.remaining_qty) + ').';
        }
    }"
    class="space-y-6"
>
    @if (! $isEdit)
        <div>
            <label for="purchase-order-id" class="block text-sm font-medium text-gray-700">Pesanan Pembelian <span class="text-red-600">*</span></label>
            <select
                id="purchase-order-id"
                name="purchase_order_id"
                class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500"
                onchange="if (this.value) { window.location.href = '{{ route('inventory.goods-receipts.create') }}?purchase_order_id=' + this.value; }"
            >
                <option value="">Pilih pesanan pembelian</option>
                @foreach ($receivablePurchaseOrders as $receivablePo)
                    <option value="{{ $receivablePo->id }}" @selected($selectedPurchaseOrderId === $receivablePo->id)>
                        {{ $receivablePo->purchase_order_number }} · {{ $receivablePo->displaySupplierName() }}
                    </option>
                @endforeach
            </select>
            @error('purchase_order_id')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
    @else
        <input type="hidden" name="purchase_order_id" value="{{ $goodsReceipt->purchase_order_id }}">
    @endif

    @if ($purchaseOrder)
        <div class="rounded-lg border border-brand-200 bg-brand-50 p-4 text-sm text-brand-800">
            <p class="font-semibold">Ringkasan Pesanan Pembelian</p>
            <dl class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-brand-700">No. PO</dt>
                    <dd class="mt-1 font-medium">
                        <a href="{{ route('inventory.purchase-orders.show', $purchaseOrder) }}" class="underline hover:text-brand-800">
                            {{ $purchaseOrder->purchase_order_number }}
                        </a>
                    </dd>
                </div>
                <div>
                    <dt class="text-brand-700">Supplier</dt>
                    <dd class="mt-1 font-medium">{{ $purchaseOrder->displaySupplierName() }}</dd>
                </div>
                <div>
                    <dt class="text-brand-700">Cabang</dt>
                    <dd class="mt-1 font-medium">{{ $purchaseOrder->branch?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-brand-700">Status PO</dt>
                    <dd class="mt-1">@include('inventory.purchase-orders._status-badge', ['status' => $purchaseOrder->status])</dd>
                </div>
            </dl>
        </div>
    @endif

    <div class="grid gap-6 md:grid-cols-2">
        <div>
            <label for="receipt-date" class="block text-sm font-medium text-gray-700">Tanggal Penerimaan <span class="text-red-600">*</span></label>
            <input
                id="receipt-date"
                type="date"
                name="receipt_date"
                value="{{ old('receipt_date', optional($goodsReceipt?->receipt_date)->format('Y-m-d') ?? now()->toDateString()) }}"
                required
                class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500"
            >
            @error('receipt_date')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="supplier-delivery-number" class="block text-sm font-medium text-gray-700">No. Surat Jalan Supplier</label>
            <input
                id="supplier-delivery-number"
                type="text"
                name="supplier_delivery_number"
                value="{{ old('supplier_delivery_number', $goodsReceipt?->supplier_delivery_number) }}"
                maxlength="100"
                class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500"
            >
            @error('supplier_delivery_number')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="supplier-invoice-number" class="block text-sm font-medium text-gray-700">No. Invoice Supplier</label>
            <input
                id="supplier-invoice-number"
                type="text"
                name="supplier_invoice_number"
                value="{{ old('supplier_invoice_number', $goodsReceipt?->supplier_invoice_number) }}"
                maxlength="100"
                class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500"
            >
            @error('supplier_invoice_number')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="md:col-span-2">
            <label for="goods-receipt-notes" class="block text-sm font-medium text-gray-700">Catatan</label>
            <textarea
                id="goods-receipt-notes"
                name="notes"
                rows="3"
                class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500"
            >{{ old('notes', $goodsReceipt?->notes) }}</textarea>
            @error('notes')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- FIX-PRE-68-45 Scope E — GR header-level default batch/lot. Fill once here;
         on save it expands to a DISTINCT batch per batch-tracked product/item that
         has no item-level batch. Item-level batch below always overrides. Products
         that do not require batch tracking keep their batch empty. --}}
    <div class="rounded-lg border border-brand-100 bg-brand-50 p-4">
        <h3 class="text-sm font-semibold text-gray-900">Batch / Lot Default (opsional)</h3>
        <p class="mt-1 text-xs text-gray-600">Isi sekali untuk semua item yang wajib pelacakan batch. Batch per produk dibuat otomatis dan terpisah — bukan satu batch global. Item yang sudah punya batch sendiri tidak akan ditimpa.</p>
        <ul class="mt-2 list-disc space-y-1 pl-4 text-xs text-gray-600">
            <li>Batch default akan diterapkan ke item yang wajib batch tracking.</li>
            <li>Item tetap bisa memakai batch berbeda melalui override.</li>
        </ul>
        <div class="mt-3 grid gap-4 md:grid-cols-4">
            <div>
                <label for="default-batch-number" class="block text-sm font-medium text-gray-700">No. Batch Default</label>
                <input id="default-batch-number" type="text" name="default_batch_number" value="{{ old('default_batch_number') }}" maxlength="100"
                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                @error('default_batch_number')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="default-lot-number" class="block text-sm font-medium text-gray-700">No. Lot Default</label>
                <input id="default-lot-number" type="text" name="default_lot_number" value="{{ old('default_lot_number') }}" maxlength="100"
                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                @error('default_lot_number')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="default-batch-received-date" class="block text-sm font-medium text-gray-700">Tgl Terima Default</label>
                <input id="default-batch-received-date" type="date" name="default_batch_received_date" value="{{ old('default_batch_received_date') }}"
                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                @error('default_batch_received_date')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="default-expiry-date" class="block text-sm font-medium text-gray-700">Tgl Kedaluwarsa Default</label>
                <input id="default-expiry-date" type="date" name="default_expiry_date" value="{{ old('default_expiry_date') }}"
                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                @error('default_expiry_date')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
        </div>
        <label class="mt-3 inline-flex items-center gap-2 text-sm text-gray-800">
            <input type="hidden" name="apply_default_batch_to_all" value="0">
            <input type="checkbox" name="apply_default_batch_to_all" value="1" @checked(old('apply_default_batch_to_all'))
                class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
            Terapkan batch/lot ini ke semua item yang wajib batch tracking
        </label>
    </div>

    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
        <div>
            <h3 class="text-base font-semibold text-gray-900">Item Penerimaan</h3>
            <p class="mt-1 text-sm text-gray-500">Isi lokasi stok dan jumlah diterima per baris. Kolom sudah diterima (PO) bersifat baca-saja.</p>
            <p class="mt-1 text-sm text-gray-500">Jumlah Diterima dihitung otomatis dari Diterima Baik + Ditolak. Hanya Diterima Baik yang menambah stok saat posting.</p>
            <p class="mt-2 rounded-lg border border-brand-100 bg-brand-50/60 px-3 py-2 text-xs text-brand-800">Untuk barang dilacak batch, centang &quot;Buat nomor batch otomatis&quot; lalu isi tanggal kedaluwarsa. Setelah Goods Receipt diposting, batch akan muncul di halaman Batch &amp; Lot.</p>
        </div>

        @error('items')
            <p class="mt-3 text-sm text-red-600">{{ $message }}</p>
        @enderror

        @if ($itemValidationErrors->isNotEmpty())
            <div class="mt-3 rounded-lg border border-red-200 bg-red-50 p-3" role="alert" aria-live="polite">
                <p class="text-sm font-medium text-red-800">Periksa item penerimaan:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-red-700">
                    @foreach ($itemValidationErrors as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($purchaseOrder === null)
            <p class="mt-4 text-sm text-gray-500">Pilih pesanan pembelian terlebih dahulu untuk menampilkan item.</p>
        @elseif (count($initialItems) === 0)
            <p class="mt-4 text-sm text-gray-500">Tidak ada sisa item yang dapat diterima dari pesanan pembelian ini.</p>
        @else
            <div class="mt-4 hidden overflow-x-auto lg:block">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-white">
                        <tr class="text-left text-gray-500">
                            <th scope="col" class="px-3 py-3 font-medium">Produk</th>
                            <th scope="col" class="px-3 py-3 text-right font-medium">Jumlah Dipesan</th>
                            <th scope="col" class="px-3 py-3 text-right font-medium">Sudah Diterima</th>
                            <th scope="col" class="px-3 py-3 text-right font-medium">Sisa</th>
                            <th scope="col" class="px-3 py-3 font-medium">Lokasi Stok</th>
                            <th scope="col" class="px-3 py-3 text-right font-medium">Diterima Baik</th>
                            <th scope="col" class="px-3 py-3 text-right font-medium">Ditolak</th>
                            <th scope="col" class="px-3 py-3 text-right font-medium">Jumlah Diterima</th>
                            <th scope="col" class="px-3 py-3 text-right font-medium">Harga Satuan</th>
                            <th scope="col" class="px-3 py-3 text-right font-medium">Total</th>
                        </tr>
                    </thead>
                    {{-- Alpine x-for requires a SINGLE root element per iteration. A table may
                         contain multiple <tbody> elements, so each item renders its own <tbody>
                         wrapping both the data row and the batch/lot detail row. Using two sibling
                         <tr> roots silently drops the batch row from the DOM on desktop. --}}
                    <template x-for="(item, index) in items" :key="index">
                        <tbody class="divide-y divide-gray-100 bg-white">
                            <tr>
                                <td class="px-3 py-3">
                                    <p class="font-semibold text-gray-900" x-text="item.product_name"></p>
                                    <p class="text-xs text-gray-500" x-text="item.product_code"></p>
                                    <input type="hidden" :name="'items[' + index + '][purchase_order_item_id]'" :value="item.purchase_order_item_id">
                                    <input type="hidden" :name="'items[' + index + '][product_id]'" :value="item.product_id">
                                    <input type="hidden" :name="'items[' + index + '][received_qty]'" :value="item.received_qty">
                                    <input type="hidden" :name="'items[' + index + '][requires_batch_tracking]'" :value="item.requires_batch_tracking ? 1 : 0">
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums text-gray-700" x-text="formatNumber(item.quantity_ordered)"></td>
                                <td class="px-3 py-3 text-right tabular-nums text-gray-500" x-text="formatNumber(item.previously_received_qty)"></td>
                                <td class="px-3 py-3 text-right tabular-nums text-gray-700" x-text="formatNumber(item.remaining_qty)"></td>
                                <td class="px-3 py-3">
                                    <select :name="'items[' + index + '][inventory_location_id]'" x-model="item.inventory_location_id" required class="block w-full min-w-[10rem] rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                                        <option value="">Pilih lokasi</option>
                                        @foreach ($locations as $location)
                                            <option value="{{ $location->id }}">{{ $location->name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="px-3 py-3">
                                    <input type="number" step="0.0001" min="0" :max="item.remaining_qty" :name="'items[' + index + '][accepted_qty]'" x-model.number="item.accepted_qty" @input="onAcceptedChange(item)" required class="block w-full min-w-[5rem] rounded-lg border-gray-300 text-right text-sm focus:border-brand-500 focus:ring-brand-500">
                                    <p x-show="isOverReceive(item)" x-cloak class="mt-1 text-xs text-amber-700" x-text="overReceiveMessage(item)"></p>
                                </td>
                                <td class="px-3 py-3">
                                    <input type="number" step="0.0001" min="0" :name="'items[' + index + '][rejected_qty]'" x-model.number="item.rejected_qty" @input="onRejectedChange(item)" class="block w-full min-w-[5rem] rounded-lg border-gray-300 text-right text-sm focus:border-brand-500 focus:ring-brand-500">
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums text-gray-700" x-text="formatNumber(item.received_qty)"></td>
                                <td class="px-3 py-3 text-right tabular-nums text-gray-700" x-text="formatCurrency(item.unit_cost)"></td>
                                <td class="px-3 py-3 text-right tabular-nums font-medium text-gray-900" x-text="formatCurrency(lineTotal(item))"></td>
                            </tr>
                            <tr x-show="item.requires_batch_tracking">
                                <td colspan="10" class="px-3 pb-3">
                                    @include('inventory.goods-receipts._batch-item-fields')
                                </td>
                            </tr>
                        </tbody>
                    </template>
                </table>
            </div>

            <div class="mt-4 space-y-4 lg:hidden">
                <template x-for="(item, index) in items" :key="'mobile-' + index">
                    <article class="rounded-lg border border-gray-200 bg-white p-4">
                        <p class="font-semibold text-gray-900" x-text="item.product_name"></p>
                        <p class="text-xs text-gray-500" x-text="item.product_code"></p>
                        <input type="hidden" :name="'items[' + index + '][purchase_order_item_id]'" :value="item.purchase_order_item_id">
                        <input type="hidden" :name="'items[' + index + '][product_id]'" :value="item.product_id">
                        <input type="hidden" :name="'items[' + index + '][received_qty]'" :value="item.received_qty">
                        <input type="hidden" :name="'items[' + index + '][requires_batch_tracking]'" :value="item.requires_batch_tracking ? 1 : 0">
                        <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                            <div>
                                <dt class="text-gray-500">Jumlah Dipesan</dt>
                                <dd class="font-medium tabular-nums" x-text="formatNumber(item.quantity_ordered)"></dd>
                            </div>
                            <div>
                                <dt class="text-gray-500">Sudah Diterima</dt>
                                <dd class="font-medium tabular-nums text-gray-500" x-text="formatNumber(item.previously_received_qty)"></dd>
                            </div>
                            <div>
                                <dt class="text-gray-500">Sisa</dt>
                                <dd class="font-medium tabular-nums" x-text="formatNumber(item.remaining_qty)"></dd>
                            </div>
                            <div>
                                <dt class="text-gray-500">Total</dt>
                                <dd class="font-medium tabular-nums" x-text="formatCurrency(lineTotal(item))"></dd>
                            </div>
                        </dl>
                        <div class="mt-3">
                            <label class="text-sm font-medium text-gray-700">Lokasi Stok</label>
                            <select :name="'items[' + index + '][inventory_location_id]'" x-model="item.inventory_location_id" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                                <option value="">Pilih lokasi</option>
                                @foreach ($locations as $location)
                                    <option value="{{ $location->id }}">{{ $location->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-sm font-medium text-gray-700">Diterima Baik</label>
                                <input type="number" step="0.0001" min="0" :max="item.remaining_qty" :name="'items[' + index + '][accepted_qty]'" x-model.number="item.accepted_qty" @input="onAcceptedChange(item)" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                                <p x-show="isOverReceive(item)" x-cloak class="mt-1 text-xs text-amber-700" x-text="overReceiveMessage(item)"></p>
                            </div>
                            <div>
                                <label class="text-sm font-medium text-gray-700">Ditolak</label>
                                <input type="number" step="0.0001" min="0" :name="'items[' + index + '][rejected_qty]'" x-model.number="item.rejected_qty" @input="onRejectedChange(item)" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                            </div>
                        </div>
                        <p class="mt-2 text-xs text-gray-500">Jumlah diterima: <span class="font-medium tabular-nums" x-text="formatNumber(item.received_qty)"></span></p>
                        <p class="mt-1 text-xs text-gray-500">Harga satuan: <span class="font-medium" x-text="formatCurrency(item.unit_cost)"></span></p>
                        @include('inventory.goods-receipts._batch-item-fields')
                    </article>
                </template>
            </div>
        @endif
    </div>
</div>
