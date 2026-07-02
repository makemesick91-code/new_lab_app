@php
    $activeTab = $activeTab ?? ($filters['report_tab'] ?? 'current_stock');
    $activeTabKebab = $activeTabKebab ?? \App\Modules\Inventory\Requests\InventoryReportFilterRequest::TAB_TO_KEBAB[$activeTab] ?? 'current-stock';
    $stockCardRows = $stockCardReport['rows'] ?? null;
    $stockMutationDateFrom = $filters['date_from'] ?? now()->startOfMonth()->toDateString();
    $stockMutationDateTo = $filters['date_to'] ?? now()->toDateString();
    $exportFilters = collect($filters)
        ->except(['per_page', 'report_tab'])
        ->put('report_type', $activeTab)
        ->all();
    $tabQueryParams = collect($filters)
        ->except(['per_page', 'report_tab'])
        ->all();
    $tabs = [
        'current_stock' => 'Stok Saat Ini',
        'stock_card' => 'Kartu Stok',
        'low_stock' => 'Low Stock',
        'mutation' => 'Mutasi Stok',
        'valuation' => 'Nilai Persediaan',
        'room_stock' => 'Stok per Ruangan',
    ];
    $showDateFilters = in_array($activeTab, ['stock_card', 'mutation'], true);
    $showProductFilter = in_array($activeTab, ['current_stock', 'stock_card', 'mutation', 'valuation', 'room_stock'], true);
    $showCategoryFilter = in_array($activeTab, ['current_stock', 'low_stock', 'valuation', 'room_stock'], true);
    $showLocationFilter = true;
    $showStockStatusFilter = in_array($activeTab, ['current_stock', 'low_stock', 'room_stock'], true);
    $showMovementTypeFilter = $activeTab === 'mutation';
    $showBatchFilter = $activeTab === 'stock_card' && ! empty($filters['product_id']);
@endphp

<x-settings-shell title="Laporan Inventory">
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Persediaan</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">Laporan Inventory</h2>
                <p class="mt-1 text-sm text-gray-500">Semua laporan persediaan berbasis ledger dari pergerakan stok, bukan kolom stok mutable.</p>
            </div>
            <a href="{{ route('inventory.dashboard') }}"
               class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                Kembali ke Dasbor
            </a>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <form method="GET" action="{{ route('inventory.reports.index') }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label for="branch_id" class="block text-sm font-medium text-gray-700">Cabang</label>
                    <select id="branch_id" name="branch_id" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" @disabled($filterOptions['branches']->count() <= 1)>
                        @foreach ($filterOptions['branches'] as $branch)
                            <option value="{{ $branch->id }}" @selected(($selectedBranchId ?? $filters['branch_id'] ?? null) == $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                    @if ($filterOptions['branches']->count() <= 1)
                        <input type="hidden" name="branch_id" value="{{ $selectedBranchId ?? $filters['branch_id'] }}">
                    @endif
                </div>

                @if ($showDateFilters)
                    <div>
                        <label for="date_from" class="block text-sm font-medium text-gray-700">Tanggal Dari</label>
                        <input id="date_from" name="date_from" type="date" value="{{ $filters['date_from'] ?? '' }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                    </div>

                    <div>
                        <label for="date_to" class="block text-sm font-medium text-gray-700">Tanggal Sampai</label>
                        <input id="date_to" name="date_to" type="date" value="{{ $filters['date_to'] ?? '' }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                    </div>
                @endif

                @if ($showProductFilter)
                    <div>
                        <label for="product_id" class="block text-sm font-medium text-gray-700">Produk</label>
                        <x-inventory.searchable-product-select
                            id="product_id"
                            name="product_id"
                            :products="$filterOptions['products']"
                            :selected="$filters['product_id'] ?? null"
                            :empty-label="$activeTab === 'stock_card' ? 'Pilih produk' : 'Semua produk'"
                            class="mt-1"
                        />
                    </div>
                @endif

                @if ($showCategoryFilter)
                    <div>
                        <label for="category_id" class="block text-sm font-medium text-gray-700">Kategori</label>
                        <select id="category_id" name="category_id" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="">Semua kategori</option>
                            @foreach ($filterOptions['categories'] as $category)
                                <option value="{{ $category->id }}" @selected(($filters['category_id'] ?? null) == $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                @if ($showLocationFilter)
                    <div>
                        <label for="inventory_location_id" class="block text-sm font-medium text-gray-700">Lokasi/Ruangan</label>
                        <select id="inventory_location_id" name="inventory_location_id" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="">Semua lokasi</option>
                            @foreach ($filterOptions['locations'] as $location)
                                <option value="{{ $location->id }}" @selected(($filters['inventory_location_id'] ?? null) == $location->id)>{{ $location->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                @if ($showBatchFilter)
                    <div>
                        <label for="inventory_batch_id" class="block text-sm font-medium text-gray-700">Batch &amp; Lot</label>
                        <select id="inventory_batch_id" name="inventory_batch_id" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="">Semua batch</option>
                            @foreach ($filterOptions['batches'] as $batch)
                                <option value="{{ $batch->id }}" @selected(($filters['inventory_batch_id'] ?? null) == $batch->id)>
                                    {{ $batch->batch_number }}{{ $batch->lot_number ? ' / '.$batch->lot_number : '' }}{{ $batch->expiry_date ? ' — Exp '.format_date_id($batch->expiry_date) : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                @if ($showStockStatusFilter)
                    <div>
                        <label for="stock_status" class="block text-sm font-medium text-gray-700">Status Stok</label>
                        <select id="stock_status" name="stock_status" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="">Semua status</option>
                            @foreach ($filterOptions['stockStatuses'] as $value => $label)
                                <option value="{{ $value }}" @selected(($filters['stock_status'] ?? null) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                @if ($showMovementTypeFilter)
                    <div>
                        <label for="movement_type" class="block text-sm font-medium text-gray-700">Tipe Movement</label>
                        <select id="movement_type" name="movement_type" class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="">Semua tipe</option>
                            @foreach ($filterOptions['movementTypes'] as $movementType)
                                <option value="{{ $movementType }}" @selected(($filters['movement_type'] ?? null) === $movementType)>{{ $movementType }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <input type="hidden" name="tab" value="{{ $activeTabKebab }}">

                <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
                    <button type="submit" class="inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">Terapkan</button>
                    <a href="{{ route('inventory.reports.index') }}" class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">Atur Ulang</a>
                </div>
            </form>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-2 shadow-sm">
            <div class="flex flex-wrap gap-2">
                @foreach ($tabs as $tabKey => $tabLabel)
                    <a href="{{ route('inventory.reports.index', array_merge($tabQueryParams, ['tab' => \App\Modules\Inventory\Requests\InventoryReportFilterRequest::TAB_TO_KEBAB[$tabKey] ?? $tabKey])) }}"
                       @class([
                           'rounded-lg px-3 py-2 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2',
                           'bg-teal-700 text-white' => $activeTab === $tabKey,
                           'text-gray-600 hover:bg-gray-50 hover:text-gray-900' => $activeTab !== $tabKey,
                       ])>
                        {{ $tabLabel }}
                    </a>
                @endforeach
            </div>
        </div>

        @if ($activeTab === 'current_stock')
        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm" data-report-panel="current-stock">
            <h3 class="text-base font-semibold text-gray-900">Stok Saat Ini</h3>
            <p class="mt-1 text-sm text-gray-500">Ringkasan stok produk dari saldo ledger aktif.</p>
            <div class="mt-3">
                <a href="{{ route('inventory.reports.export', $exportFilters) }}"
                   class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Export CSV
                </a>
            </div>

            <div class="mt-4 hidden overflow-x-auto rounded-lg border border-gray-200 md:block">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-gray-500">
                            <th scope="col" class="px-3 py-2 font-medium">Cabang</th>
                            <th scope="col" class="px-3 py-2 font-medium">Kode Produk</th>
                            <th scope="col" class="px-3 py-2 font-medium">Produk</th>
                            <th scope="col" class="px-3 py-2 font-medium">Kategori</th>
                            <th scope="col" class="px-3 py-2 font-medium">Satuan</th>
                            <th scope="col" class="px-3 py-2 font-medium">Lokasi/Ruangan</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">Stok Saat Ini</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">Minimum</th>
                            <th scope="col" class="px-3 py-2 font-medium">Status</th>
                            <th scope="col" class="px-3 py-2 font-medium">Movement Terakhir</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse ($currentStockReport as $row)
                            @php
                                $statusLabel = match ($row->stock_status) {
                                    'empty' => 'Kosong',
                                    'low' => 'Low Stock',
                                    'overstock' => 'Overstock',
                                    default => 'Normal',
                                };
                                $statusClasses = match ($row->stock_status) {
                                    'empty' => 'bg-rose-50 text-rose-700',
                                    'low' => 'bg-amber-50 text-amber-700',
                                    'overstock' => 'bg-sky-50 text-sky-700',
                                    default => 'bg-emerald-50 text-emerald-700',
                                };
                            @endphp
                            <tr @class([
                                'hover:bg-gray-50',
                                'bg-rose-50/40' => $row->stock_status === 'empty',
                                'bg-amber-50/40' => $row->stock_status === 'low',
                            ])>
                                <td class="px-3 py-2 text-gray-700">{{ $row->branch_name }}</td>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $row->product_code }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $row->product_name }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $row->category_name }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $row->unit_symbol ?: $row->unit_name }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $row->inventory_location_name }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-900">{{ number_format((float) $row->current_stock, 4) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-700">{{ number_format((float) $row->minimum_stock, 4) }}</td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClasses }}">{{ $statusLabel }}</span>
                                </td>
                                <td class="px-3 py-2 tabular-nums text-gray-700">
                                    {{ $row->last_movement_date ? \Illuminate\Support\Carbon::parse($row->last_movement_date)->format('d M Y') : '-' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-3 py-6 text-center text-sm text-gray-500">Tidak ada data stok untuk filter yang dipilih.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4 divide-y divide-gray-100 rounded-lg border border-gray-200 md:hidden">
                @forelse ($currentStockReport as $row)
                    @php
                        $statusLabel = match ($row->stock_status) {
                            'empty' => 'Kosong',
                            'low' => 'Low Stock',
                            'overstock' => 'Overstock',
                            default => 'Normal',
                        };
                        $statusClasses = match ($row->stock_status) {
                            'empty' => 'bg-rose-50 text-rose-700',
                            'low' => 'bg-amber-50 text-amber-700',
                            'overstock' => 'bg-sky-50 text-sky-700',
                            default => 'bg-emerald-50 text-emerald-700',
                        };
                    @endphp
                    <article class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-xs uppercase tracking-wide text-gray-500">{{ $row->branch_name }} &middot; {{ $row->product_code }}</p>
                                <h4 class="mt-1 text-base font-semibold text-gray-900">{{ $row->product_name }}</h4>
                                <p class="mt-1 text-sm text-gray-500">{{ $row->category_name }} &middot; {{ $row->inventory_location_name }}</p>
                            </div>
                            <span class="inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClasses }}">{{ $statusLabel }}</span>
                        </div>
                        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div class="rounded-lg bg-gray-50 p-3">
                                <dt class="text-xs text-gray-500">Stok Saat Ini</dt>
                                <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ number_format((float) $row->current_stock, 4) }}</dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3">
                                <dt class="text-xs text-gray-500">Minimum</dt>
                                <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ number_format((float) $row->minimum_stock, 4) }}</dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3">
                                <dt class="text-xs text-gray-500">Satuan</dt>
                                <dd class="mt-1 font-semibold text-gray-900">{{ $row->unit_symbol ?: $row->unit_name }}</dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3">
                                <dt class="text-xs text-gray-500">Movement Terakhir</dt>
                                <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ $row->last_movement_date ? \Illuminate\Support\Carbon::parse($row->last_movement_date)->format('d M Y') : '-' }}</dd>
                            </div>
                        </dl>
                    </article>
                @empty
                    <p class="p-4 text-center text-sm text-gray-500">Tidak ada data stok untuk filter yang dipilih.</p>
                @endforelse
            </div>

            @if ($currentStockReport->hasPages())
                <div class="mt-4 border-t border-gray-200 pt-4">
                    {{ $currentStockReport->links() }}
                </div>
            @endif
        </section>
        @endif

        @if ($activeTab === 'stock_card')
        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm" data-report-panel="stock-card">
            <h3 class="text-base font-semibold text-gray-900">Kartu Stok</h3>
            <p class="mt-1 text-sm text-amber-700">Pilih produk dan periode tanggal untuk melihat kartu stok secara detail.</p>
            <div class="mt-3">
                @if (empty($filters['product_id']))
                    <p class="text-sm font-medium text-amber-700">Export kartu stok membutuhkan filter produk.</p>
                @else
                    <a href="{{ route('inventory.reports.export', $exportFilters) }}"
                       class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                        Export CSV
                    </a>
                @endif
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Periode Aktif</p>
                    <p class="mt-1 text-sm font-semibold tabular-nums text-gray-900">
                        {{ \Illuminate\Support\Carbon::parse($stockCardReport['date_from'])->format('d M Y') }}
                        -
                        {{ \Illuminate\Support\Carbon::parse($stockCardReport['date_to'])->format('d M Y') }}
                    </p>
                </div>
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Saldo Awal</p>
                    <p class="mt-1 text-sm font-semibold tabular-nums text-gray-900">{{ number_format((float) $stockCardReport['opening_balance'], 4) }}</p>
                </div>
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Sumber</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900">Ledger pergerakan stok</p>
                </div>
            </div>

            @if ($stockCardReport['requires_product'])
                <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                    <p class="font-semibold">{{ $stockCardReport['message'] }}</p>
                    <p class="mt-1">Gunakan filter Produk. Jika tanggal tidak diisi, sistem memakai periode bulan berjalan.</p>
                </div>
            @else
                <div class="mt-4 hidden overflow-x-auto rounded-lg border border-gray-200 md:block">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-gray-500">
                                <th scope="col" class="px-3 py-2 font-medium">Tanggal</th>
                                <th scope="col" class="px-3 py-2 font-medium">Cabang</th>
                                <th scope="col" class="px-3 py-2 font-medium">Produk</th>
                                <th scope="col" class="px-3 py-2 font-medium">Lokasi/Ruangan</th>
                                <th scope="col" class="px-3 py-2 font-medium">Tipe Movement</th>
                                <th scope="col" class="px-3 py-2 text-right font-medium">Masuk</th>
                                <th scope="col" class="px-3 py-2 text-right font-medium">Keluar</th>
                                <th scope="col" class="px-3 py-2 text-right font-medium">Saldo</th>
                                <th scope="col" class="px-3 py-2 font-medium">Referensi</th>
                                <th scope="col" class="px-3 py-2 font-medium">Dibuat Oleh</th>
                                <th scope="col" class="px-3 py-2 font-medium">Catatan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @forelse ($stockCardRows as $movement)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-3 py-2 tabular-nums text-gray-700">{{ $movement->movement_date?->format('d M Y') }}</td>
                                    <td class="px-3 py-2 text-gray-700">{{ $movement->branch?->name ?? '-' }}</td>
                                    <td class="px-3 py-2 text-gray-700">
                                        <span class="font-medium text-gray-900">{{ $movement->product?->code }}</span>
                                        <span class="block text-xs text-gray-500">{{ $movement->product?->name }}</span>
                                    </td>
                                    <td class="px-3 py-2 text-gray-700">{{ $movement->inventoryLocation?->name ?? '-' }}</td>
                                    <td class="px-3 py-2">
                                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">{{ $movement->movement_type }}</span>
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums text-emerald-700">{{ number_format((float) $movement->quantity_in, 4) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-rose-700">{{ number_format((float) $movement->quantity_out, 4) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums font-semibold text-gray-900">{{ number_format((float) $movement->running_balance, 4) }}</td>
                                    <td class="px-3 py-2 text-gray-700">{{ $movement->reference_label }}</td>
                                    <td class="px-3 py-2 text-gray-700">{{ $movement->createdBy?->name ?? '-' }}</td>
                                    <td class="px-3 py-2 text-gray-700">{{ $movement->notes ?: '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="11" class="px-3 py-6 text-center text-sm text-gray-500">Tidak ada pergerakan stok pada periode yang dipilih.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 divide-y divide-gray-100 rounded-lg border border-gray-200 md:hidden">
                    @forelse ($stockCardRows as $movement)
                        <article class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-xs uppercase tracking-wide text-gray-500">{{ $movement->movement_date?->format('d M Y') }} &middot; {{ $movement->movement_type }}</p>
                                    <h4 class="mt-1 text-base font-semibold text-gray-900">{{ $movement->product?->code }} - {{ $movement->product?->name }}</h4>
                                    <p class="mt-1 text-sm text-gray-500">{{ $movement->inventoryLocation?->name ?? '-' }}</p>
                                </div>
                                <span class="inline-flex shrink-0 items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">{{ $movement->branch?->name ?? '-' }}</span>
                            </div>
                            <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                                <div class="rounded-lg bg-gray-50 p-3">
                                    <dt class="text-xs text-gray-500">Masuk</dt>
                                    <dd class="mt-1 font-semibold tabular-nums text-emerald-700">{{ number_format((float) $movement->quantity_in, 4) }}</dd>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-3">
                                    <dt class="text-xs text-gray-500">Keluar</dt>
                                    <dd class="mt-1 font-semibold tabular-nums text-rose-700">{{ number_format((float) $movement->quantity_out, 4) }}</dd>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-3">
                                    <dt class="text-xs text-gray-500">Saldo</dt>
                                    <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ number_format((float) $movement->running_balance, 4) }}</dd>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-3">
                                    <dt class="text-xs text-gray-500">Dibuat Oleh</dt>
                                    <dd class="mt-1 font-semibold text-gray-900">{{ $movement->createdBy?->name ?? '-' }}</dd>
                                </div>
                            </dl>
                            <p class="mt-3 text-sm text-gray-500">Referensi: {{ $movement->reference_label }}</p>
                            <p class="mt-1 text-sm text-gray-500">Catatan: {{ $movement->notes ?: '-' }}</p>
                        </article>
                    @empty
                        <p class="p-4 text-center text-sm text-gray-500">Tidak ada pergerakan stok pada periode yang dipilih.</p>
                    @endforelse
                </div>

                @if ($stockCardRows->hasPages())
                    <div class="mt-4 border-t border-gray-200 pt-4">
                        {{ $stockCardRows->links() }}
                    </div>
                @endif
            @endif
        </section>
        @endif

        @if ($activeTab === 'low_stock')
        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm" data-report-panel="low-stock">
            <h3 class="text-base font-semibold text-gray-900">Low Stock</h3>
            <p class="mt-1 text-sm text-gray-500">Produk kosong atau di bawah minimum produk, dihitung dari saldo ledger per lokasi.</p>
            <div class="mt-3">
                <a href="{{ route('inventory.reports.export', $exportFilters) }}"
                   class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Export CSV
                </a>
            </div>

            <div class="mt-4 hidden overflow-x-auto rounded-lg border border-gray-200 md:block">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-gray-500">
                            <th scope="col" class="px-3 py-2 font-medium">Cabang</th>
                            <th scope="col" class="px-3 py-2 font-medium">Kode Produk</th>
                            <th scope="col" class="px-3 py-2 font-medium">Produk</th>
                            <th scope="col" class="px-3 py-2 font-medium">Kategori</th>
                            <th scope="col" class="px-3 py-2 font-medium">Satuan</th>
                            <th scope="col" class="px-3 py-2 font-medium">Lokasi/Ruangan</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">Stok Saat Ini</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">Minimum</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">Kekurangan</th>
                            <th scope="col" class="px-3 py-2 font-medium">Status</th>
                            <th scope="col" class="px-3 py-2 font-medium">Rekomendasi</th>
                            <th scope="col" class="px-3 py-2 font-medium">Movement Terakhir</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse ($lowStockReport as $row)
                            @php
                                $statusLabel = $row->stock_status === 'empty' ? 'Kosong' : 'Low Stock';
                                $statusClasses = $row->stock_status === 'empty'
                                    ? 'bg-rose-50 text-rose-700'
                                    : 'bg-amber-50 text-amber-700';
                            @endphp
                            <tr @class([
                                'hover:bg-gray-50',
                                'bg-rose-50/40' => $row->stock_status === 'empty',
                                'bg-amber-50/40' => $row->stock_status === 'low',
                            ])>
                                <td class="px-3 py-2 text-gray-700">{{ $row->branch_name }}</td>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $row->product_code }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $row->product_name }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $row->category_name }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $row->unit_symbol ?: $row->unit_name }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $row->inventory_location_name }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-900">{{ number_format((float) $row->current_stock, 4) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-700">{{ number_format((float) $row->minimum_stock, 4) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums font-semibold text-rose-700">{{ number_format((float) $row->shortage_qty, 4) }}</td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClasses }}">{{ $statusLabel }}</span>
                                </td>
                                <td class="px-3 py-2 text-gray-700">{{ $row->recommendation }}</td>
                                <td class="px-3 py-2 tabular-nums text-gray-700">
                                    {{ $row->last_movement_date ? \Illuminate\Support\Carbon::parse($row->last_movement_date)->format('d M Y') : '-' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="12" class="px-3 py-6 text-center text-sm text-gray-500">Tidak ada barang low stock untuk filter yang dipilih.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4 divide-y divide-gray-100 rounded-lg border border-gray-200 md:hidden">
                @forelse ($lowStockReport as $row)
                    @php
                        $statusLabel = $row->stock_status === 'empty' ? 'Kosong' : 'Low Stock';
                        $statusClasses = $row->stock_status === 'empty'
                            ? 'bg-rose-50 text-rose-700'
                            : 'bg-amber-50 text-amber-700';
                    @endphp
                    <article class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-xs uppercase tracking-wide text-gray-500">{{ $row->branch_name }} &middot; {{ $row->product_code }}</p>
                                <h4 class="mt-1 text-base font-semibold text-gray-900">{{ $row->product_name }}</h4>
                                <p class="mt-1 text-sm text-gray-500">{{ $row->category_name }} &middot; {{ $row->inventory_location_name }}</p>
                            </div>
                            <span class="inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClasses }}">{{ $statusLabel }}</span>
                        </div>
                        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div class="rounded-lg bg-gray-50 p-3">
                                <dt class="text-xs text-gray-500">Stok Saat Ini</dt>
                                <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ number_format((float) $row->current_stock, 4) }}</dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3">
                                <dt class="text-xs text-gray-500">Minimum</dt>
                                <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ number_format((float) $row->minimum_stock, 4) }}</dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3">
                                <dt class="text-xs text-gray-500">Kekurangan</dt>
                                <dd class="mt-1 font-semibold tabular-nums text-rose-700">{{ number_format((float) $row->shortage_qty, 4) }}</dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3">
                                <dt class="text-xs text-gray-500">Satuan</dt>
                                <dd class="mt-1 font-semibold text-gray-900">{{ $row->unit_symbol ?: $row->unit_name }}</dd>
                            </div>
                        </dl>
                        <p class="mt-3 text-sm font-medium text-gray-900">{{ $row->recommendation }}</p>
                        <p class="mt-1 text-sm text-gray-500">Movement terakhir: {{ $row->last_movement_date ? \Illuminate\Support\Carbon::parse($row->last_movement_date)->format('d M Y') : '-' }}</p>
                    </article>
                @empty
                    <p class="p-4 text-center text-sm text-gray-500">Tidak ada barang low stock untuk filter yang dipilih.</p>
                @endforelse
            </div>

            @if ($lowStockReport->hasPages())
                <div class="mt-4 border-t border-gray-200 pt-4">
                    {{ $lowStockReport->links() }}
                </div>
            @endif
        </section>
        @endif

        @if ($activeTab === 'mutation')
        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm" data-report-panel="mutation">
            <h3 class="text-base font-semibold text-gray-900">Mutasi Stok</h3>
            <p class="mt-1 text-sm text-gray-500">Pergerakan masuk dan keluar berdasarkan ledger inventory.</p>
            <div class="mt-3">
                <a href="{{ route('inventory.reports.export', $exportFilters) }}"
                   class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Export CSV
                </a>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Periode Aktif</p>
                    <p class="mt-1 text-sm font-semibold tabular-nums text-gray-900">
                        {{ \Illuminate\Support\Carbon::parse($stockMutationDateFrom)->format('d M Y') }}
                        -
                        {{ \Illuminate\Support\Carbon::parse($stockMutationDateTo)->format('d M Y') }}
                    </p>
                </div>
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Sumber</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900">trx_inventory_movements</p>
                </div>
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Catatan Tipe</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900">Filter tipe hanya untuk mutasi periode</p>
                </div>
            </div>

            <div class="mt-4 hidden overflow-x-auto rounded-lg border border-gray-200 md:block">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-gray-500">
                            <th scope="col" class="px-3 py-2 font-medium">Cabang</th>
                            <th scope="col" class="px-3 py-2 font-medium">Kode Produk</th>
                            <th scope="col" class="px-3 py-2 font-medium">Produk</th>
                            <th scope="col" class="px-3 py-2 font-medium">Kategori</th>
                            <th scope="col" class="px-3 py-2 font-medium">Satuan</th>
                            <th scope="col" class="px-3 py-2 font-medium">Lokasi/Ruangan</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">Saldo Awal</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">Masuk</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">Keluar</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">Saldo Akhir</th>
                            <th scope="col" class="px-3 py-2 font-medium">Periode</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse ($stockMutationReport as $row)
                            <tr class="hover:bg-gray-50">
                                <td class="px-3 py-2 text-gray-700">{{ $row->branch_name }}</td>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $row->product_code }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $row->product_name }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $row->category_name }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $row->unit_symbol ?: $row->unit_name }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $row->inventory_location_name }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-900">{{ number_format((float) $row->opening_balance, 4) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-emerald-700">{{ number_format((float) $row->total_in, 4) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-rose-700">{{ number_format((float) $row->total_out, 4) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums font-semibold text-gray-900">{{ number_format((float) $row->ending_balance, 4) }}</td>
                                <td class="px-3 py-2 tabular-nums text-gray-700">{{ $row->period_label }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="px-3 py-6 text-center text-sm text-gray-500">Tidak ada mutasi stok pada periode yang dipilih.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4 divide-y divide-gray-100 rounded-lg border border-gray-200 md:hidden">
                @forelse ($stockMutationReport as $row)
                    <article class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-xs uppercase tracking-wide text-gray-500">{{ $row->branch_name }} &middot; {{ $row->product_code }}</p>
                                <h4 class="mt-1 text-base font-semibold text-gray-900">{{ $row->product_name }}</h4>
                                <p class="mt-1 text-sm text-gray-500">{{ $row->category_name }} &middot; {{ $row->inventory_location_name }}</p>
                            </div>
                            <span class="inline-flex shrink-0 items-center rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700">{{ $row->unit_symbol ?: $row->unit_name }}</span>
                        </div>
                        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div class="rounded-lg bg-gray-50 p-3">
                                <dt class="text-xs text-gray-500">Saldo Awal</dt>
                                <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ number_format((float) $row->opening_balance, 4) }}</dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3">
                                <dt class="text-xs text-gray-500">Saldo Akhir</dt>
                                <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ number_format((float) $row->ending_balance, 4) }}</dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3">
                                <dt class="text-xs text-gray-500">Masuk</dt>
                                <dd class="mt-1 font-semibold tabular-nums text-emerald-700">{{ number_format((float) $row->total_in, 4) }}</dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3">
                                <dt class="text-xs text-gray-500">Keluar</dt>
                                <dd class="mt-1 font-semibold tabular-nums text-rose-700">{{ number_format((float) $row->total_out, 4) }}</dd>
                            </div>
                        </dl>
                        <p class="mt-3 text-sm text-gray-500">Periode: {{ $row->period_label }}</p>
                    </article>
                @empty
                    <p class="p-4 text-center text-sm text-gray-500">Tidak ada mutasi stok pada periode yang dipilih.</p>
                @endforelse
            </div>

            @if ($stockMutationReport->hasPages())
                <div class="mt-4 border-t border-gray-200 pt-4">
                    {{ $stockMutationReport->links() }}
                </div>
            @endif
        </section>
        @endif

        @if ($activeTab === 'valuation')
        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm" data-report-panel="valuation">
            <h3 class="text-base font-semibold text-gray-900">Nilai Persediaan</h3>
            <p class="mt-1 text-sm text-gray-500">Nilai persediaan bersifat estimasi operasional berdasarkan harga/cost produk yang tersedia.</p>
            <div class="mt-3">
                <a href="{{ route('inventory.reports.export', $exportFilters) }}"
                   class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Export CSV
                </a>
            </div>

            <div class="mt-4 hidden overflow-x-auto rounded-lg border border-gray-200 md:block">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-gray-500">
                            <th scope="col" class="px-3 py-2 font-medium">Cabang</th>
                            <th scope="col" class="px-3 py-2 font-medium">Kode Produk</th>
                            <th scope="col" class="px-3 py-2 font-medium">Produk</th>
                            <th scope="col" class="px-3 py-2 font-medium">Kategori</th>
                            <th scope="col" class="px-3 py-2 font-medium">Satuan</th>
                            <th scope="col" class="px-3 py-2 font-medium">Lokasi/Ruangan</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">Stok Saat Ini</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">Unit Cost</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">Total Nilai</th>
                            <th scope="col" class="px-3 py-2 font-medium">Sumber</th>
                            <th scope="col" class="px-3 py-2 font-medium">Movement Terakhir</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse ($inventoryValuationReport as $row)
                            <tr @class([
                                'hover:bg-gray-50',
                                'bg-rose-50/40' => (float) $row->current_stock < 0,
                            ])>
                                <td class="px-3 py-2 text-gray-700">{{ $row->branch_name }}</td>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $row->product_code }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $row->product_name }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $row->category_name }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $row->unit_symbol ?: $row->unit_name }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $row->inventory_location_name }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-900">{{ number_format((float) $row->current_stock, 4) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-700">{{ (float) $row->unit_cost > 0 ? format_currency_id($row->unit_cost) : '-' }}</td>
                                <td class="px-3 py-2 text-right tabular-nums font-semibold text-gray-900">{{ format_currency_id($row->total_value) }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $row->valuation_source }}</td>
                                <td class="px-3 py-2 tabular-nums text-gray-700">
                                    {{ $row->last_movement_date ? \Illuminate\Support\Carbon::parse($row->last_movement_date)->format('d M Y') : '-' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="px-3 py-6 text-center text-sm text-gray-500">Tidak ada data nilai persediaan untuk filter yang dipilih.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4 divide-y divide-gray-100 rounded-lg border border-gray-200 md:hidden">
                @forelse ($inventoryValuationReport as $row)
                    <article @class([
                        'p-4',
                        'bg-rose-50/40' => (float) $row->current_stock < 0,
                    ])>
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-xs uppercase tracking-wide text-gray-500">{{ $row->branch_name }} &middot; {{ $row->product_code }}</p>
                                <h4 class="mt-1 text-base font-semibold text-gray-900">{{ $row->product_name }}</h4>
                                <p class="mt-1 text-sm text-gray-500">{{ $row->category_name }} &middot; {{ $row->inventory_location_name }}</p>
                            </div>
                            <span class="inline-flex shrink-0 items-center rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700">{{ $row->unit_symbol ?: $row->unit_name }}</span>
                        </div>
                        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div class="rounded-lg bg-gray-50 p-3">
                                <dt class="text-xs text-gray-500">Stok Saat Ini</dt>
                                <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ number_format((float) $row->current_stock, 4) }}</dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3">
                                <dt class="text-xs text-gray-500">Unit Cost</dt>
                                <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ (float) $row->unit_cost > 0 ? format_currency_id($row->unit_cost) : '-' }}</dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3">
                                <dt class="text-xs text-gray-500">Total Nilai</dt>
                                <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ format_currency_id($row->total_value) }}</dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3">
                                <dt class="text-xs text-gray-500">Movement Terakhir</dt>
                                <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ $row->last_movement_date ? \Illuminate\Support\Carbon::parse($row->last_movement_date)->format('d M Y') : '-' }}</dd>
                            </div>
                        </dl>
                        <p class="mt-3 text-sm text-gray-500">Sumber: {{ $row->valuation_source }}</p>
                    </article>
                @empty
                    <p class="p-4 text-center text-sm text-gray-500">Tidak ada data nilai persediaan untuk filter yang dipilih.</p>
                @endforelse
            </div>

            @if ($inventoryValuationReport->hasPages())
                <div class="mt-4 border-t border-gray-200 pt-4">
                    {{ $inventoryValuationReport->links() }}
                </div>
            @endif
        </section>
        @endif

        @if ($activeTab === 'room_stock')
        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm" data-report-panel="room-stock">
            <h3 class="text-base font-semibold text-gray-900">Stok per Ruangan</h3>
            <p class="mt-1 text-sm text-sky-700">Ruangan menggunakan data Lokasi Inventory.</p>
            <p class="mt-1 text-sm text-gray-500">Minimum/maksimum per ruangan diambil dari konfigurasi Minimum Stok Ruangan. Bila ruangan belum dikonfigurasi, minimum mengikuti minimum produk. Produk dengan ambang per ruangan tetap tampil meski belum ada pergerakan.</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <a href="{{ route('inventory.reports.export', $exportFilters) }}"
                   class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Export CSV
                </a>
                <a href="{{ route('inventory.reports.room-stock.refill-checklist', $exportFilters) }}"
                   class="inline-flex items-center rounded-lg border border-teal-600 bg-teal-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Cetak Checklist Refill
                </a>
            </div>

            <div class="mt-4 hidden overflow-x-auto rounded-lg border border-gray-200 md:block">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-gray-500">
                            <th scope="col" class="px-3 py-2 font-medium">Cabang</th>
                            <th scope="col" class="px-3 py-2 font-medium">Ruangan / Lokasi</th>
                            <th scope="col" class="px-3 py-2 font-medium">Kode Produk</th>
                            <th scope="col" class="px-3 py-2 font-medium">Produk</th>
                            <th scope="col" class="px-3 py-2 font-medium">Kategori</th>
                            <th scope="col" class="px-3 py-2 font-medium">Satuan</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">Stok Saat Ini</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">Min. Ruangan</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">Maks. Ruangan</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">Saran Refill</th>
                            <th scope="col" class="px-3 py-2 font-medium">Status</th>
                            <th scope="col" class="px-3 py-2 font-medium">Rekomendasi Refill</th>
                            <th scope="col" class="px-3 py-2 font-medium">Movement Terakhir</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse ($roomStockReport as $row)
                            @php
                                $statusLabel = match ($row->stock_status) {
                                    'empty' => 'Kosong',
                                    'low' => 'Low Stock',
                                    'overstock' => 'Overstock',
                                    default => 'Normal',
                                };
                                $statusClasses = match ($row->stock_status) {
                                    'empty' => 'bg-rose-50 text-rose-700',
                                    'low' => 'bg-amber-50 text-amber-700',
                                    'overstock' => 'bg-sky-50 text-sky-700',
                                    default => 'bg-emerald-50 text-emerald-700',
                                };
                            @endphp
                            <tr @class([
                                'hover:bg-gray-50',
                                'bg-rose-50/40' => $row->stock_status === 'empty',
                                'bg-amber-50/40' => $row->stock_status === 'low',
                            ])>
                                <td class="px-3 py-2 text-gray-700">{{ $row->branch_name }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $row->inventory_location_name }}</td>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $row->product_code }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $row->product_name }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $row->category_name }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $row->unit_symbol ?: $row->unit_name }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-900">{{ number_format((float) $row->current_stock, 4) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-700">{{ number_format((float) $row->minimum_stock, 4) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-700">{{ $row->maximum_stock !== null ? number_format((float) $row->maximum_stock, 4) : '-' }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-gray-700">{{ number_format((float) $row->suggested_refill_qty, 4) }}</td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClasses }}">{{ $statusLabel }}</span>
                                </td>
                                <td class="px-3 py-2 text-gray-700">{{ $row->recommendation }}</td>
                                <td class="px-3 py-2 tabular-nums text-gray-700">
                                    {{ $row->last_movement_date ? \Illuminate\Support\Carbon::parse($row->last_movement_date)->format('d M Y') : '-' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="13" class="px-3 py-6 text-center text-sm text-gray-500">Tidak ada stok pada ruangan yang dipilih.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4 divide-y divide-gray-100 rounded-lg border border-gray-200 md:hidden">
                @forelse ($roomStockReport as $row)
                    @php
                        $statusLabel = match ($row->stock_status) {
                            'empty' => 'Kosong',
                            'low' => 'Low Stock',
                            'overstock' => 'Overstock',
                            default => 'Normal',
                        };
                        $statusClasses = match ($row->stock_status) {
                            'empty' => 'bg-rose-50 text-rose-700',
                            'low' => 'bg-amber-50 text-amber-700',
                            'overstock' => 'bg-sky-50 text-sky-700',
                            default => 'bg-emerald-50 text-emerald-700',
                        };
                    @endphp
                    <article @class([
                        'p-4',
                        'bg-rose-50/40' => $row->stock_status === 'empty',
                        'bg-amber-50/40' => $row->stock_status === 'low',
                    ])>
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-xs uppercase tracking-wide text-gray-500">{{ $row->branch_name }} &middot; {{ $row->inventory_location_name }}</p>
                                <h4 class="mt-1 text-base font-semibold text-gray-900">{{ $row->product_code }} - {{ $row->product_name }}</h4>
                                <p class="mt-1 text-sm text-gray-500">{{ $row->category_name }} &middot; {{ $row->unit_symbol ?: $row->unit_name }}</p>
                            </div>
                            <span class="inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClasses }}">{{ $statusLabel }}</span>
                        </div>
                        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div class="rounded-lg bg-gray-50 p-3">
                                <dt class="text-xs text-gray-500">Stok Saat Ini</dt>
                                <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ number_format((float) $row->current_stock, 4) }}</dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3">
                                <dt class="text-xs text-gray-500">Min. Ruangan</dt>
                                <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ number_format((float) $row->minimum_stock, 4) }}</dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3">
                                <dt class="text-xs text-gray-500">Maks. Ruangan</dt>
                                <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ $row->maximum_stock !== null ? number_format((float) $row->maximum_stock, 4) : '-' }}</dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3">
                                <dt class="text-xs text-gray-500">Saran Refill</dt>
                                <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ number_format((float) $row->suggested_refill_qty, 4) }}</dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3">
                                <dt class="text-xs text-gray-500">Movement Terakhir</dt>
                                <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ $row->last_movement_date ? \Illuminate\Support\Carbon::parse($row->last_movement_date)->format('d M Y') : '-' }}</dd>
                            </div>
                        </dl>
                        <p class="mt-3 text-sm font-medium text-gray-900">{{ $row->recommendation }}</p>
                    </article>
                @empty
                    <p class="p-4 text-center text-sm text-gray-500">Tidak ada stok pada ruangan yang dipilih.</p>
                @endforelse
            </div>

            @if ($roomStockReport->hasPages())
                <div class="mt-4 border-t border-gray-200 pt-4">
                    {{ $roomStockReport->links() }}
                </div>
            @endif
        </section>
        @endif
    </div>
</x-settings-shell>
