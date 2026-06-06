@php
    use App\Modules\Inventory\Models\GoodsReceipt;
    use App\Modules\Inventory\Models\InventoryMovement;

    $movementLabels = [
        InventoryMovement::TYPE_PURCHASE => 'Pembelian',
    ];
@endphp

<x-settings-shell title="Detail Penerimaan Barang {{ $goodsReceipt->receipt_number }}">
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Detail Penerimaan Barang</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">{{ $goodsReceipt->receipt_number }}</h2>
                <p class="mt-1 text-sm text-gray-500">
                    {{ format_date_id($goodsReceipt->receipt_date) }}
                    · @include('inventory.goods-receipts._status-badge', ['status' => $goodsReceipt->status])
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @can('update', $goodsReceipt)
                    <a href="{{ route('inventory.goods-receipts.edit', $goodsReceipt) }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                        Edit
                    </a>
                @endcan

                @can('submit', $goodsReceipt)
                    <form method="POST" action="{{ route('inventory.goods-receipts.submit', $goodsReceipt) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center rounded-lg bg-yellow-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-yellow-500 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2">
                            Submit
                        </button>
                    </form>
                @endcan

                @can('post', $goodsReceipt)
                    <button type="button" class="inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2" x-data @click="$dispatch('open-modal', 'confirm-post-gr-show')">
                        Posting
                    </button>
                @endcan

                @can('cancel', $goodsReceipt)
                    <button type="button" class="inline-flex items-center rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2" x-data @click="$dispatch('open-modal', 'confirm-cancel-gr-show')">
                        Batalkan
                    </button>
                @endcan

                <a href="{{ route('inventory.goods-receipts.index') }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Kembali
                </a>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 px-4 py-3">
                        <h3 class="text-base font-semibold text-gray-900">Item Penerimaan</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr class="text-left text-gray-500">
                                    <th scope="col" class="px-4 py-3 font-medium">Produk</th>
                                    <th scope="col" class="px-3 py-3 font-medium">Lokasi Stok</th>
                                    <th scope="col" class="px-3 py-3 text-right font-medium">Ordered</th>
                                    <th scope="col" class="px-3 py-3 text-right font-medium">Previously Received</th>
                                    <th scope="col" class="px-3 py-3 text-right font-medium">Received</th>
                                    <th scope="col" class="px-3 py-3 text-right font-medium">Accepted</th>
                                    <th scope="col" class="px-3 py-3 text-right font-medium">Rejected</th>
                                    <th scope="col" class="px-3 py-3 text-right font-medium">Unit Cost</th>
                                    <th scope="col" class="px-3 py-3 text-right font-medium">Line Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($goodsReceipt->items as $item)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3">
                                            <p class="font-semibold text-gray-900">{{ $item->product?->name ?? '—' }}</p>
                                            <p class="text-xs text-gray-500">{{ $item->product?->code ?? '—' }}</p>
                                        </td>
                                        <td class="px-3 py-3 text-gray-600">{{ $item->inventoryLocation?->name ?? '—' }}</td>
                                        <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_quantity_id($item->ordered_qty) }}</td>
                                        <td class="px-3 py-3 text-right tabular-nums text-gray-500">{{ format_quantity_id($item->previously_received_qty) }}</td>
                                        <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_quantity_id($item->received_qty) }}</td>
                                        <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_quantity_id($item->accepted_qty) }}</td>
                                        <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_quantity_id($item->rejected_qty) }}</td>
                                        <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ $item->unit_cost !== null ? format_currency_id($item->unit_cost) : '—' }}</td>
                                        <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ $item->line_total !== null ? format_currency_id($item->line_total) : '—' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="9" class="px-4 py-8 text-center text-sm text-gray-500">Belum ada item.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @if ($goodsReceipt->isPosted() && $ledgerMovements->isNotEmpty())
                    <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
                        <div class="border-b border-gray-200 px-4 py-3">
                            <h3 class="text-base font-semibold text-gray-900">Jejak Pergerakan Stok</h3>
                            <p class="mt-1 text-sm text-gray-500">Pergerakan ledger PURCHASE yang dihasilkan saat penerimaan diposting.</p>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50">
                                    <tr class="text-left text-gray-500">
                                        <th scope="col" class="px-4 py-3 font-medium">Inventory Movement ID</th>
                                        <th scope="col" class="px-3 py-3 font-medium">Produk</th>
                                        <th scope="col" class="px-3 py-3 font-medium">Lokasi</th>
                                        <th scope="col" class="px-3 py-3 font-medium">Movement Type</th>
                                        <th scope="col" class="px-3 py-3 text-right font-medium">Masuk</th>
                                        <th scope="col" class="px-3 py-3 font-medium">Tanggal Posting</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($ledgerMovements as $movement)
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-3 font-medium text-gray-900">#{{ $movement->id }}</td>
                                            <td class="px-3 py-3">
                                                <p class="font-medium text-gray-900">{{ $movement->product?->name ?? '—' }}</p>
                                                <p class="text-xs text-gray-500">{{ $movement->product?->code ?? '—' }}</p>
                                            </td>
                                            <td class="px-3 py-3 text-gray-600">{{ $movement->inventoryLocation?->name ?? '—' }}</td>
                                            <td class="px-3 py-3 text-gray-600">{{ $movementLabels[$movement->movement_type] ?? $movement->movement_type }}</td>
                                            <td class="px-3 py-3 text-right tabular-nums text-green-700">+{{ format_quantity_id($movement->quantity_in) }}</td>
                                            <td class="px-3 py-3 text-gray-600">{{ format_datetime_id($goodsReceipt->posted_at ?? $movement->movement_date) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>

            <div class="space-y-6">
                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <h3 class="text-base font-semibold text-gray-900">Header</h3>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div>
                            <dt class="text-gray-500">No. Penerimaan</dt>
                            <dd class="font-medium text-gray-900">{{ $goodsReceipt->receipt_number }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Status</dt>
                            <dd>@include('inventory.goods-receipts._status-badge', ['status' => $goodsReceipt->status])</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Purchase Order</dt>
                            <dd>
                                @if ($goodsReceipt->purchaseOrder)
                                    <a href="{{ route('inventory.purchase-orders.show', $goodsReceipt->purchaseOrder) }}" class="font-medium text-teal-700 hover:text-teal-600">
                                        {{ $goodsReceipt->purchaseOrder->purchase_order_number }}
                                    </a>
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Supplier</dt>
                            <dd class="font-medium text-gray-900">{{ $goodsReceipt->purchaseOrder?->displaySupplierName() ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Cabang</dt>
                            <dd class="font-medium text-gray-900">{{ $goodsReceipt->purchaseOrder?->branch?->name ?? $goodsReceipt->branch?->name ?? '—' }}</dd>
                        </div>
                        @if ($goodsReceipt->notes)
                            <div>
                                <dt class="text-gray-500">Catatan</dt>
                                <dd class="text-gray-900">{{ $goodsReceipt->notes }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <h3 class="text-base font-semibold text-gray-900">Referensi Supplier</h3>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div>
                            <dt class="text-gray-500">No. Surat Jalan Supplier</dt>
                            <dd class="font-medium text-gray-900">{{ $goodsReceipt->supplier_delivery_number ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">No. Invoice Supplier</dt>
                            <dd class="font-medium text-gray-900">{{ $goodsReceipt->supplier_invoice_number ?? '—' }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <h3 class="text-base font-semibold text-gray-900">Audit</h3>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div>
                            <dt class="text-gray-500">Dibuat Oleh</dt>
                            <dd class="font-medium text-gray-900">{{ $goodsReceipt->createdBy?->name ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Diajukan Oleh</dt>
                            <dd class="font-medium text-gray-900">{{ $goodsReceipt->submittedBy?->name ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Diposting Oleh</dt>
                            <dd class="font-medium text-gray-900">{{ $goodsReceipt->postedBy?->name ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Dibatalkan Oleh</dt>
                            <dd class="font-medium text-gray-900">{{ $goodsReceipt->cancelledBy?->name ?? '—' }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    @can('post', $goodsReceipt)
        <x-modal name="confirm-post-gr-show" focusable>
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900">Konfirmasi Posting</h3>
                <p class="mt-3 text-sm text-gray-600">Posting penerimaan barang akan menambah stok melalui ledger inventory dan tidak dapat diedit kembali.</p>
                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <button type="button" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50" @click="$dispatch('close-modal', 'confirm-post-gr-show')">Batal</button>
                    <form method="POST" action="{{ route('inventory.goods-receipts.post', $goodsReceipt) }}">
                        @csrf
                        <button type="submit" class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-600">Ya, Posting Penerimaan</button>
                    </form>
                </div>
            </div>
        </x-modal>
    @endcan

    @can('cancel', $goodsReceipt)
        <x-modal name="confirm-cancel-gr-show" focusable>
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900">Konfirmasi Pembatalan</h3>
                <p class="mt-3 text-sm text-gray-600">Batalkan draft penerimaan barang ini?</p>
                <div class="mt-6 flex flex-wrap justify-end gap-3">
                    <button type="button" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50" @click="$dispatch('close-modal', 'confirm-cancel-gr-show')">Kembali</button>
                    <form method="POST" action="{{ route('inventory.goods-receipts.cancel', $goodsReceipt) }}">
                        @csrf
                        <button type="submit" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-500">Ya, Batalkan</button>
                    </form>
                </div>
            </div>
        </x-modal>
    @endcan
</x-settings-shell>
