@php
    use App\Modules\Inventory\Models\PurchaseOrder;
    use App\Modules\Inventory\Models\PurchaseOrderItem;

    $statusLabels = [
        PurchaseOrder::STATUS_DRAFT => 'Draft',
        PurchaseOrder::STATUS_SUBMITTED => 'Diajukan',
        PurchaseOrder::STATUS_APPROVED => 'Disetujui',
        PurchaseOrder::STATUS_SENT => 'Dikirim',
        PurchaseOrder::STATUS_PARTIALLY_RECEIVED => 'Diterima Sebagian',
        PurchaseOrder::STATUS_FULLY_RECEIVED => 'Diterima Lengkap',
        PurchaseOrder::STATUS_CANCELLED => 'Dibatalkan',
    ];

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

<x-settings-shell title="Pesanan Pembelian">
    <div class="space-y-6">
        <x-ui.page-header
            title="Direktori Pesanan Pembelian"
            subtitle="Kelola pesanan pembelian ke supplier tanpa mengubah stok ledger.">
            <x-slot:breadcrumb>Persediaan / Pesanan Pembelian</x-slot:breadcrumb>
            @can('create', PurchaseOrder::class)
                <x-slot:actions>
                    <x-ui.button variant="primary" :href="route('inventory.purchase-orders.create')">Buat Pesanan Pembelian</x-ui.button>
                </x-slot:actions>
            @endcan
        </x-ui.page-header>

        <form method="GET" action="{{ route('inventory.purchase-orders.index') }}" class="rounded-lg border border-hairline bg-white p-4 shadow-sm">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4 xl:items-end">
                <div>
                    <label for="po-search" class="mb-1 block text-sm font-medium text-ink">Cari pesanan</label>
                    <input id="po-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Nomor, supplier, atau referensi"
                           class="block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                </div>
                <div>
                    <label for="po-status" class="mb-1 block text-sm font-medium text-ink">Status</label>
                    <select id="po-status" name="status" class="block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Semua status</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') == $status)>{{ $statusLabels[$status] ?? $status }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="po-supplier" class="mb-1 block text-sm font-medium text-ink">Pemasok</label>
                    <select id="po-supplier" name="supplier_id" class="block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Semua pemasok</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected((string) ($filters['supplier_id'] ?? '') === (string) $supplier->id)>{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="po-date-from" class="mb-1 block text-sm font-medium text-ink">Dari tanggal</label>
                    <input id="po-date-from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"
                           class="block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                </div>
                <div>
                    <label for="po-date-to" class="mb-1 block text-sm font-medium text-ink">Sampai tanggal</label>
                    <input id="po-date-to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"
                           class="block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                </div>
                @if (! empty($filters['purchase_request_id']))
                    <input type="hidden" name="purchase_request_id" value="{{ $filters['purchase_request_id'] }}">
                @endif
                @include('inventory._filter-actions', ['resetUrl' => route('inventory.purchase-orders.index')])
            </div>
        </form>

        <section class="rounded-lg border border-hairline bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-4 py-3">
                <div>
                    <h3 class="text-base font-semibold text-navy">Pesanan Pembelian</h3>
                    <p class="text-sm text-ink-soft">{{ format_number_id($purchaseOrders->total()) }} pesanan dalam cabang aktif.</p>
                </div>
            </div>

            <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-hairline text-sm">
                    <thead class="bg-navy-50">
                        <tr class="text-left text-ink-soft">
                            <th scope="col" class="px-4 py-3 font-medium">Nomor PO</th>
                            <th scope="col" class="px-3 py-3 font-medium">Pemasok</th>
                            <th scope="col" class="px-3 py-3 font-medium">Tanggal</th>
                            <th scope="col" class="px-3 py-3 font-medium">Status PO</th>
                            <th scope="col" class="px-3 py-3 font-medium">Status Penerimaan</th>
                            <th scope="col" class="px-3 py-3 text-right font-medium">Item</th>
                            <th scope="col" class="px-3 py-3 text-right font-medium">Total</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @forelse ($purchaseOrders as $purchaseOrder)
                            <tr class="hover:bg-navy-50">
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-navy">{{ $purchaseOrder->purchase_order_number }}</p>
                                    @if ($purchaseOrder->supplier_reference_number)
                                        <p class="mt-0.5 text-xs text-ink-soft">Ref: {{ $purchaseOrder->supplier_reference_number }}</p>
                                    @endif
                                    @if ($purchaseOrder->purchaseRequest)
                                        <p class="mt-0.5 text-xs">
                                            <a href="{{ route('inventory.purchase-requests.show', $purchaseOrder->purchaseRequest) }}" class="font-medium text-brand-700 hover:text-brand-600">
                                                PR {{ $purchaseOrder->purchaseRequest->purchase_request_number }}
                                            </a>
                                        </p>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-ink">{{ $purchaseOrder->displaySupplierName() }}</td>
                                <td class="px-3 py-3 tabular-nums text-ink-soft">{{ format_date_id($purchaseOrder->order_date) }}</td>
                                <td class="px-3 py-3">@include('inventory.purchase-orders._status-badge', ['status' => $purchaseOrder->status])</td>
                                <td class="px-3 py-3">
                                    @if (isset($receivingStatuses[$purchaseOrder->status]))
                                        @include('inventory.purchase-orders._receiving-status-badge', $receivingStatuses[$purchaseOrder->status])
                                    @else
                                        <span class="text-xs text-ink-muted">—</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums text-ink">{{ format_number_id($purchaseOrder->items->count()) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums text-ink">
                                    <span class="text-xs text-ink-soft">{{ $purchaseOrder->currency }}</span>
                                    {{ format_currency_id($purchaseOrder->total_amount) }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('inventory.purchase-orders.show', $purchaseOrder) }}" class="font-medium text-brand-700 hover:text-brand-600">Detail</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-12">
                                    <div class="mx-auto max-w-sm text-center">
                                        <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-navy-50 text-ink-muted">
                                            <span class="text-lg font-semibold">0</span>
                                        </div>
                                        <p class="mt-3 text-sm font-medium text-navy">Belum ada pesanan pembelian.</p>
                                        <p class="mt-1 text-sm text-ink-soft">Buat pesanan baru untuk memesan material ke supplier.</p>
                                        @can('create', PurchaseOrder::class)
                                            <a href="{{ route('inventory.purchase-orders.create') }}" class="mt-4 inline-flex items-center rounded-lg bg-brand-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-600">
                                                Buat Pesanan Pembelian
                                            </a>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-hairline md:hidden">
                @forelse ($purchaseOrders as $purchaseOrder)
                    <article class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-xs font-medium uppercase tracking-wide text-ink-soft">{{ $purchaseOrder->purchase_order_number }}</p>
                                <h3 class="mt-1 text-base font-semibold text-navy">{{ $purchaseOrder->displaySupplierName() }}</h3>
                                <p class="mt-1 text-sm text-ink-soft">{{ format_date_id($purchaseOrder->order_date) }}</p>
                            </div>
                            @include('inventory.purchase-orders._status-badge', ['status' => $purchaseOrder->status])
                        </div>
                        <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div class="rounded-lg bg-navy-50 p-3 ring-1 ring-hairline">
                                <p class="text-xs text-ink-soft">Jumlah Item</p>
                                <p class="mt-1 font-semibold tabular-nums text-navy">{{ format_number_id($purchaseOrder->items->count()) }}</p>
                            </div>
                            <div class="rounded-lg bg-navy-50 p-3 ring-1 ring-hairline">
                                <p class="text-xs text-ink-soft">Total ({{ $purchaseOrder->currency }})</p>
                                <p class="mt-1 font-semibold tabular-nums text-navy">{{ format_currency_id($purchaseOrder->total_amount) }}</p>
                            </div>
                        </div>
                        @if (isset($receivingStatuses[$purchaseOrder->status]))
                            <div class="mt-3">
                                @include('inventory.purchase-orders._receiving-status-badge', $receivingStatuses[$purchaseOrder->status])
                            </div>
                        @endif
                        @if ($purchaseOrder->purchaseRequest)
                            <p class="mt-2 text-xs text-ink-soft">
                                PR:
                                <a href="{{ route('inventory.purchase-requests.show', $purchaseOrder->purchaseRequest) }}" class="font-medium text-brand-700">
                                    {{ $purchaseOrder->purchaseRequest->purchase_request_number }}
                                </a>
                            </p>
                        @endif
                        <div class="mt-4">
                            <a href="{{ route('inventory.purchase-orders.show', $purchaseOrder) }}" class="rounded-lg border border-brand-200 px-3 py-2 text-sm font-medium text-brand-700">Lihat detail</a>
                        </div>
                    </article>
                @empty
                    <div class="px-4 py-10 text-center">
                        <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-navy-50 text-ink-muted">
                            <span class="text-lg font-semibold">0</span>
                        </div>
                        <p class="mt-3 text-sm font-medium text-navy">Belum ada pesanan pembelian.</p>
                        <p class="mt-1 text-sm text-ink-soft">Buat pesanan baru untuk memesan material ke supplier.</p>
                        @can('create', PurchaseOrder::class)
                            <a href="{{ route('inventory.purchase-orders.create') }}" class="mt-4 inline-flex items-center rounded-lg bg-brand-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-600">
                                Buat Pesanan Pembelian
                            </a>
                        @endcan
                    </div>
                @endforelse
            </div>

            @if ($purchaseOrders->hasPages())
                <div class="border-t border-hairline px-4 py-3">
                    {{ $purchaseOrders->links() }}
                </div>
            @endif
        </section>
    </div>
</x-settings-shell>
