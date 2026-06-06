@php
    use App\Modules\Inventory\Models\PurchaseOrder;
@endphp

<x-settings-shell title="Pesanan Pembelian {{ $purchaseOrder->purchase_order_number }}">
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Detail Pesanan Pembelian</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">{{ $purchaseOrder->purchase_order_number }}</h2>
                <p class="mt-1 text-sm text-gray-500">
                    {{ format_date_id($purchaseOrder->order_date) }}
                    · @include('inventory.purchase-orders._status-badge', ['status' => $purchaseOrder->status])
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @can('update', $purchaseOrder)
                    <a href="{{ route('inventory.purchase-orders.edit', $purchaseOrder) }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                        Ubah
                    </a>
                @endcan

                @can('submit', $purchaseOrder)
                    <form method="POST" action="{{ route('inventory.purchase-orders.submit', $purchaseOrder) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center rounded-lg bg-yellow-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-yellow-500 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2">
                            Ajukan
                        </button>
                    </form>
                @endcan

                @can('approve', $purchaseOrder)
                    <form method="POST" action="{{ route('inventory.purchase-orders.approve', $purchaseOrder) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                            Setujui
                        </button>
                    </form>
                @endcan

                @can('send', $purchaseOrder)
                    <form method="POST" action="{{ route('inventory.purchase-orders.send', $purchaseOrder) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                            Kirim ke Supplier
                        </button>
                    </form>
                @endcan

                @can('receive', $purchaseOrder)
                    <a href="{{ route('inventory.goods-receipts.create', ['purchase_order_id' => $purchaseOrder->id]) }}"
                       class="inline-flex items-center rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                        Terima Barang
                    </a>
                @endcan

                @can('cancel', $purchaseOrder)
                    <form method="POST" action="{{ route('inventory.purchase-orders.cancel', $purchaseOrder) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center rounded-lg bg-gray-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                            Batalkan
                        </button>
                    </form>
                @endcan

                <a href="{{ route('inventory.purchase-orders.index') }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Kembali
                </a>
            </div>
        </div>

        <div class="rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-800">
            <p class="font-semibold">Tidak menambah stok</p>
            <p class="mt-1">Pesanan pembelian tidak menambah stok. Stok bertambah hanya melalui penerimaan barang.</p>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 px-4 py-3">
                        <h3 class="text-base font-semibold text-gray-900">Item Pesanan</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr class="text-left text-gray-500">
                                    <th scope="col" class="px-4 py-3 font-medium">Produk</th>
                                    <th scope="col" class="px-3 py-3 font-medium">Lokasi</th>
                                    <th scope="col" class="px-3 py-3 font-medium">Item PR</th>
                                    <th scope="col" class="px-3 py-3 text-right font-medium">Jumlah</th>
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
                                        <td class="px-3 py-3 text-right tabular-nums text-gray-700">
                                            {{ $item->unit_price !== null ? format_currency_id($item->unit_price) : '—' }}
                                        </td>
                                        <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_currency_id($item->lineTotal()) }}</td>
                                        <td class="px-4 py-3 text-gray-600">{{ $item->notes ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-500">Belum ada item.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            @if ($purchaseOrder->items->isNotEmpty())
                                <tfoot class="bg-gray-50">
                                    <tr>
                                        <td colspan="5" class="px-4 py-3 text-right text-sm font-semibold text-gray-900">Total Pesanan</td>
                                        <td class="px-3 py-3 text-right text-sm font-semibold tabular-nums text-gray-900">{{ format_currency_id($purchaseOrder->total_amount) }}</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
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
                                    <a href="{{ route('inventory.purchase-requests.show', $purchaseOrder->purchaseRequest) }}" class="font-medium text-teal-700 hover:text-teal-600">
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
                    <div class="rounded-lg border border-yellow-200 bg-yellow-50 p-4 shadow-sm">
                        <h3 class="text-base font-semibold text-yellow-900">Pengajuan</h3>
                        <dl class="mt-3 space-y-2 text-sm text-yellow-800">
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
                    <div class="rounded-lg border border-green-200 bg-green-50 p-4 shadow-sm">
                        <h3 class="text-base font-semibold text-green-900">Persetujuan</h3>
                        <dl class="mt-3 space-y-2 text-sm text-green-800">
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
                    <div class="rounded-lg border border-teal-200 bg-teal-50 p-4 shadow-sm">
                        <h3 class="text-base font-semibold text-teal-900">Pengiriman ke Supplier</h3>
                        <dl class="mt-3 space-y-2 text-sm text-teal-800">
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
