@php
    $statusLabels = [
        'DRAFT' => 'Draft',
        'COUNTING' => 'Sedang Dihitung',
        'COMPLETED' => 'Selesai',
        'CANCELLED' => 'Dibatalkan',
    ];
@endphp

<x-settings-shell title="Stok Opname">
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Stok Opname Persediaan</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">Direktori Stok Opname</h2>
                <p class="mt-1 text-sm text-gray-500">Sesi penghitungan stok fisik dan penyesuaian selisih.</p>
            </div>
            <a href="{{ route('inventory.stock-opnames.create') }}" class="inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                Buat Stok Opname
            </a>
        </div>

        <form method="GET" action="{{ route('inventory.stock-opnames.index') }}" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_12rem_12rem_12rem_auto_auto] md:items-end">
                <div>
                    <label for="stock-opname-search" class="text-sm font-medium text-gray-700">Cari stok opname</label>
                    <input id="stock-opname-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Nomor atau lokasi"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div>
                    <label for="stock-opname-location" class="text-sm font-medium text-gray-700">Lokasi</label>
                    <select id="stock-opname-location" name="inventory_location_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">Semua lokasi</option>
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}" @selected(($filters['inventory_location_id'] ?? '') == $location->id)>{{ $location->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="stock-opname-status" class="text-sm font-medium text-gray-700">Status</label>
                    <select id="stock-opname-status" name="status" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">Semua status</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') == $status)>{{ $statusLabels[$status] ?? $status }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="stock-opname-date-from" class="text-sm font-medium text-gray-700">Dari tanggal</label>
                    <input id="stock-opname-date-from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <button class="inline-flex justify-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-700 focus:ring-offset-2">
                    Terapkan
                </button>
                <a href="{{ route('inventory.stock-opnames.index') }}" class="inline-flex justify-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Atur Ulang
                </a>
            </div>
        </form>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Stok Opname</h3>
                    <p class="text-sm text-gray-500">{{ number_format($stockOpnames->total()) }} stok opname dalam lingkup cabang aktif.</p>
                </div>
            </div>

            <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-gray-500">
                            <th scope="col" class="px-4 py-3 font-medium">Nomor Opname</th>
                            <th scope="col" class="px-3 py-3 font-medium">Lokasi</th>
                            <th scope="col" class="px-3 py-3 font-medium">Tanggal</th>
                            <th scope="col" class="px-3 py-3 font-medium">Status</th>
                            <th scope="col" class="px-3 py-3 font-medium">Dihitung Oleh</th>
                            <th scope="col" class="px-3 py-3 font-medium">Item</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($stockOpnames as $stockOpname)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 font-semibold text-gray-900">{{ $stockOpname->opname_number }}</td>
                                <td class="px-3 py-3 text-gray-600">{{ $stockOpname->inventoryLocation?->name ?? '-' }}</td>
                                <td class="px-3 py-3 text-gray-600">{{ $stockOpname->opname_date->format('Y-m-d') }}</td>
                                <td class="px-3 py-3">
                                    <span @class([
                                        'inline-flex rounded-full px-3 py-1 text-xs font-medium',
                                        'bg-blue-100 text-blue-800' => $stockOpname->status === 'DRAFT',
                                        'bg-yellow-100 text-yellow-800' => $stockOpname->status === 'COUNTING',
                                        'bg-green-100 text-green-800' => $stockOpname->status === 'COMPLETED',
                                        'bg-red-100 text-red-800' => $stockOpname->status === 'CANCELLED',
                                    ])>
                                        {{ $statusLabels[$stockOpname->status] ?? $stockOpname->status }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 text-gray-600">{{ $stockOpname->countedBy?->name ?? '-' }}</td>
                                <td class="px-3 py-3 text-gray-600">{{ $stockOpname->items_count ?? 0 }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                        <a href="{{ route('inventory.stock-opnames.show', $stockOpname) }}" class="font-medium text-teal-700 hover:text-teal-600">Lihat</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-12">
                                    <div class="mx-auto max-w-sm text-center">
                                        <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                                            <span class="text-lg font-semibold">0</span>
                                        </div>
                                        <p class="mt-3 text-sm font-medium text-gray-900">Belum ada stok opname.</p>
                                        <p class="mt-1 text-sm text-gray-500">Buat stok opname untuk mulai menghitung persediaan fisik.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-gray-100 md:hidden">
                @forelse ($stockOpnames as $stockOpname)
                    <article class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $stockOpname->opname_number }}</p>
                                <h3 class="mt-1 text-base font-semibold text-gray-900">{{ $stockOpname->inventoryLocation?->name ?? '-' }}</h3>
                                <p class="mt-1 text-sm text-gray-500">{{ $stockOpname->opname_date->format('Y-m-d') }}</p>
                            </div>
                            <span @class([
                                'inline-flex rounded-full px-3 py-1 text-xs font-medium',
                                'bg-blue-100 text-blue-800' => $stockOpname->status === 'DRAFT',
                                'bg-yellow-100 text-yellow-800' => $stockOpname->status === 'COUNTING',
                                'bg-green-100 text-green-800' => $stockOpname->status === 'COMPLETED',
                                'bg-red-100 text-red-800' => $stockOpname->status === 'CANCELLED',
                            ])>
                                {{ $statusLabels[$stockOpname->status] ?? $stockOpname->status }}
                            </span>
                        </div>
                        <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div class="rounded-lg bg-white p-3 ring-1 ring-gray-100">
                                <p class="text-xs text-gray-500">Dihitung Oleh</p>
                                <p class="mt-1 font-semibold tabular-nums text-gray-900">{{ $stockOpname->countedBy?->name ?? '-' }}</p>
                            </div>
                            <div class="rounded-lg bg-white p-3 ring-1 ring-gray-100">
                                <p class="text-xs text-gray-500">Item</p>
                                <p class="mt-1 font-semibold tabular-nums text-gray-900">{{ $stockOpname->items_count ?? 0 }}</p>
                            </div>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <a href="{{ route('inventory.stock-opnames.show', $stockOpname) }}" class="rounded-lg border border-teal-200 px-3 py-2 text-sm font-medium text-teal-700">Lihat</a>
                        </div>
                    </article>
                @empty
                    <div class="px-4 py-10 text-center">
                        <p class="text-sm font-medium text-gray-900">Belum ada stok opname.</p>
                        <p class="mt-1 text-sm text-gray-500">Buat stok opname untuk mulai menghitung persediaan fisik.</p>
                    </div>
                @endforelse
            </div>

            <div class="border-t border-gray-200 px-4 py-3">{{ $stockOpnames->links() }}</div>
        </section>
    </div>
</x-settings-shell>
