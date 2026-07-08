<x-settings-shell title="Produk Persediaan">
    <div class="space-y-6">
        <x-ui.page-header
            title="Direktori Stok Produk"
            subtitle="Total stok cabang dihitung dari ledger pergerakan di seluruh lokasi persediaan aktif.">
            <x-slot:breadcrumb>Persediaan / Produk</x-slot:breadcrumb>
            <x-slot:actions>
                @can('create', \App\Modules\Inventory\Models\Product::class)
                    <x-ui.button variant="secondary" :href="route('inventory.products.import.template')">Unduh Template CSV</x-ui.button>
                    <x-ui.button variant="secondary" :href="route('inventory.products.import')">Impor CSV</x-ui.button>
                @endcan
                <x-ui.button variant="primary" :href="route('inventory.products.create')">Tambah Produk</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.filter-bar :action="route('inventory.products.index')">
            <div class="min-w-0 flex-1 md:max-w-sm">
                <x-ui.input label="Cari produk" id="product-search" type="search" name="search"
                    :value="$filters['search'] ?? ''" placeholder="Kode, nama, atau kategori" />
            </div>
            <div class="md:w-48">
                <x-ui.select label="Status aktif" id="product-status" name="is_active">
                    <option value="">Semua produk</option>
                    <option value="1" @selected(($filters['is_active'] ?? '') === true || ($filters['is_active'] ?? '') === '1')>Hanya aktif</option>
                    <option value="0" @selected(($filters['is_active'] ?? '') === false || ($filters['is_active'] ?? '') === '0')>Hanya nonaktif</option>
                </x-ui.select>
            </div>
            <x-slot:actions>
                <x-ui.button type="submit" variant="primary">Terapkan</x-ui.button>
                <x-ui.button variant="secondary" :href="route('inventory.products.index')">Atur Ulang</x-ui.button>
            </x-slot:actions>
        </x-ui.filter-bar>

        <x-ui.card padding="">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-4 py-3">
                <div>
                    <h3 class="text-base font-semibold text-navy">Produk</h3>
                    <p class="text-sm text-ink-soft">{{ format_number_id($products->total()) }} produk dalam lingkup cabang aktif.</p>
                </div>
                <x-ui.badge tone="info">Total Stok Cabang</x-ui.badge>
            </div>

            <div class="hidden md:block">
                <x-ui.table class="!border-0 !shadow-none !rounded-none">
                    <thead class="bg-navy-50">
                        <tr class="text-left text-ink-soft">
                            <th scope="col" class="px-4 py-3 font-medium">Produk</th>
                            <th scope="col" class="px-3 py-3 font-medium">Kategori / Satuan</th>
                            <th scope="col" class="px-3 py-3 text-right font-medium">Stok Minimum</th>
                            <th scope="col" class="px-3 py-3 text-right font-medium">Stok Saat Ini - Total Cabang</th>
                            <th scope="col" class="px-3 py-3 font-medium">Status Stok</th>
                            <th scope="col" class="px-3 py-3 font-medium">Status Aktif</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Aksi Aman</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @forelse ($products as $product)
                            @php
                                $currentStock = (float) ($currentStocks[$product->id] ?? 0);
                                $minimumStock = (float) $product->minimum_stock;
                                $isOut = $currentStock <= 0;
                                $isLow = ! $isOut && $currentStock <= $minimumStock;
                            @endphp
                            <tr @class([
                                'hover:bg-navy-50',
                                'bg-navy-50/60 text-ink-soft' => ! $product->is_active,
                                'bg-danger-50/40' => $product->is_active && $isOut,
                                'bg-warning-50/40' => $product->is_active && $isLow,
                            ])>
                                <td class="px-4 py-3">
                                    <div class="flex items-start gap-3">
                                        <div @class([
                                            'mt-1 h-10 w-1 rounded-full',
                                            'bg-danger-700' => $isOut,
                                            'bg-warning-700' => $isLow,
                                            'bg-success-700' => ! $isOut && ! $isLow,
                                        ])></div>
                                        <div class="min-w-0">
                                            <p class="font-semibold text-navy">{{ $product->name }}</p>
                                            <p class="mt-0.5 text-xs text-ink-soft">{{ $product->code }}</p>
                                            @if (! $product->is_active)
                                                <p class="mt-1 text-xs font-medium text-ink-soft">Operasi stok dinonaktifkan untuk produk nonaktif.</p>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-3 text-ink-soft">
                                    <p>{{ $product->category?->name ?? '-' }}</p>
                                    <p class="mt-0.5 text-xs text-ink-soft">{{ $product->unit?->symbol ?? '-' }}</p>
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums text-ink">{{ format_quantity_id($minimumStock) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">
                                    <span @class([
                                        'font-semibold',
                                        'text-danger-700' => $isOut,
                                        'text-warning-700' => $isLow,
                                        'text-navy' => ! $isOut && ! $isLow,
                                    ])>{{ format_quantity_id($currentStock) }}</span>
                                </td>
                                <td class="px-3 py-3">@include('inventory._low-stock-badge', ['current' => $currentStock, 'minimum' => $minimumStock])</td>
                                <td class="px-3 py-3">@include('inventory._status-badge', ['active' => $product->is_active])</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                        <x-ui.button size="sm" variant="secondary" :href="route('inventory.products.show', $product)">Lihat</x-ui.button>
                                        <x-ui.button size="sm" variant="primary" :href="route('inventory.products.stock-card', $product)">Kartu Stok</x-ui.button>
                                        @if ($product->is_active)
                                            <x-ui.button size="sm" variant="secondary" :href="route('inventory.products.receive-stock.create', $product)" class="!border-success-100 !text-success-700 hover:!bg-success-50">Terima</x-ui.button>
                                        @endif
                                        <x-ui.button size="sm" variant="secondary" :href="route('inventory.products.edit', $product)">Ubah</x-ui.button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-12">
                                    <x-ui.empty-state title="Belum ada produk."
                                        description="Tambahkan material sebelum menerima stok atau mencatat stok awal." class="border-0 bg-transparent shadow-none">
                                        @can('create', \App\Modules\Inventory\Models\Product::class)
                                            <x-slot:action>
                                                <x-ui.button variant="primary" :href="route('inventory.products.create')">Tambah Produk</x-ui.button>
                                            </x-slot:action>
                                        @else
                                            <x-slot:action>
                                                <x-ui.restricted-notice description="Anda tidak memiliki akses untuk menambah produk. Hubungi administrator jika memerlukan akses." />
                                            </x-slot:action>
                                        @endcan
                                    </x-ui.empty-state>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </x-ui.table>
            </div>

            <div class="divide-y divide-hairline md:hidden">
                @forelse ($products as $product)
                    @php
                        $currentStock = (float) ($currentStocks[$product->id] ?? 0);
                        $minimumStock = (float) $product->minimum_stock;
                        $isOut = $currentStock <= 0;
                        $isLow = ! $isOut && $currentStock <= $minimumStock;
                    @endphp
                    <article @class([
                        'p-4',
                        'bg-navy-50/60' => ! $product->is_active,
                        'bg-danger-50/40' => $product->is_active && $isOut,
                        'bg-warning-50/40' => $product->is_active && $isLow,
                    ])>
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-xs font-medium uppercase tracking-wide text-ink-soft">{{ $product->code }}</p>
                                <h3 class="mt-1 text-base font-semibold text-navy">{{ $product->name }}</h3>
                                <p class="mt-1 text-sm text-ink-soft">{{ $product->category?->name ?? '-' }} · {{ $product->unit?->symbol ?? '-' }}</p>
                            </div>
                            <div class="shrink-0">@include('inventory._low-stock-badge', ['current' => $currentStock, 'minimum' => $minimumStock])</div>
                        </div>
                        <div class="mt-4 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                            <div class="rounded-lg bg-surface p-3 ring-1 ring-hairline">
                                <p class="text-xs text-ink-soft">Total Cabang</p>
                                <p class="mt-1 font-semibold tabular-nums text-navy">{{ format_quantity_id($currentStock) }}</p>
                            </div>
                            <div class="rounded-lg bg-surface p-3 ring-1 ring-hairline">
                                <p class="text-xs text-ink-soft">Stok Minimum</p>
                                <p class="mt-1 font-semibold tabular-nums text-navy">{{ format_quantity_id($minimumStock) }}</p>
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            @include('inventory._status-badge', ['active' => $product->is_active])
                            @if (! $product->is_active)
                                <span class="text-xs font-medium text-ink-soft">Operasi stok dinonaktifkan.</span>
                            @endif
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <x-ui.button variant="secondary" :href="route('inventory.products.show', $product)">Lihat</x-ui.button>
                            <x-ui.button variant="primary" :href="route('inventory.products.stock-card', $product)">Kartu Stok</x-ui.button>
                            @if ($product->is_active)
                                <x-ui.button variant="secondary" :href="route('inventory.products.receive-stock.create', $product)" class="!border-success-100 !text-success-700 hover:!bg-success-50">Terima</x-ui.button>
                            @endif
                        </div>
                    </article>
                @empty
                    <div class="px-4 py-10">
                        <x-ui.empty-state title="Belum ada produk."
                            description="Tambahkan material sebelum menerima stok atau mencatat stok awal." class="border-0 bg-transparent shadow-none" />
                    </div>
                @endforelse
            </div>

            <div class="border-t border-hairline px-4 py-3">{{ $products->links() }}</div>
        </x-ui.card>
    </div>
</x-settings-shell>
