@php
    use App\Modules\Inventory\Models\StockTransfer;

    $statusLabels = [
        StockTransfer::STATUS_DRAFT => 'Draft',
        StockTransfer::STATUS_SUBMITTED => 'Diajukan',
        StockTransfer::STATUS_IN_TRANSIT => 'Dalam Perjalanan',
        StockTransfer::STATUS_RECEIVED => 'Diterima',
        StockTransfer::STATUS_COMPLETED => 'Diterima',
        StockTransfer::STATUS_CANCELLED => 'Dibatalkan',
    ];
@endphp

<x-settings-shell title="Transfer Stok">
    <div class="space-y-6">
        <x-ui.page-header
            title="Direktori Transfer Stok"
            subtitle="Pindahkan stok antar lokasi persediaan dalam cabang aktif.">
            <x-slot:breadcrumb>Persediaan / Transfer Stok</x-slot:breadcrumb>
            @can('create', StockTransfer::class)
                <x-slot:actions>
                    <x-ui.button variant="primary" :href="route('inventory.stock-transfers.create')">Buat Transfer Stok</x-ui.button>
                </x-slot:actions>
            @endcan
        </x-ui.page-header>

        <form method="GET" action="{{ route('inventory.stock-transfers.index') }}" class="rounded-lg border border-hairline bg-white p-4 shadow-sm">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4 xl:items-end">
                <div>
                    <label for="transfer-search" class="mb-1 block text-sm font-medium text-ink">Cari transfer</label>
                    <input id="transfer-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Nomor atau lokasi"
                           class="block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                </div>
                <div>
                    <label for="transfer-source" class="mb-1 block text-sm font-medium text-ink">Lokasi Sumber</label>
                    <select id="transfer-source" name="source_inventory_location_id" class="block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Semua lokasi</option>
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}" @selected(($filters['source_inventory_location_id'] ?? '') == $location->id)>{{ $location->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="transfer-destination" class="mb-1 block text-sm font-medium text-ink">Lokasi Tujuan</label>
                    <select id="transfer-destination" name="destination_inventory_location_id" class="block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Semua lokasi</option>
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}" @selected(($filters['destination_inventory_location_id'] ?? '') == $location->id)>{{ $location->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="transfer-status" class="mb-1 block text-sm font-medium text-ink">Status</label>
                    <select id="transfer-status" name="status" class="block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                        <option value="">Semua status</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') == $status)>{{ $statusLabels[$status] ?? $status }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="transfer-date-from" class="mb-1 block text-sm font-medium text-ink">Dari tanggal</label>
                    <input id="transfer-date-from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"
                           class="block w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                </div>
                @include('inventory._filter-actions', ['resetUrl' => route('inventory.stock-transfers.index')])
            </div>
        </form>

        <section class="rounded-lg border border-hairline bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-4 py-3">
                <div>
                    <h3 class="text-base font-semibold text-navy">Transfer Stok</h3>
                    <p class="text-sm text-ink-soft">{{ format_number_id($stockTransfers->total()) }} transfer dalam lingkup cabang aktif.</p>
                </div>
            </div>

            <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-hairline text-sm">
                    <thead class="bg-navy-50">
                        <tr class="text-left text-ink-soft">
                            <th scope="col" class="px-4 py-3 font-medium">Nomor Transfer</th>
                            <th scope="col" class="px-3 py-3 font-medium">Lokasi Sumber</th>
                            <th scope="col" class="px-3 py-3 font-medium">Lokasi Tujuan</th>
                            <th scope="col" class="px-3 py-3 font-medium">Tanggal</th>
                            <th scope="col" class="px-3 py-3 font-medium">Status</th>
                            <th scope="col" class="px-3 py-3 text-right font-medium">Item</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @forelse ($stockTransfers as $stockTransfer)
                            <tr class="hover:bg-navy-50">
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-navy">{{ $stockTransfer->transfer_number }}</p>
                                </td>
                                <td class="px-3 py-3 text-ink">{{ $stockTransfer->sourceInventoryLocation?->name ?? '-' }}</td>
                                <td class="px-3 py-3 text-ink">{{ $stockTransfer->destinationInventoryLocation?->name ?? '-' }}</td>
                                <td class="px-3 py-3 tabular-nums text-ink-soft">{{ format_date_id($stockTransfer->transfer_date) }}</td>
                                <td class="px-3 py-3">
                                    @include('inventory.stock-transfers._status-badge', ['status' => $stockTransfer->status])
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums text-ink">{{ format_number_id($stockTransfer->items_count ?? 0) }}</td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                        <a href="{{ route('inventory.stock-transfers.show', $stockTransfer) }}" class="font-medium text-brand-700 hover:text-brand-600">Detail</a>
                                        @can('update', $stockTransfer)
                                            @if ($stockTransfer->status === StockTransfer::STATUS_DRAFT)
                                                <a href="{{ route('inventory.stock-transfers.edit', $stockTransfer) }}" class="font-medium text-ink hover:text-navy">Ubah</a>
                                            @endif
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-12">
                                    <div class="mx-auto max-w-sm text-center">
                                        <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-navy-50 text-ink-muted">
                                            <span class="text-lg font-semibold">0</span>
                                        </div>
                                        <p class="mt-3 text-sm font-medium text-navy">Belum ada transfer stok.</p>
                                        <p class="mt-1 text-sm text-ink-soft">Buat transfer stok untuk memindahkan persediaan antar lokasi.</p>
                                        @can('create', StockTransfer::class)
                                            <a href="{{ route('inventory.stock-transfers.create') }}" class="mt-4 inline-flex items-center rounded-lg bg-brand-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-600">
                                                Buat Transfer Stok
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
                @forelse ($stockTransfers as $stockTransfer)
                    <article class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-xs font-medium uppercase tracking-wide text-ink-soft">{{ $stockTransfer->transfer_number }}</p>
                                <h3 class="mt-1 text-base font-semibold text-navy">{{ $stockTransfer->sourceInventoryLocation?->name ?? '-' }} → {{ $stockTransfer->destinationInventoryLocation?->name ?? '-' }}</h3>
                                <p class="mt-1 text-sm text-ink-soft">{{ format_date_id($stockTransfer->transfer_date) }}</p>
                            </div>
                            @include('inventory.stock-transfers._status-badge', ['status' => $stockTransfer->status])
                        </div>
                        <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div class="rounded-lg bg-navy-50 p-3 ring-1 ring-hairline">
                                <p class="text-xs text-ink-soft">Diminta Oleh</p>
                                <p class="mt-1 font-semibold text-navy">{{ $stockTransfer->requestedBy?->name ?? $stockTransfer->createdBy?->name ?? '-' }}</p>
                            </div>
                            <div class="rounded-lg bg-navy-50 p-3 ring-1 ring-hairline">
                                <p class="text-xs text-ink-soft">Item</p>
                                <p class="mt-1 font-semibold tabular-nums text-navy">{{ format_number_id($stockTransfer->items_count ?? 0) }}</p>
                            </div>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <a href="{{ route('inventory.stock-transfers.show', $stockTransfer) }}" class="rounded-lg border border-brand-200 px-3 py-2 text-sm font-medium text-brand-700">Lihat detail</a>
                            @can('update', $stockTransfer)
                                @if ($stockTransfer->status === StockTransfer::STATUS_DRAFT)
                                    <a href="{{ route('inventory.stock-transfers.edit', $stockTransfer) }}" class="rounded-lg border border-hairline px-3 py-2 text-sm font-medium text-ink">Ubah</a>
                                @endif
                            @endcan
                        </div>
                    </article>
                @empty
                    <div class="px-4 py-10 text-center">
                        <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-navy-50 text-ink-muted">
                            <span class="text-lg font-semibold">0</span>
                        </div>
                        <p class="mt-3 text-sm font-medium text-navy">Belum ada transfer stok.</p>
                        <p class="mt-1 text-sm text-ink-soft">Buat transfer stok untuk memindahkan persediaan antar lokasi.</p>
                        @can('create', StockTransfer::class)
                            <a href="{{ route('inventory.stock-transfers.create') }}" class="mt-4 inline-flex items-center rounded-lg bg-brand-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-600">
                                Buat Transfer Stok
                            </a>
                        @endcan
                    </div>
                @endforelse
            </div>

            @if ($stockTransfers->hasPages())
                <div class="border-t border-hairline px-4 py-3">
                    {{ $stockTransfers->links() }}
                </div>
            @endif
        </section>
    </div>
</x-settings-shell>
