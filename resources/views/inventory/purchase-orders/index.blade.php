@php
    use App\Modules\Inventory\Models\PurchaseOrder;

    $statusLabels = [
        PurchaseOrder::STATUS_DRAFT => 'Draft',
        PurchaseOrder::STATUS_SUBMITTED => 'Diajukan',
        PurchaseOrder::STATUS_APPROVED => 'Disetujui',
        PurchaseOrder::STATUS_SENT => 'Dikirim',
        PurchaseOrder::STATUS_CANCELLED => 'Dibatalkan',
    ];
@endphp

<x-settings-shell title="Pesanan Pembelian">
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Pesanan Pembelian Persediaan</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">Direktori Pesanan Pembelian</h2>
                <p class="mt-1 text-sm text-gray-500">Kelola pesanan pembelian ke supplier tanpa mengubah stok ledger.</p>
            </div>
            @can('create', PurchaseOrder::class)
                <a href="{{ route('inventory.purchase-orders.create') }}" class="inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Buat Pesanan Pembelian
                </a>
            @endcan
        </div>

        <form method="GET" action="{{ route('inventory.purchase-orders.index') }}" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_12rem_12rem_12rem_12rem_12rem_auto_auto] md:items-end">
                <div>
                    <label for="po-search" class="text-sm font-medium text-gray-700">Cari pesanan</label>
                    <input id="po-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Nomor, supplier, atau referensi"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div>
                    <label for="po-status" class="text-sm font-medium text-gray-700">Status</label>
                    <select id="po-status" name="status" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">Semua status</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') == $status)>{{ $statusLabels[$status] ?? $status }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="po-supplier" class="text-sm font-medium text-gray-700">Pemasok</label>
                    <select id="po-supplier" name="supplier_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">Semua pemasok</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected((string) ($filters['supplier_id'] ?? '') === (string) $supplier->id)>{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="po-date-from" class="text-sm font-medium text-gray-700">Dari tanggal</label>
                    <input id="po-date-from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div>
                    <label for="po-date-to" class="text-sm font-medium text-gray-700">Sampai tanggal</label>
                    <input id="po-date-to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                @if (! empty($filters['purchase_request_id']))
                    <input type="hidden" name="purchase_request_id" value="{{ $filters['purchase_request_id'] }}">
                @endif
                <button class="inline-flex justify-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-700 focus:ring-offset-2">
                    Terapkan
                </button>
                <a href="{{ route('inventory.purchase-orders.index') }}" class="inline-flex justify-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Atur Ulang
                </a>
            </div>
        </form>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Pesanan Pembelian</h3>
                    <p class="text-sm text-gray-500">{{ format_number_id($purchaseOrders->total()) }} pesanan dalam cabang aktif.</p>
                </div>
            </div>

            @if ($purchaseOrders->isEmpty())
                <div class="px-4 py-12 text-center">
                    <p class="text-sm font-medium text-gray-900">Belum ada pesanan pembelian.</p>
                    <p class="mt-1 text-sm text-gray-500">Buat pesanan baru untuk memesan material ke supplier.</p>
                    @can('create', PurchaseOrder::class)
                        <a href="{{ route('inventory.purchase-orders.create') }}" class="mt-4 inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600">
                            Buat Pesanan Pembelian
                        </a>
                    @endcan
                </div>
            @else
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-gray-500">
                                <th scope="col" class="px-4 py-3 font-medium">Nomor PO</th>
                                <th scope="col" class="px-4 py-3 font-medium">Tanggal</th>
                                <th scope="col" class="px-4 py-3 font-medium">Pemasok</th>
                                <th scope="col" class="px-4 py-3 font-medium">Referensi</th>
                                <th scope="col" class="px-4 py-3 font-medium">Mata Uang</th>
                                <th scope="col" class="px-4 py-3 font-medium text-right">Total</th>
                                <th scope="col" class="px-4 py-3 font-medium">Status</th>
                                <th scope="col" class="px-4 py-3 font-medium">PR Terkait</th>
                                <th scope="col" class="px-4 py-3 font-medium text-right">Jumlah Item</th>
                                <th scope="col" class="px-4 py-3 font-medium">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @foreach ($purchaseOrders as $purchaseOrder)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 font-medium text-gray-900">{{ $purchaseOrder->purchase_order_number }}</td>
                                    <td class="px-4 py-3 tabular-nums text-gray-700">{{ format_date_id($purchaseOrder->order_date) }}</td>
                                    <td class="px-4 py-3 text-gray-700">{{ $purchaseOrder->displaySupplierName() }}</td>
                                    <td class="px-4 py-3 text-gray-600">{{ $purchaseOrder->supplier_reference_number ?? '—' }}</td>
                                    <td class="px-4 py-3 text-gray-700">{{ $purchaseOrder->currency }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-gray-700">{{ format_currency_id($purchaseOrder->total_amount) }}</td>
                                    <td class="px-4 py-3">@include('inventory.purchase-orders._status-badge', ['status' => $purchaseOrder->status])</td>
                                    <td class="px-4 py-3 text-gray-600">
                                        @if ($purchaseOrder->purchaseRequest)
                                            <a href="{{ route('inventory.purchase-requests.show', $purchaseOrder->purchaseRequest) }}" class="font-medium text-teal-700 hover:text-teal-600">
                                                {{ $purchaseOrder->purchaseRequest->purchase_request_number }}
                                            </a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums text-gray-700">{{ format_number_id($purchaseOrder->items->count()) }}</td>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('inventory.purchase-orders.show', $purchaseOrder) }}" class="text-sm font-medium text-teal-700 hover:text-teal-600">Detail</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="divide-y divide-gray-100 md:hidden">
                    @foreach ($purchaseOrders as $purchaseOrder)
                        <article class="p-4">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <h4 class="font-semibold text-gray-900">{{ $purchaseOrder->purchase_order_number }}</h4>
                                @include('inventory.purchase-orders._status-badge', ['status' => $purchaseOrder->status])
                            </div>
                            <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                                <div>
                                    <dt class="text-gray-500">Tanggal</dt>
                                    <dd class="font-medium tabular-nums text-gray-900">{{ format_date_id($purchaseOrder->order_date) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500">Pemasok</dt>
                                    <dd class="font-medium text-gray-900">{{ $purchaseOrder->displaySupplierName() }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500">Mata Uang</dt>
                                    <dd class="font-medium text-gray-900">{{ $purchaseOrder->currency }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500">Total</dt>
                                    <dd class="font-medium tabular-nums text-gray-900">{{ format_currency_id($purchaseOrder->total_amount) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500">Jumlah Item</dt>
                                    <dd class="font-medium tabular-nums text-gray-900">{{ format_number_id($purchaseOrder->items->count()) }}</dd>
                                </div>
                                @if ($purchaseOrder->purchaseRequest)
                                    <div class="col-span-2">
                                        <dt class="text-gray-500">PR Terkait</dt>
                                        <dd class="font-medium text-teal-700">{{ $purchaseOrder->purchaseRequest->purchase_request_number }}</dd>
                                    </div>
                                @endif
                            </dl>
                            <a href="{{ route('inventory.purchase-orders.show', $purchaseOrder) }}" class="mt-3 inline-flex text-sm font-medium text-teal-700 hover:text-teal-600">Lihat detail</a>
                        </article>
                    @endforeach
                </div>

                @if ($purchaseOrders->hasPages())
                    <div class="border-t border-gray-200 px-4 py-3">
                        {{ $purchaseOrders->links() }}
                    </div>
                @endif
            @endif
        </section>
    </div>
</x-settings-shell>
