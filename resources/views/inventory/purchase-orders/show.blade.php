@php
    use App\Modules\Inventory\Models\PurchaseOrder;
    use App\Modules\Inventory\Models\PurchaseOrderItem;

    $receivingStatuses = [
        PurchaseOrder::STATUS_SENT => [
            'status' => PurchaseOrderItem::RECEIVING_STATUS_PENDING,
            'label' => 'Belum Diterima',
        ],
        PurchaseOrder::STATUS_PARTIALLY_RECEIVED => [
            'status' => PurchaseOrderItem::RECEIVING_STATUS_PARTIAL,
            'label' => 'Sebagian',
        ],
        PurchaseOrder::STATUS_FULLY_RECEIVED => [
            'status' => PurchaseOrderItem::RECEIVING_STATUS_COMPLETE,
            'label' => 'Lengkap',
        ],
    ];
@endphp

<x-settings-shell title="Pesanan Pembelian {{ $purchaseOrder->purchase_order_number }}">
    <div class="space-y-6">
        <x-ui.page-header :title="$purchaseOrder->purchase_order_number" :subtitle="$purchaseOrder->displaySupplierName()">
            <x-slot:breadcrumb>Persediaan / Pesanan Pembelian</x-slot:breadcrumb>
            <x-slot:actions>
                @can('submit', $purchaseOrder)
                    <form method="POST" action="{{ route('inventory.purchase-orders.submit', $purchaseOrder) }}">
                        @csrf
                        <x-ui.button type="submit" variant="warning">Ajukan</x-ui.button>
                    </form>
                @endcan
                @can('approve', $purchaseOrder)
                    <form method="POST" action="{{ route('inventory.purchase-orders.approve', $purchaseOrder) }}">
                        @csrf
                        <x-ui.button type="submit" variant="success">Setujui</x-ui.button>
                    </form>
                @endcan
                @can('send', $purchaseOrder)
                    <form method="POST" action="{{ route('inventory.purchase-orders.send', $purchaseOrder) }}">
                        @csrf
                        <x-ui.button type="submit">Kirim ke Supplier</x-ui.button>
                    </form>
                @endcan
                @can('receive', $purchaseOrder)
                    <x-ui.button variant="primary" :href="route('inventory.goods-receipts.create', ['purchase_order_id' => $purchaseOrder->id])">Terima Barang</x-ui.button>
                @endcan
                @can('update', $purchaseOrder)
                    <x-ui.button variant="secondary" :href="route('inventory.purchase-orders.edit', $purchaseOrder)">Ubah</x-ui.button>
                @endcan
                @can('cancel', $purchaseOrder)
                    <form method="POST" action="{{ route('inventory.purchase-orders.cancel', $purchaseOrder) }}">
                        @csrf
                        <x-ui.button type="submit" variant="danger">Batalkan</x-ui.button>
                    </form>
                @endcan
                <x-ui.button variant="secondary" :href="route('inventory.purchase-orders.index')">Kembali</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <div class="flex flex-wrap items-center gap-2">
            @include('inventory.purchase-orders._status-badge', ['status' => $purchaseOrder->status])
            @if (isset($receivingStatuses[$purchaseOrder->status]))
                @include('inventory.purchase-orders._receiving-status-badge', $receivingStatuses[$purchaseOrder->status])
            @endif
        </div>

        <x-ui.alert variant="info" title="Tidak menambah stok">
            Pesanan pembelian tidak menambah stok. Stok bertambah hanya melalui penerimaan barang.
        </x-ui.alert>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium text-gray-500">Tanggal PO</p>
                <p class="mt-1 text-sm font-semibold tabular-nums text-gray-900">{{ format_date_id($purchaseOrder->order_date) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium text-gray-500">Perkiraan Kirim</p>
                <p class="mt-1 text-sm font-semibold tabular-nums text-gray-900">
                    {{ $purchaseOrder->expected_delivery_date ? format_date_id($purchaseOrder->expected_delivery_date) : '—' }}
                </p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium text-gray-500">Dibuat oleh</p>
                <p class="mt-1 text-sm font-semibold text-gray-900">{{ $purchaseOrder->createdBy?->name ?? '—' }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium text-gray-500">Total Item</p>
                <p class="mt-1 text-sm font-semibold tabular-nums text-gray-900">{{ format_number_id($purchaseOrder->items->count()) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:col-span-2 lg:col-span-1">
                <p class="text-xs font-medium text-gray-500">Total Nilai ({{ $purchaseOrder->currency }})</p>
                <p class="mt-1 text-sm font-semibold tabular-nums text-gray-900">{{ format_currency_id($purchaseOrder->total_amount) }}</p>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 px-4 py-3">
                        <h3 class="text-base font-semibold text-gray-900">Item Pesanan</h3>
                        <p class="mt-1 text-sm text-gray-500">Progress penerimaan per baris menggunakan cache jumlah diterima dari penerimaan barang yang diposting.</p>
                    </div>

                    <div class="hidden overflow-x-auto md:block">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr class="text-left text-gray-500">
                                    <th scope="col" class="px-4 py-3 font-medium">Produk</th>
                                    <th scope="col" class="px-3 py-3 font-medium">Lokasi</th>
                                    <th scope="col" class="px-3 py-3 font-medium">Item PR</th>
                                    <th scope="col" class="px-3 py-3 text-right font-medium">Dipesan</th>
                                    <th scope="col" class="px-3 py-3 text-right font-medium">Diterima</th>
                                    <th scope="col" class="px-3 py-3 text-right font-medium">Sisa</th>
                                    <th scope="col" class="px-3 py-3 font-medium">Status Penerimaan</th>
                                    <th scope="col" class="px-3 py-3 text-right font-medium">Harga Satuan</th>
                                    <th scope="col" class="px-3 py-3 text-right font-medium">Total Baris</th>
                                    <th scope="col" class="px-4 py-3 font-medium">Catatan</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($purchaseOrder->items as $item)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3">
                                            <p class="font-semibold text-gray-900">{{ $item->product?->name ?? '-' }}</p>
                                            <p class="text-xs text-gray-500">{{ $item->product?->code ?? '-' }}</p>
                                        </td>
                                        <td class="px-3 py-3 text-gray-600">{{ $item->inventoryLocation?->name ?? '—' }}</td>
                                        <td class="px-3 py-3 text-gray-600">
                                            @if ($item->purchaseRequestItem)
                                                {{ $item->purchaseRequestItem->id }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_quantity_id($item->quantity_ordered) }}</td>
                                        <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_quantity_id($item->quantity_received ?? 0) }}</td>
                                        <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_quantity_id($item->quantityRemaining()) }}</td>
                                        <td class="px-3 py-3">
                                            @include('inventory.purchase-orders._receiving-status-badge', [
                                                'status' => $item->receivingStatus(),
                                                'label' => $item->receivingStatusLabel(),
                                            ])
                                        </td>
                                        <td class="px-3 py-3 text-right tabular-nums text-gray-700">
                                            {{ $item->unit_price !== null ? format_currency_id($item->unit_price) : '—' }}
                                        </td>
                                        <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_currency_id($item->lineTotal()) }}</td>
                                        <td class="px-4 py-3 text-gray-600">{{ $item->notes ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="10" class="px-4 py-8 text-center text-sm text-gray-500">Belum ada item.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            @if ($purchaseOrder->items->isNotEmpty())
                                <tfoot class="bg-gray-50">
                                    <tr>
                                        <td colspan="8" class="px-4 py-3 text-right text-sm font-semibold text-gray-900">Total Pesanan</td>
                                        <td class="px-3 py-3 text-right text-sm font-semibold tabular-nums text-gray-900">{{ format_currency_id($purchaseOrder->total_amount) }}</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>

                    <div class="divide-y divide-gray-100 md:hidden">
                        @forelse ($purchaseOrder->items as $item)
                            <article class="p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="font-semibold text-gray-900">{{ $item->product?->name ?? '-' }}</p>
                                        <p class="text-xs text-gray-500">{{ $item->product?->code ?? '-' }}</p>
                                    </div>
                                    @include('inventory.purchase-orders._receiving-status-badge', [
                                        'status' => $item->receivingStatus(),
                                        'label' => $item->receivingStatusLabel(),
                                    ])
                                </div>
                                <div class="mt-3 grid grid-cols-1 gap-2 text-sm sm:grid-cols-3">
                                    <div class="rounded-lg bg-gray-50 p-2 ring-1 ring-gray-100">
                                        <p class="text-xs text-gray-500">Dipesan</p>
                                        <p class="mt-0.5 font-semibold tabular-nums text-gray-900">{{ format_quantity_id($item->quantity_ordered) }}</p>
                                    </div>
                                    <div class="rounded-lg bg-gray-50 p-2 ring-1 ring-gray-100">
                                        <p class="text-xs text-gray-500">Diterima</p>
                                        <p class="mt-0.5 font-semibold tabular-nums text-gray-900">{{ format_quantity_id($item->quantity_received ?? 0) }}</p>
                                    </div>
                                    <div class="rounded-lg bg-gray-50 p-2 ring-1 ring-gray-100">
                                        <p class="text-xs text-gray-500">Sisa</p>
                                        <p class="mt-0.5 font-semibold tabular-nums text-gray-900">{{ format_quantity_id($item->quantityRemaining()) }}</p>
                                    </div>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm text-gray-600">
                                    <span>Harga: {{ $item->unit_price !== null ? format_currency_id($item->unit_price) : '—' }}</span>
                                    <span>Subtotal: {{ format_currency_id($item->lineTotal()) }}</span>
                                </div>
                                @if ($item->notes)
                                    <p class="mt-2 text-sm text-gray-600">{{ $item->notes }}</p>
                                @endif
                            </article>
                        @empty
                            <div class="px-4 py-8 text-center text-sm text-gray-500">Belum ada item.</div>
                        @endforelse
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 px-4 py-3">
                        <h3 class="text-base font-semibold text-gray-900">Penerimaan Barang Terkait</h3>
                        <p class="mt-1 text-sm text-gray-500">Daftar penerimaan barang untuk pesanan pembelian ini dalam cabang aktif.</p>
                    </div>
                    <div class="hidden overflow-x-auto md:block">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr class="text-left text-gray-500">
                                    <th scope="col" class="px-4 py-3 font-medium">No. Penerimaan</th>
                                    <th scope="col" class="px-3 py-3 font-medium">Tanggal</th>
                                    <th scope="col" class="px-3 py-3 font-medium">Status</th>
                                    <th scope="col" class="px-3 py-3 font-medium">Surat Jalan</th>
                                    <th scope="col" class="px-4 py-3 font-medium">Dibuat oleh</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($purchaseOrder->goodsReceipts as $goodsReceipt)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3">
                                            @can('view', $goodsReceipt)
                                                <a href="{{ route('inventory.goods-receipts.show', $goodsReceipt) }}" class="font-semibold text-brand-700 hover:text-brand-600">
                                                    {{ $goodsReceipt->receipt_number }}
                                                </a>
                                            @else
                                                <span class="font-semibold text-gray-900">{{ $goodsReceipt->receipt_number }}</span>
                                            @endcan
                                        </td>
                                        <td class="px-3 py-3 tabular-nums text-gray-700">{{ format_date_id($goodsReceipt->receipt_date) }}</td>
                                        <td class="px-3 py-3">
                                            @include('inventory.goods-receipts._status-badge', ['status' => $goodsReceipt->status])
                                        </td>
                                        <td class="px-3 py-3 text-gray-600">{{ $goodsReceipt->supplier_delivery_number ?? '—' }}</td>
                                        <td class="px-4 py-3 text-gray-600">{{ $goodsReceipt->createdBy?->name ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">Belum ada penerimaan barang untuk pesanan ini.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="divide-y divide-gray-100 md:hidden">
                        @forelse ($purchaseOrder->goodsReceipts as $goodsReceipt)
                            <article class="p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        @can('view', $goodsReceipt)
                                            <a href="{{ route('inventory.goods-receipts.show', $goodsReceipt) }}" class="font-semibold text-brand-700 hover:text-brand-600">
                                                {{ $goodsReceipt->receipt_number }}
                                            </a>
                                        @else
                                            <p class="font-semibold text-gray-900">{{ $goodsReceipt->receipt_number }}</p>
                                        @endcan
                                        <p class="mt-1 text-sm text-gray-500">{{ format_date_id($goodsReceipt->receipt_date) }}</p>
                                    </div>
                                    @include('inventory.goods-receipts._status-badge', ['status' => $goodsReceipt->status])
                                </div>
                                <p class="mt-2 text-sm text-gray-600">Surat jalan: {{ $goodsReceipt->supplier_delivery_number ?? '—' }}</p>
                                <p class="mt-1 text-sm text-gray-600">Dibuat oleh: {{ $goodsReceipt->createdBy?->name ?? '—' }}</p>
                            </article>
                        @empty
                            <div class="px-4 py-8 text-center text-sm text-gray-500">Belum ada penerimaan barang untuk pesanan ini.</div>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="space-y-6">
                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <h3 class="text-base font-semibold text-gray-900">Informasi Pesanan</h3>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div>
                            <dt class="text-gray-500">Cabang</dt>
                            <dd class="font-medium text-gray-900">{{ $purchaseOrder->branch?->name ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Pemasok</dt>
                            <dd class="font-medium text-gray-900">{{ $purchaseOrder->displaySupplierName() }}</dd>
                        </div>
                        @if ($purchaseOrder->supplier_reference_number)
                            <div>
                                <dt class="text-gray-500">Referensi Supplier</dt>
                                <dd class="font-medium text-gray-900">{{ $purchaseOrder->supplier_reference_number }}</dd>
                            </div>
                        @endif
                        <div>
                            <dt class="text-gray-500">Mata Uang</dt>
                            <dd class="font-medium text-gray-900">{{ $purchaseOrder->currency }}</dd>
                        </div>
                        @if ($purchaseOrder->purchaseRequest)
                            <div>
                                <dt class="text-gray-500">Permintaan Pembelian</dt>
                                <dd>
                                    <a href="{{ route('inventory.purchase-requests.show', $purchaseOrder->purchaseRequest) }}" class="font-medium text-brand-700 hover:text-brand-600">
                                        {{ $purchaseOrder->purchaseRequest->purchase_request_number }}
                                    </a>
                                </dd>
                            </div>
                        @endif
                        @if ($purchaseOrder->expected_delivery_date)
                            <div>
                                <dt class="text-gray-500">Perkiraan Tanggal Kirim</dt>
                                <dd class="font-medium tabular-nums text-gray-900">{{ format_date_id($purchaseOrder->expected_delivery_date) }}</dd>
                            </div>
                        @endif
                        <div>
                            <dt class="text-gray-500">Dibuat oleh</dt>
                            <dd class="font-medium text-gray-900">{{ $purchaseOrder->createdBy?->name ?? '—' }}</dd>
                        </div>
                        @if ($purchaseOrder->notes)
                            <div>
                                <dt class="text-gray-500">Catatan</dt>
                                <dd class="text-gray-900">{{ $purchaseOrder->notes }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>

                @if ($purchaseOrder->submitted_at)
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 shadow-sm">
                        <h3 class="text-base font-semibold text-amber-900">Pengajuan</h3>
                        <dl class="mt-3 space-y-2 text-sm text-amber-800">
                            <div>
                                <dt class="font-medium">Diajukan oleh</dt>
                                <dd>{{ $purchaseOrder->submittedBy?->name ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="font-medium">Waktu pengajuan</dt>
                                <dd>{{ $purchaseOrder->submitted_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                            </div>
                        </dl>
                    </div>
                @endif

                @if ($purchaseOrder->approved_at)
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 shadow-sm">
                        <h3 class="text-base font-semibold text-emerald-900">Persetujuan</h3>
                        <dl class="mt-3 space-y-2 text-sm text-emerald-800">
                            <div>
                                <dt class="font-medium">Disetujui oleh</dt>
                                <dd>{{ $purchaseOrder->approvedBy?->name ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="font-medium">Waktu persetujuan</dt>
                                <dd>{{ $purchaseOrder->approved_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                            </div>
                        </dl>
                    </div>
                @endif

                @if ($purchaseOrder->sent_at)
                    <div class="rounded-lg border border-brand-200 bg-brand-50 p-4 shadow-sm">
                        <h3 class="text-base font-semibold text-brand-800">Pengiriman ke Supplier</h3>
                        <dl class="mt-3 space-y-2 text-sm text-brand-800">
                            <div>
                                <dt class="font-medium">Dikirim oleh</dt>
                                <dd>{{ $purchaseOrder->sentBy?->name ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="font-medium">Waktu pengiriman</dt>
                                <dd>{{ $purchaseOrder->sent_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                            </div>
                        </dl>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-settings-shell>
