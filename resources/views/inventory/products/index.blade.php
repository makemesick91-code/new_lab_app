<x-settings-shell title="Produk Persediaan">
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Produk Persediaan</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">Direktori Stok Produk</h2>
                <p class="mt-1 text-sm text-gray-500">Total stok cabang dihitung dari ledger pergerakan di seluruh lokasi persediaan aktif.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('create', \App\Modules\Inventory\Models\Product::class)
                    <x-ui.button variant="secondary" :href="route('inventory.products.import.template')">Unduh Template CSV</x-ui.button>
                    <x-ui.button variant="primary" :href="route('inventory.products.import')">Impor CSV</x-ui.button>
                @endcan
                <x-ui.button variant="primary" :href="route('inventory.products.create')">Tambah Produk</x-ui.button>
            </div>
        </div>

        <x-ui.card padding="p-4">
            <form method="GET" action="{{ route('inventory.products.index') }}">
                <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_12rem_auto_auto] md:items-end">
                    <div>
                        <label for="product-search" class="text-sm font-medium text-gray-700">Cari produk</label>
                        <input id="product-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Kode, nama, atau kategori"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                    </div>
                    <div>
                        <label for="product-status" class="text-sm font-medium text-gray-700">Status aktif</label>
                        <select id="product-status" name="is_active" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="">Semua produk</option>
                            <option value="1" @selected(($filters['is_active'] ?? '') === true || ($filters['is_active'] ?? '') === '1')>Hanya aktif</option>
                            <option value="0" @selected(($filters['is_active'] ?? '') === false || ($filters['is_active'] ?? '') === '0')>Hanya nonaktif</option>
                        </select>
                    </div>
                    <x-ui.button type="submit" variant="neutral">Terapkan</x-ui.button>
                    <x-ui.button variant="secondary" :href="route('inventory.products.index')">Atur Ulang</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card padding="">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Produk</h3>
                    <p class="text-sm text-gray-500">{{ format_number_id($products->total()) }} produk dalam lingkup cabang aktif.</p>
                </div>
                <x-ui.badge tone="info">Total Stok Cabang</x-ui.badge>
            </div>

            <div class="hidden md:block">
                <x-ui.table class="!border-0 !shadow-none !rounded-none">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-gray-500">
                            <th scope="col" class="px-4 py-3 font-medium">Produk</th>
                            <th scope="col" class="px-3 py-3 font-medium">Kategori / Satuan</th>
                            <th scope="col" class="px-3 py-3 text-right font-medium">Stok Minimum</th>
                            <th scope="col" class="px-3 py-3 text-right font-medium">Stok Saat Ini - Total Cabang</th>
                            <th scope="col" class="px-3 py-3 font-medium">Status Stok</th>
                            <th scope="col" class="px-3 py-3 font-medium">Status Aktif</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Aksi Aman</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($products as $product)
                            @php
                                $currentStock = (float) ($currentStocks[$product->id] ?? 0);
                                $minimumStock = (float) $product->minimum_stock;
                                $isOut = $currentStock <= 0;
                                $isLow = ! $isOut && $currentStock <= $minimumStock;
                            @endphp
                            <tr @class([
                                'hover:bg-gray-50',
                                'bg-gray-50/60 text-gray-500' => ! $product->is_active,
                                'bg-rose-50/40' => $product->is_active && $isOut,
                                'bg-amber-50/40' => $product->is_active && $isLow,
                            ])>
                                <td class="px-4 py-3">
                                    <div class="flex items-start gap-3">
                                        <div @class([
                                            'mt-1 h-10 w-1 rounded-full',
                                            'bg-rose-400' => $isOut,
                                            'bg-amber-400' => $isLow,
                                            'bg-emerald-400' => ! $isOut && ! $isLow,
                                        ])></div>
                                        <div class="min-w-0">
                                            <p class="font-semibold text-gray-900">{{ $product->name }}</p>
                                            <p class="mt-0.5 text-xs text-gray-500">{{ $product->code }}</p>
                                            @if (! $product->is_active)
                                                <p class="mt-1 text-xs font-medium text-gray-500">Operasi stok dinonaktifkan untuk produk nonaktif.</p>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-3 text-gray-600">
                                    <p>{{ $product->category?->name ?? '-' }}</p>
                                    <p class="mt-0.5 text-xs text-gray-500">{{ $product->unit?->symbol ?? '-' }}</p>
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_quantity_id($minimumStock) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">
                                    <span @class([
                                        'font-semibold',
                                        'text-rose-700' => $isOut,
                                        'text-amber-700' => $isLow,
                                        'text-gray-900' => ! $isOut && ! $isLow,
                                    ])>{{ format_quantity_id($currentStock) }}</span>
                                </td>
                                <td class="px-3 py-3">@include('inventory._low-stock-badge', ['current' => $currentStock, 'minimum' => $minimumStock])</td>
                                <td class="px-3 py-3">@include('inventory._status-badge', ['active' => $product->is_active])</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                        <x-ui.button variant="secondary" :href="route('inventory.products.show', $product)" class="!px-3 !py-1.5 !text-xs">Lihat</x-ui.button>
                                        <x-ui.button variant="primary" :href="route('inventory.products.stock-card', $product)" class="!px-3 !py-1.5 !text-xs">Kartu Stok</x-ui.button>
                                        @if ($product->is_active)
                                            <x-ui.button variant="secondary" :href="route('inventory.products.receive-stock.create', $product)" class="!px-3 !py-1.5 !text-xs !border-emerald-200 !text-emerald-700 hover:!bg-emerald-50">Terima</x-ui.button>
                                        @endif
                                        <x-ui.button variant="secondary" :href="route('inventory.products.edit', $product)" class="!px-3 !py-1.5 !text-xs">Ubah</x-ui.button>
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
                                        <p class="mt-3 text-sm font-medium text-gray-900">Belum ada produk.</p>
                                        <p class="mt-1 text-sm text-gray-500">Tambahkan material sebelum menerima stok atau mencatat stok awal.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </x-ui.table>
            </div>

            <div class="divide-y divide-gray-100 md:hidden">
                @forelse ($products as $product)
                    @php
                        $currentStock = (float) ($currentStocks[$product->id] ?? 0);
                        $minimumStock = (float) $product->minimum_stock;
                        $isOut = $currentStock <= 0;
                        $isLow = ! $isOut && $currentStock <= $minimumStock;
                    @endphp
                    <article @class([
                        'p-4',
                        'bg-gray-50/60' => ! $product->is_active,
                        'bg-rose-50/40' => $product->is_active && $isOut,
                        'bg-amber-50/40' => $product->is_active && $isLow,
                    ])>
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $product->code }}</p>
                                <h3 class="mt-1 text-base font-semibold text-gray-900">{{ $product->name }}</h3>
                                <p class="mt-1 text-sm text-gray-500">{{ $product->category?->name ?? '-' }} · {{ $product->unit?->symbol ?? '-' }}</p>
                            </div>
                            <div class="shrink-0">@include('inventory._low-stock-badge', ['current' => $currentStock, 'minimum' => $minimumStock])</div>
                        </div>
                        <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div class="rounded-lg bg-white p-3 ring-1 ring-gray-100">
                                <p class="text-xs text-gray-500">Total Cabang</p>
                                <p class="mt-1 font-semibold tabular-nums text-gray-900">{{ format_quantity_id($currentStock) }}</p>
                            </div>
                            <div class="rounded-lg bg-white p-3 ring-1 ring-gray-100">
                                <p class="text-xs text-gray-500">Stok Minimum</p>
                                <p class="mt-1 font-semibold tabular-nums text-gray-900">{{ format_quantity_id($minimumStock) }}</p>
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            @include('inventory._status-badge', ['active' => $product->is_active])
                            @if (! $product->is_active)
                                <span class="text-xs font-medium text-gray-500">Operasi stok dinonaktifkan.</span>
                            @endif
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <x-ui.button variant="secondary" :href="route('inventory.products.show', $product)" class="!px-3 !py-2 !text-sm">Lihat</x-ui.button>
                            <x-ui.button variant="primary" :href="route('inventory.products.stock-card', $product)" class="!px-3 !py-2 !text-sm">Kartu Stok</x-ui.button>
                            @if ($product->is_active)
                                <x-ui.button variant="secondary" :href="route('inventory.products.receive-stock.create', $product)" class="!px-3 !py-2 !text-sm !border-emerald-200 !text-emerald-700 hover:!bg-emerald-50">Terima</x-ui.button>
                            @endif
                        </div>
                    </article>
                @empty
                    <div class="px-4 py-10 text-center">
                        <p class="text-sm font-medium text-gray-900">Belum ada produk.</p>
                        <p class="mt-1 text-sm text-gray-500">Tambahkan material sebelum menerima stok atau mencatat stok awal.</p>
                    </div>
                @endforelse
            </div>

            <div class="border-t border-gray-200 px-4 py-3">{{ $products->links() }}</div>
        </x-ui.card>
    </div>
</x-settings-shell>
