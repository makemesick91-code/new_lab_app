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
        <x-ui.page-header
            title="Penerimaan Barang"
            subtitle="Kelola penerimaan barang dari pesanan pembelian. Stok bertambah saat dokumen diposting.">
            <x-slot:breadcrumb>Persediaan / Penerimaan Barang</x-slot:breadcrumb>
            @can('create', GoodsReceipt::class)
                <x-slot:actions>
                    <x-ui.button variant="primary" :href="route('inventory.goods-receipts.create')">Buat Penerimaan Barang</x-ui.button>
                </x-slot:actions>
            @endcan
        </x-ui.page-header>

        <form method="GET" action="{{ route('inventory.goods-receipts.index') }}" class="rounded-lg border border-hairline bg-white p-4 shadow-sm">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4 xl:items-end">
                <div>
                    <label for="gr-search" class="mb-1 block text-sm font-medium text-ink">Pencarian No. Penerimaan</label>
                    <input id="gr-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Nomor penerimaan atau PO"
                           class="block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                </div>
                <div>
                    <label for="gr-status" class="mb-1 block text-sm font-medium text-ink">Status</label>
                    <select id="gr-status" name="status" class="block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Semua status</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') == $status)>{{ $statusLabels[$status] ?? $status }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="gr-po" class="mb-1 block text-sm font-medium text-ink">Purchase Order</label>
                    <select id="gr-po" name="purchase_order_id" class="block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Semua PO</option>
                        @foreach ($purchaseOrders as $purchaseOrder)
                            <option value="{{ $purchaseOrder->id }}" @selected((string) ($filters['purchase_order_id'] ?? '') === (string) $purchaseOrder->id)>{{ $purchaseOrder->purchase_order_number }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="gr-date-from" class="mb-1 block text-sm font-medium text-ink">Tanggal Dari</label>
                    <input id="gr-date-from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"
                           class="block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                </div>
                <div>
                    <label for="gr-date-to" class="mb-1 block text-sm font-medium text-ink">Tanggal Sampai</label>
                    <input id="gr-date-to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"
                           class="block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                </div>
                @include('inventory._filter-actions', ['resetUrl' => route('inventory.goods-receipts.index')])
            </div>
        </form>

        <section class="rounded-lg border border-hairline bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-4 py-3">
                <div>
                    <h3 class="text-base font-semibold text-navy">Daftar Penerimaan Barang</h3>
                    <p class="text-sm text-ink-soft">{{ format_number_id($goodsReceipts->total()) }} dokumen dalam cabang aktif.</p>
                </div>
            </div>

            @if ($goodsReceipts->isEmpty())
                <div class="px-4 py-12 text-center">
                    <p class="text-sm font-medium text-navy">Belum ada penerimaan barang.</p>
                    <p class="mt-1 text-sm text-ink-soft">Buat penerimaan baru dari pesanan pembelian yang dapat diterima.</p>
                    @can('create', GoodsReceipt::class)
                        <a href="{{ route('inventory.goods-receipts.create') }}" class="mt-4 inline-flex items-center rounded-lg bg-brand-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-600">
                            Buat Penerimaan Barang
                        </a>
                    @endcan
                </div>
            @else
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-hairline text-sm">
                        <thead class="bg-navy-50">
                            <tr class="text-left text-ink-soft">
                                <th scope="col" class="px-4 py-3 font-medium">No. Penerimaan</th>
                                <th scope="col" class="px-4 py-3 font-medium">Tanggal</th>
                                <th scope="col" class="px-4 py-3 font-medium">Purchase Order</th>
                                <th scope="col" class="px-4 py-3 font-medium">Supplier</th>
                                <th scope="col" class="px-4 py-3 font-medium">Cabang</th>
                                <th scope="col" class="px-4 py-3 font-medium">Status</th>
                                <th scope="col" class="px-4 py-3 font-medium">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-hairline bg-white">
                            @foreach ($goodsReceipts as $goodsReceipt)
                                <tr class="hover:bg-navy-50">
                                    <td class="px-4 py-3 font-medium text-navy">{{ $goodsReceipt->receipt_number }}</td>
                                    <td class="px-4 py-3 tabular-nums text-ink">{{ format_date_id($goodsReceipt->receipt_date) }}</td>
                                    <td class="px-4 py-3 text-ink">
                                        @if ($goodsReceipt->purchaseOrder)
                                            <a href="{{ route('inventory.purchase-orders.show', $goodsReceipt->purchaseOrder) }}" class="font-medium text-brand-700 hover:text-brand-600">
                                                {{ $goodsReceipt->purchaseOrder->purchase_order_number }}
                                            </a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-ink">{{ $goodsReceipt->purchaseOrder?->displaySupplierName() ?? '—' }}</td>
                                    <td class="px-4 py-3 text-ink">{{ $goodsReceipt->branch?->name ?? '—' }}</td>
                                    <td class="px-4 py-3">@include('inventory.goods-receipts._status-badge', ['status' => $goodsReceipt->status])</td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <a href="{{ route('inventory.goods-receipts.show', $goodsReceipt) }}" class="text-sm font-medium text-brand-700 hover:text-brand-600">Lihat</a>
                                            @can('update', $goodsReceipt)
                                                <a href="{{ route('inventory.goods-receipts.edit', $goodsReceipt) }}" class="text-sm font-medium text-ink hover:text-navy">Edit</a>
                                            @endcan
                                            @can('submit', $goodsReceipt)
                                                <form method="POST" action="{{ route('inventory.goods-receipts.submit', $goodsReceipt) }}" class="inline">
                                                    @csrf
                                                    <button type="submit" class="text-sm font-medium text-yellow-700 hover:text-yellow-600">Submit</button>
                                                </form>
                                            @endcan
                                            @can('post', $goodsReceipt)
                                                <button type="button" class="text-sm font-medium text-brand-700 hover:text-brand-600" x-data @click="$dispatch('open-modal', 'confirm-post-gr-{{ $goodsReceipt->id }}')">Posting</button>
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

                <div class="divide-y divide-hairline md:hidden">
                    @foreach ($goodsReceipts as $goodsReceipt)
                        <article class="p-4">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <h4 class="font-semibold text-navy">{{ $goodsReceipt->receipt_number }}</h4>
                                @include('inventory.goods-receipts._status-badge', ['status' => $goodsReceipt->status])
                            </div>
                            <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                                <div>
                                    <dt class="text-ink-soft">Tanggal</dt>
                                    <dd class="font-medium tabular-nums text-navy">{{ format_date_id($goodsReceipt->receipt_date) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-ink-soft">Purchase Order</dt>
                                    <dd class="font-medium text-navy">{{ $goodsReceipt->purchaseOrder?->purchase_order_number ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-ink-soft">Supplier</dt>
                                    <dd class="font-medium text-navy">{{ $goodsReceipt->purchaseOrder?->displaySupplierName() ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-ink-soft">Cabang</dt>
                                    <dd class="font-medium text-navy">{{ $goodsReceipt->branch?->name ?? '—' }}</dd>
                                </div>
                            </dl>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <a href="{{ route('inventory.goods-receipts.show', $goodsReceipt) }}" class="text-sm font-medium text-brand-700 hover:text-brand-600">Lihat</a>
                                @can('update', $goodsReceipt)
                                    <a href="{{ route('inventory.goods-receipts.edit', $goodsReceipt) }}" class="text-sm font-medium text-ink hover:text-navy">Edit</a>
                                @endcan
                            </div>
                        </article>
                    @endforeach
                </div>

                @foreach ($goodsReceipts as $goodsReceipt)
                    @can('post', $goodsReceipt)
                        <x-modal name="confirm-post-gr-{{ $goodsReceipt->id }}" focusable>
                            <div class="p-6">
                                <h3 class="text-lg font-semibold text-navy">Konfirmasi Posting</h3>
                                <p class="mt-3 text-sm text-ink-soft">Posting penerimaan barang akan menambah stok melalui ledger inventory dan tidak dapat diedit kembali.</p>
                                <div class="mt-6 flex flex-wrap justify-end gap-3">
                                    <button type="button" class="rounded-lg border border-hairline px-4 py-2 text-sm font-semibold text-ink-soft hover:bg-navy-50" @click="$dispatch('close-modal', 'confirm-post-gr-{{ $goodsReceipt->id }}')">Batal</button>
                                    <form method="POST" action="{{ route('inventory.goods-receipts.post', $goodsReceipt) }}">
                                        @csrf
                                        <button type="submit" class="rounded-lg bg-brand-700 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600">Ya, Posting Penerimaan</button>
                                    </form>
                                </div>
                            </div>
                        </x-modal>
                    @endcan

                    @can('cancel', $goodsReceipt)
                        <x-modal name="confirm-cancel-gr-{{ $goodsReceipt->id }}" focusable>
                            <div class="p-6">
                                <h3 class="text-lg font-semibold text-navy">Konfirmasi Pembatalan</h3>
                                <p class="mt-3 text-sm text-ink-soft">Batalkan draft penerimaan barang ini?</p>
                                <div class="mt-6 flex flex-wrap justify-end gap-3">
                                    <button type="button" class="rounded-lg border border-hairline px-4 py-2 text-sm font-semibold text-ink-soft hover:bg-navy-50" @click="$dispatch('close-modal', 'confirm-cancel-gr-{{ $goodsReceipt->id }}')">Kembali</button>
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
                    <div class="border-t border-hairline px-4 py-3">
                        {{ $goodsReceipts->links() }}
                    </div>
                @endif
            @endif
        </section>
    </div>
</x-settings-shell>
