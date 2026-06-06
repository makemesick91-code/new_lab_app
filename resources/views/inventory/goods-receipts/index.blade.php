@php
    use App\Modules\Inventory\Models\GoodsReceipt;

    $statusLabels = [
        GoodsReceipt::STATUS_DRAFT => 'Draft',
        GoodsReceipt::STATUS_SUBMITTED => 'Diajukan',
        GoodsReceipt::STATUS_POSTED => 'Diposting',
        GoodsReceipt::STATUS_CANCELLED => 'Dibatalkan',
    ];
@endphp

<x-settings-shell title="Penerimaan Barang">
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Penerimaan Barang Persediaan</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">Penerimaan Barang</h2>
                <p class="mt-1 text-sm text-gray-500">Kelola penerimaan barang dari pesanan pembelian. Stok bertambah saat dokumen diposting.</p>
            </div>
            @can('create', GoodsReceipt::class)
                <a href="{{ route('inventory.goods-receipts.create') }}" class="inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Buat Penerimaan Barang
                </a>
            @endcan
        </div>

        <form method="GET" action="{{ route('inventory.goods-receipts.index') }}" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_12rem_12rem_12rem_12rem_auto_auto] md:items-end">
                <div>
                    <label for="gr-search" class="text-sm font-medium text-gray-700">Pencarian No. Penerimaan</label>
                    <input id="gr-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Nomor penerimaan atau PO"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div>
                    <label for="gr-status" class="text-sm font-medium text-gray-700">Status</label>
                    <select id="gr-status" name="status" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">Semua status</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') == $status)>{{ $statusLabels[$status] ?? $status }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="gr-po" class="text-sm font-medium text-gray-700">Purchase Order</label>
                    <select id="gr-po" name="purchase_order_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">Semua PO</option>
                        @foreach ($purchaseOrders as $purchaseOrder)
                            <option value="{{ $purchaseOrder->id }}" @selected((string) ($filters['purchase_order_id'] ?? '') === (string) $purchaseOrder->id)>{{ $purchaseOrder->purchase_order_number }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="gr-date-from" class="text-sm font-medium text-gray-700">Tanggal Dari</label>
                    <input id="gr-date-from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div>
                    <label for="gr-date-to" class="text-sm font-medium text-gray-700">Tanggal Sampai</label>
                    <input id="gr-date-to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <button class="inline-flex justify-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-700 focus:ring-offset-2">
                    Terapkan
                </button>
                <a href="{{ route('inventory.goods-receipts.index') }}" class="inline-flex justify-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Atur Ulang
                </a>
            </div>
        </form>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Daftar Penerimaan Barang</h3>
                    <p class="text-sm text-gray-500">{{ format_number_id($goodsReceipts->total()) }} dokumen dalam cabang aktif.</p>
                </div>
            </div>

            @if ($goodsReceipts->isEmpty())
                <div class="px-4 py-12 text-center">
                    <p class="text-sm font-medium text-gray-900">Belum ada penerimaan barang.</p>
                    <p class="mt-1 text-sm text-gray-500">Buat penerimaan baru dari pesanan pembelian yang dapat diterima.</p>
                    @can('create', GoodsReceipt::class)
                        <a href="{{ route('inventory.goods-receipts.create') }}" class="mt-4 inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600">
                            Buat Penerimaan Barang
                        </a>
                    @endcan
                </div>
            @else
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-gray-500">
                                <th scope="col" class="px-4 py-3 font-medium">No. Penerimaan</th>
                                <th scope="col" class="px-4 py-3 font-medium">Tanggal</th>
                                <th scope="col" class="px-4 py-3 font-medium">Purchase Order</th>
                                <th scope="col" class="px-4 py-3 font-medium">Supplier</th>
                                <th scope="col" class="px-4 py-3 font-medium">Cabang</th>
                                <th scope="col" class="px-4 py-3 font-medium">Status</th>
                                <th scope="col" class="px-4 py-3 font-medium">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @foreach ($goodsReceipts as $goodsReceipt)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 font-medium text-gray-900">{{ $goodsReceipt->receipt_number }}</td>
                                    <td class="px-4 py-3 tabular-nums text-gray-700">{{ format_date_id($goodsReceipt->receipt_date) }}</td>
                                    <td class="px-4 py-3 text-gray-700">
                                        @if ($goodsReceipt->purchaseOrder)
                                            <a href="{{ route('inventory.purchase-orders.show', $goodsReceipt->purchaseOrder) }}" class="font-medium text-teal-700 hover:text-teal-600">
                                                {{ $goodsReceipt->purchaseOrder->purchase_order_number }}
                                            </a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-gray-700">{{ $goodsReceipt->purchaseOrder?->displaySupplierName() ?? '—' }}</td>
                                    <td class="px-4 py-3 text-gray-700">{{ $goodsReceipt->branch?->name ?? '—' }}</td>
                                    <td class="px-4 py-3">@include('inventory.goods-receipts._status-badge', ['status' => $goodsReceipt->status])</td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <a href="{{ route('inventory.goods-receipts.show', $goodsReceipt) }}" class="text-sm font-medium text-teal-700 hover:text-teal-600">Lihat</a>
                                            @can('update', $goodsReceipt)
                                                <a href="{{ route('inventory.goods-receipts.edit', $goodsReceipt) }}" class="text-sm font-medium text-gray-700 hover:text-gray-900">Edit</a>
                                            @endcan
                                            @can('submit', $goodsReceipt)
                                                <form method="POST" action="{{ route('inventory.goods-receipts.submit', $goodsReceipt) }}" class="inline">
                                                    @csrf
                                                    <button type="submit" class="text-sm font-medium text-yellow-700 hover:text-yellow-600">Submit</button>
                                                </form>
                                            @endcan
                                            @can('post', $goodsReceipt)
                                                <button type="button" class="text-sm font-medium text-teal-700 hover:text-teal-600" x-data @click="$dispatch('open-modal', 'confirm-post-gr-{{ $goodsReceipt->id }}')">Posting</button>
                                            @endcan
                                            @can('cancel', $goodsReceipt)
                                                <button type="button" class="text-sm font-medium text-red-700 hover:text-red-600" x-data @click="$dispatch('open-modal', 'confirm-cancel-gr-{{ $goodsReceipt->id }}')">Batalkan</button>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="divide-y divide-gray-100 md:hidden">
                    @foreach ($goodsReceipts as $goodsReceipt)
                        <article class="p-4">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <h4 class="font-semibold text-gray-900">{{ $goodsReceipt->receipt_number }}</h4>
                                @include('inventory.goods-receipts._status-badge', ['status' => $goodsReceipt->status])
                            </div>
                            <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                                <div>
                                    <dt class="text-gray-500">Tanggal</dt>
                                    <dd class="font-medium tabular-nums text-gray-900">{{ format_date_id($goodsReceipt->receipt_date) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500">Purchase Order</dt>
                                    <dd class="font-medium text-gray-900">{{ $goodsReceipt->purchaseOrder?->purchase_order_number ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500">Supplier</dt>
                                    <dd class="font-medium text-gray-900">{{ $goodsReceipt->purchaseOrder?->displaySupplierName() ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500">Cabang</dt>
                                    <dd class="font-medium text-gray-900">{{ $goodsReceipt->branch?->name ?? '—' }}</dd>
                                </div>
                            </dl>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <a href="{{ route('inventory.goods-receipts.show', $goodsReceipt) }}" class="text-sm font-medium text-teal-700 hover:text-teal-600">Lihat</a>
                                @can('update', $goodsReceipt)
                                    <a href="{{ route('inventory.goods-receipts.edit', $goodsReceipt) }}" class="text-sm font-medium text-gray-700 hover:text-gray-900">Edit</a>
                                @endcan
                            </div>
                        </article>
                    @endforeach
                </div>

                @foreach ($goodsReceipts as $goodsReceipt)
                    @can('post', $goodsReceipt)
                        <x-modal name="confirm-post-gr-{{ $goodsReceipt->id }}" focusable>
                            <div class="p-6">
                                <h3 class="text-lg font-semibold text-gray-900">Konfirmasi Posting</h3>
                                <p class="mt-3 text-sm text-gray-600">Posting penerimaan barang akan menambah stok melalui ledger inventory dan tidak dapat diedit kembali.</p>
                                <div class="mt-6 flex flex-wrap justify-end gap-3">
                                    <button type="button" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50" @click="$dispatch('close-modal', 'confirm-post-gr-{{ $goodsReceipt->id }}')">Batal</button>
                                    <form method="POST" action="{{ route('inventory.goods-receipts.post', $goodsReceipt) }}">
                                        @csrf
                                        <button type="submit" class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-600">Ya, Posting Penerimaan</button>
                                    </form>
                                </div>
                            </div>
                        </x-modal>
                    @endcan

                    @can('cancel', $goodsReceipt)
                        <x-modal name="confirm-cancel-gr-{{ $goodsReceipt->id }}" focusable>
                            <div class="p-6">
                                <h3 class="text-lg font-semibold text-gray-900">Konfirmasi Pembatalan</h3>
                                <p class="mt-3 text-sm text-gray-600">Batalkan draft penerimaan barang ini?</p>
                                <div class="mt-6 flex flex-wrap justify-end gap-3">
                                    <button type="button" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50" @click="$dispatch('close-modal', 'confirm-cancel-gr-{{ $goodsReceipt->id }}')">Kembali</button>
                                    <form method="POST" action="{{ route('inventory.goods-receipts.cancel', $goodsReceipt) }}">
                                        @csrf
                                        <button type="submit" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-500">Ya, Batalkan</button>
                                    </form>
                                </div>
                            </div>
                        </x-modal>
                    @endcan
                @endforeach

                @if ($goodsReceipts->hasPages())
                    <div class="border-t border-gray-200 px-4 py-3">
                        {{ $goodsReceipts->links() }}
                    </div>
                @endif
            @endif
        </section>
    </div>
</x-settings-shell>
