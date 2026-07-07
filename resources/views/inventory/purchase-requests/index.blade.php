@php
    use App\Modules\Inventory\Models\PurchaseRequest;

    $statusLabels = [
        PurchaseRequest::STATUS_DRAFT => 'Draft',
        PurchaseRequest::STATUS_SUBMITTED => 'Diajukan',
        PurchaseRequest::STATUS_APPROVED => 'Disetujui',
        PurchaseRequest::STATUS_REJECTED => 'Ditolak',
        PurchaseRequest::STATUS_CANCELLED => 'Dibatalkan',
    ];
@endphp

<x-settings-shell title="Permintaan Pembelian">
    <div class="space-y-6">
        <x-ui.page-header
            title="Direktori Permintaan Pembelian"
            subtitle="Ajukan kebutuhan pembelian material tanpa mengubah stok ledger.">
            <x-slot:breadcrumb>Persediaan / Permintaan Pembelian</x-slot:breadcrumb>
            @can('create', PurchaseRequest::class)
                <x-slot:actions>
                    <x-ui.button variant="primary" :href="route('inventory.purchase-requests.create')">Buat Permintaan Pembelian</x-ui.button>
                </x-slot:actions>
            @endcan
        </x-ui.page-header>

        <form method="GET" action="{{ route('inventory.purchase-requests.index') }}" class="rounded-lg border border-hairline bg-white p-4 shadow-sm">
            <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_12rem_12rem_12rem_auto_auto] md:items-end">
                <div>
                    <label for="pr-search" class="text-sm font-medium text-ink">Cari permintaan</label>
                    <input id="pr-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Nomor atau catatan"
                           class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                </div>
                <div>
                    <label for="pr-status" class="text-sm font-medium text-ink">Status</label>
                    <select id="pr-status" name="status" class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Semua status</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') == $status)>{{ $statusLabels[$status] ?? $status }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="pr-date-from" class="text-sm font-medium text-ink">Dari tanggal</label>
                    <input id="pr-date-from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"
                           class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                </div>
                <div>
                    <label for="pr-date-to" class="text-sm font-medium text-ink">Sampai tanggal</label>
                    <input id="pr-date-to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"
                           class="mt-1 block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                </div>
                <button class="inline-flex justify-center rounded-lg bg-navy px-4 py-2 text-sm font-semibold text-white hover:bg-navy-700 focus:outline-none focus:ring-2 focus:ring-navy focus:ring-offset-2">
                    Terapkan
                </button>
                <a href="{{ route('inventory.purchase-requests.index') }}" class="inline-flex justify-center rounded-lg border border-hairline px-4 py-2 text-sm font-semibold text-ink-soft hover:bg-navy-50 hover:text-navy focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                    Atur Ulang
                </a>
            </div>
        </form>

        <section class="rounded-lg border border-hairline bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-4 py-3">
                <div>
                    <h3 class="text-base font-semibold text-navy">Permintaan Pembelian</h3>
                    <p class="text-sm text-ink-soft">{{ format_number_id($purchaseRequests->total()) }} permintaan dalam cabang aktif.</p>
                </div>
            </div>

            @if ($purchaseRequests->isEmpty())
                <div class="px-4 py-12 text-center">
                    <p class="text-sm font-medium text-navy">Belum ada permintaan pembelian.</p>
                    <p class="mt-1 text-sm text-ink-soft">Buat permintaan baru untuk mengajukan kebutuhan material.</p>
                    @can('create', PurchaseRequest::class)
                        <a href="{{ route('inventory.purchase-requests.create') }}" class="mt-4 inline-flex items-center rounded-lg bg-brand-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-600">
                            Buat Permintaan Pembelian
                        </a>
                    @endcan
                </div>
            @else
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-hairline text-sm">
                        <thead class="bg-navy-50">
                            <tr class="text-left text-ink-soft">
                                <th scope="col" class="px-4 py-3 font-medium">Nomor PR</th>
                                <th scope="col" class="px-4 py-3 font-medium">Tanggal</th>
                                <th scope="col" class="px-4 py-3 font-medium">Status</th>
                                <th scope="col" class="px-4 py-3 font-medium">Pemohon</th>
                                <th scope="col" class="px-4 py-3 font-medium text-right">Jumlah Item</th>
                                <th scope="col" class="px-4 py-3 font-medium">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-hairline bg-white">
                            @foreach ($purchaseRequests as $purchaseRequest)
                                <tr class="hover:bg-navy-50">
                                    <td class="px-4 py-3 font-medium text-navy">{{ $purchaseRequest->purchase_request_number }}</td>
                                    <td class="px-4 py-3 tabular-nums text-ink">{{ format_date_id($purchaseRequest->request_date) }}</td>
                                    <td class="px-4 py-3">@include('inventory.purchase-requests._status-badge', ['status' => $purchaseRequest->status])</td>
                                    <td class="px-4 py-3 text-ink">{{ $purchaseRequest->requestedBy?->name ?? '—' }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-ink">{{ format_number_id($purchaseRequest->items_count) }}</td>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('inventory.purchase-requests.show', $purchaseRequest) }}" class="text-sm font-medium text-brand-700 hover:text-brand-600">Detail</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="divide-y divide-hairline md:hidden">
                    @foreach ($purchaseRequests as $purchaseRequest)
                        <article class="p-4">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <h4 class="font-semibold text-navy">{{ $purchaseRequest->purchase_request_number }}</h4>
                                @include('inventory.purchase-requests._status-badge', ['status' => $purchaseRequest->status])
                            </div>
                            <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                                <div>
                                    <dt class="text-ink-soft">Tanggal</dt>
                                    <dd class="font-medium tabular-nums text-navy">{{ format_date_id($purchaseRequest->request_date) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-ink-soft">Pemohon</dt>
                                    <dd class="font-medium text-navy">{{ $purchaseRequest->requestedBy?->name ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-ink-soft">Jumlah Item</dt>
                                    <dd class="font-medium tabular-nums text-navy">{{ format_number_id($purchaseRequest->items_count) }}</dd>
                                </div>
                            </dl>
                            <a href="{{ route('inventory.purchase-requests.show', $purchaseRequest) }}" class="mt-3 inline-flex text-sm font-medium text-brand-700 hover:text-brand-600">Lihat detail</a>
                        </article>
                    @endforeach
                </div>

                @if ($purchaseRequests->hasPages())
                    <div class="border-t border-hairline px-4 py-3">
                        {{ $purchaseRequests->links() }}
                    </div>
                @endif
            @endif
        </section>
    </div>
</x-settings-shell>
