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
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Transfer Stok Persediaan</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">Direktori Transfer Stok</h2>
                <p class="mt-1 text-sm text-gray-500">Pindahkan stok antar lokasi persediaan dalam cabang aktif.</p>
            </div>
            @can('create', StockTransfer::class)
                <a href="{{ route('inventory.stock-transfers.create') }}" class="inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Buat Transfer Stok
                </a>
            @endcan
        </div>

        <form method="GET" action="{{ route('inventory.stock-transfers.index') }}" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_12rem_12rem_12rem_12rem_auto_auto] md:items-end">
                <div>
                    <label for="transfer-search" class="text-sm font-medium text-gray-700">Cari transfer</label>
                    <input id="transfer-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Nomor atau lokasi"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div>
                    <label for="transfer-source" class="text-sm font-medium text-gray-700">Lokasi Sumber</label>
                    <select id="transfer-source" name="source_inventory_location_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">Semua lokasi</option>
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}" @selected(($filters['source_inventory_location_id'] ?? '') == $location->id)>{{ $location->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="transfer-destination" class="text-sm font-medium text-gray-700">Lokasi Tujuan</label>
                    <select id="transfer-destination" name="destination_inventory_location_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">Semua lokasi</option>
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}" @selected(($filters['destination_inventory_location_id'] ?? '') == $location->id)>{{ $location->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="transfer-status" class="text-sm font-medium text-gray-700">Status</label>
                    <select id="transfer-status" name="status" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">Semua status</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') == $status)>{{ $statusLabels[$status] ?? $status }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="transfer-date-from" class="text-sm font-medium text-gray-700">Dari tanggal</label>
                    <input id="transfer-date-from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <button class="inline-flex justify-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-700 focus:ring-offset-2">
                    Terapkan
                </button>
                <a href="{{ route('inventory.stock-transfers.index') }}" class="inline-flex justify-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Atur Ulang
                </a>
            </div>
        </form>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Transfer Stok</h3>
                    <p class="text-sm text-gray-500">{{ format_number_id($stockTransfers->total()) }} transfer dalam lingkup cabang aktif.</p>
                </div>
            </div>

            <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-gray-500">
                            <th scope="col" class="px-4 py-3 font-medium">Nomor Transfer</th>
                            <th scope="col" class="px-3 py-3 font-medium">Lokasi Sumber</th>
                            <th scope="col" class="px-3 py-3 font-medium">Lokasi Tujuan</th>
                            <th scope="col" class="px-3 py-3 font-medium">Tanggal</th>
                            <th scope="col" class="px-3 py-3 font-medium">Status</th>
                            <th scope="col" class="px-3 py-3 font-medium">Diminta Oleh</th>
                            <th scope="col" class="px-3 py-3 font-medium">Item</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($stockTransfers as $stockTransfer)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 font-semibold text-gray-900">{{ $stockTransfer->transfer_number }}</td>
                                <td class="px-3 py-3 text-gray-600">{{ $stockTransfer->sourceInventoryLocation?->name ?? '-' }}</td>
                                <td class="px-3 py-3 text-gray-600">{{ $stockTransfer->destinationInventoryLocation?->name ?? '-' }}</td>
                                <td class="px-3 py-3 text-gray-600">{{ format_date_id($stockTransfer->transfer_date) }}</td>
                                <td class="px-3 py-3">
                                    @include('inventory.stock-transfers._status-badge', ['status' => $stockTransfer->status])
                                </td>
                                <td class="px-3 py-3 text-gray-600">{{ $stockTransfer->requestedBy?->name ?? $stockTransfer->createdBy?->name ?? '-' }}</td>
                                <td class="px-3 py-3 text-gray-600">{{ $stockTransfer->items_count ?? 0 }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                        <a href="{{ route('inventory.stock-transfers.show', $stockTransfer) }}" class="font-medium text-teal-700 hover:text-teal-600">Lihat</a>
                                        @can('update', $stockTransfer)
                                            @if ($stockTransfer->status === StockTransfer::STATUS_DRAFT)
                                                <a href="{{ route('inventory.stock-transfers.edit', $stockTransfer) }}" class="font-medium text-gray-700 hover:text-gray-900">Ubah</a>
                                            @endif
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-12">
                                    <div class="mx-auto max-w-sm text-center">
                                        <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                                            <span class="text-lg font-semibold">0</span>
                                        </div>
                                        <p class="mt-3 text-sm font-medium text-gray-900">Belum ada transfer stok.</p>
                                        <p class="mt-1 text-sm text-gray-500">Buat transfer stok untuk memindahkan persediaan antar lokasi.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-gray-100 md:hidden">
                @forelse ($stockTransfers as $stockTransfer)
                    <article class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $stockTransfer->transfer_number }}</p>
                                <h3 class="mt-1 text-base font-semibold text-gray-900">{{ $stockTransfer->sourceInventoryLocation?->name ?? '-' }} → {{ $stockTransfer->destinationInventoryLocation?->name ?? '-' }}</h3>
                                <p class="mt-1 text-sm text-gray-500">{{ format_date_id($stockTransfer->transfer_date) }}</p>
                            </div>
                            @include('inventory.stock-transfers._status-badge', ['status' => $stockTransfer->status])
                        </div>
                        <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div class="rounded-lg bg-white p-3 ring-1 ring-gray-100">
                                <p class="text-xs text-gray-500">Diminta Oleh</p>
                                <p class="mt-1 font-semibold text-gray-900">{{ $stockTransfer->requestedBy?->name ?? $stockTransfer->createdBy?->name ?? '-' }}</p>
                            </div>
                            <div class="rounded-lg bg-white p-3 ring-1 ring-gray-100">
                                <p class="text-xs text-gray-500">Item</p>
                                <p class="mt-1 font-semibold tabular-nums text-gray-900">{{ $stockTransfer->items_count ?? 0 }}</p>
                            </div>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <a href="{{ route('inventory.stock-transfers.show', $stockTransfer) }}" class="rounded-lg border border-teal-200 px-3 py-2 text-sm font-medium text-teal-700">Lihat</a>
                            @can('update', $stockTransfer)
                                @if ($stockTransfer->status === StockTransfer::STATUS_DRAFT)
                                    <a href="{{ route('inventory.stock-transfers.edit', $stockTransfer) }}" class="rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-700">Ubah</a>
                                @endif
                            @endcan
                        </div>
                    </article>
                @empty
                    <div class="px-4 py-10 text-center">
                        <p class="text-sm font-medium text-gray-900">Belum ada transfer stok.</p>
                        <p class="mt-1 text-sm text-gray-500">Buat transfer stok untuk memindahkan persediaan antar lokasi.</p>
                    </div>
                @endforelse
            </div>

            <div class="border-t border-gray-200 px-4 py-3">{{ $stockTransfers->links() }}</div>
        </section>
    </div>
</x-settings-shell>
