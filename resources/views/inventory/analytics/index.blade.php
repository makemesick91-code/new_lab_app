@php
    use App\Modules\Inventory\Services\InventoryAnalyticsService;

    $dateFrom = $filters['date_from'] ?? now()->subDays(InventoryAnalyticsService::DEFAULT_PERIOD_DAYS)->toDateString();
    $dateTo = $filters['date_to'] ?? now()->toDateString();
    $deadStockDays = $filters['dead_stock_days'] ?? InventoryAnalyticsService::DEFAULT_DEAD_STOCK_DAYS;
    $slowThreshold = $filters['slow_moving_threshold'] ?? InventoryAnalyticsService::DEFAULT_SLOW_THRESHOLD;
    $limit = $filters['limit'] ?? InventoryAnalyticsService::DEFAULT_FAST_LIMIT;
    $agingGranularity = $filters['aging_granularity'] ?? 'product';

    $sectionNav = $tabs ?? [];
    $fastMoving = collect($fastMoving ?? []);
    $slowMoving = collect($slowMoving ?? []);
    $deadStock = collect($deadStock ?? []);
    $turnover = collect($turnover ?? []);
    $valueByCategory = collect($valueByCategory ?? []);
    $valueByLocation = collect($valueByLocation ?? []);
    $outboundTrend = $outboundTrend ?? [];
    $aging = $aging ?? ['granularity' => $agingGranularity, 'buckets' => [], 'items' => collect()];
    if (! ($aging['items'] ?? null) instanceof \Illuminate\Support\Collection) {
        $aging['items'] = collect($aging['items'] ?? []);
    }
@endphp

<x-settings-shell title="Analitik Persediaan">
    <div class="space-y-6">
        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Analitik Persediaan</p>
                    <h1 class="mt-1 text-2xl font-semibold text-gray-900">Analitik persediaan cabang aktif</h1>
                    <p class="mt-2 max-w-3xl text-sm text-gray-600">
                        Semua metrik dihitung dari ledger pergerakan persediaan (<code class="text-xs">trx_inventory_movements</code>).
                        Stok saat ini = jumlah masuk − jumlah keluar. Tidak ada kolom stok mutable yang digunakan.
                    </p>
                </div>
                <a href="{{ route('inventory.dashboard') }}" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Kembali ke Dasbor
                </a>
            </div>
        </section>

        @include('inventory.analytics._meta-hint')

        <form method="GET" action="{{ route('inventory.analytics.index') }}" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm lg:sticky lg:top-4 lg:z-10">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-8 lg:items-end">
                <div>
                    <label for="analytics-date-from" class="text-sm font-medium text-gray-700">Tanggal Mulai</label>
                    <input id="analytics-date-from" type="date" name="date_from" value="{{ $dateFrom }}"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div>
                    <label for="analytics-date-to" class="text-sm font-medium text-gray-700">Tanggal Akhir</label>
                    <input id="analytics-date-to" type="date" name="date_to" value="{{ $dateTo }}"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div>
                    <label for="analytics-location" class="text-sm font-medium text-gray-700">Lokasi</label>
                    <select id="analytics-location" name="location_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">Semua lokasi</option>
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}" @selected((int) ($filters['location_id'] ?? 0) === $location->id)>{{ $location->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="analytics-category" class="text-sm font-medium text-gray-700">Kategori</label>
                    <select id="analytics-category" name="category_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">Semua kategori</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected((int) ($filters['category_id'] ?? 0) === $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="analytics-dead-days" class="text-sm font-medium text-gray-700">Hari Stok Mati</label>
                    <input id="analytics-dead-days" type="number" name="dead_stock_days" min="1" max="365" value="{{ $deadStockDays }}"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div>
                    <label for="analytics-slow-threshold" class="text-sm font-medium text-gray-700">Ambang Lambat</label>
                    <input id="analytics-slow-threshold" type="number" name="slow_moving_threshold" min="0" step="0.01" value="{{ $slowThreshold }}"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div>
                    <label for="analytics-limit" class="text-sm font-medium text-gray-700">Batas Baris</label>
                    <input id="analytics-limit" type="number" name="limit" min="1" max="100" value="{{ $limit }}"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div>
                    <label for="analytics-aging-granularity" class="text-sm font-medium text-gray-700">Umur Persediaan</label>
                    <select id="analytics-aging-granularity" name="aging_granularity" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="product" @selected($agingGranularity === 'product')>Per Produk</option>
                        <option value="batch" @selected($agingGranularity === 'batch')>Per Batch</option>
                    </select>
                </div>
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
                <button type="submit" class="inline-flex justify-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-700 focus:ring-offset-2">
                    Terapkan
                </button>
                <a href="{{ route('inventory.analytics.index') }}" class="inline-flex justify-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Atur Ulang
                </a>
            </div>
        </form>

        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm" aria-labelledby="analytics-summary">
            <h2 id="analytics-summary" class="text-lg font-semibold text-gray-900">Ringkasan Analitik</h2>
            <p class="mt-1 text-xs text-gray-500">
                Periode: {{ format_date_id($summary['period_from'] ?? $dateFrom) }} s/d {{ format_date_id($summary['period_to'] ?? $dateTo) }}
            </p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <x-inventory.kpi-card
                    label="Produk Cepat Bergerak"
                    :value="format_number_id((int) ($summary['fast_moving_count'] ?? 0))"
                    hint="Keluar tertinggi dalam periode"
                    tone="primary"
                />
                <x-inventory.kpi-card
                    label="Produk Lambat Bergerak"
                    :value="format_number_id((int) ($summary['slow_moving_count'] ?? 0))"
                    hint="Stok positif, keluar rendah"
                    tone="warning"
                />
                <x-inventory.kpi-card
                    label="Stok Mati"
                    :value="format_number_id((int) ($summary['dead_stock_count'] ?? 0))"
                    hint="Tanpa keluar dalam ambang hari"
                    tone="danger"
                />
                <x-inventory.kpi-card
                    label="Nilai Persediaan"
                    :value="format_currency_id($summary['inventory_value'] ?? 0)"
                    hint="Stok saat ini × biaya rata-rata"
                    tone="neutral"
                />
                <x-inventory.kpi-card
                    label="Nilai Keluar Bulanan"
                    :value="format_currency_id($summary['period_outbound_value'] ?? 0)"
                    hint="Total nilai keluar dalam periode"
                    tone="info"
                />
                <x-inventory.kpi-card
                    label="Perputaran Persediaan"
                    :value="($summary['branch_turnover_ratio'] ?? null) !== null ? format_number_id($summary['branch_turnover_ratio'], 2) : '—'"
                    hint="Total keluar ÷ rata-rata stok (periode)"
                    tone="success"
                />
            </div>
        </section>

        <nav class="flex flex-wrap gap-2 rounded-lg border border-gray-200 bg-white p-3 shadow-sm" aria-label="Navigasi bagian analitik">
            @foreach ($sectionNav as $section)
                <a
                    href="{{ route('inventory.analytics.index', array_merge($filters, ['tab' => $section['key']])) }}#{{ $section['id'] }}"
                    class="rounded-md px-3 py-2 text-sm font-medium {{ $tab === $section['key'] ? 'bg-teal-50 text-teal-800 ring-1 ring-teal-200' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}"
                >
                    {{ $section['label'] }}
                </a>
            @endforeach
        </nav>

        <aside class="rounded-lg border border-amber-100 bg-amber-50 p-4 text-sm text-amber-900">
            <p class="font-semibold">Catatan Penting</p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-amber-800">
                <li>Semua stok dihitung dari ledger pergerakan — bukan kolom stok tersimpan.</li>
                <li>Tren Nilai Keluar menunjukkan nilai keluar bulanan, <strong>bukan</strong> nilai stok historis on-hand.</li>
                <li>Umur produk tanpa batch memakai tanggal masuk terakhir sebagai perkiraan (bukan FIFO penuh).</li>
            </ul>
        </aside>

        @if ($tab === 'summary')
            @include('inventory.analytics._tab-summary')
        @endif

        @if (in_array($tab, ['movement', 'fast'], true))
        {{-- Produk Cepat Bergerak --}}
        <section id="section-fast" class="rounded-lg border border-gray-200 bg-white shadow-sm scroll-mt-24">
            <div class="border-b border-gray-200 px-4 py-3">
                <h2 class="text-base font-semibold text-gray-900">Produk Cepat Bergerak</h2>
                <p class="text-sm text-gray-500">Diurutkan berdasarkan jumlah keluar tertinggi dalam periode.</p>
            </div>
            @if ($fastMoving->isEmpty())
                @include('inventory.analytics._empty-state')
            @else
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-gray-500">
                                <th scope="col" class="px-4 py-3 font-medium">Produk</th>
                                <th scope="col" class="px-3 py-3 font-medium">Kategori</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Jumlah Keluar</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Nilai Keluar</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Stok Saat Ini</th>
                                <th scope="col" class="px-4 py-3 text-right font-medium">Nilai Stok</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($fastMoving as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3">
                                        <p class="font-medium text-gray-900">{{ $row['product_name'] }}</p>
                                        <p class="text-xs text-gray-500">{{ $row['product_code'] }}</p>
                                    </td>
                                    <td class="px-3 py-3 text-gray-600">{{ $row['category_name'] ?? '—' }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums text-gray-900">{{ format_quantity_id($row['outbound_qty_period'] ?? 0) }} {{ $row['unit_symbol'] ?? '' }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_currency_id($row['outbound_value_period'] ?? 0) }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_quantity_id($row['current_stock'] ?? 0) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-gray-700">{{ format_currency_id($row['stock_value'] ?? 0) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="divide-y divide-gray-100 md:hidden analytics-mobile-cards">
                    @foreach ($fastMoving as $row)
                        <article class="p-4">
                            <h3 class="font-semibold text-gray-900">{{ $row['product_name'] }}</h3>
                            <p class="text-xs text-gray-500">{{ $row['product_code'] }}</p>
                            <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                                <div>
                                    <dt class="text-gray-500">Jumlah Keluar</dt>
                                    <dd class="font-medium tabular-nums text-gray-900">{{ format_quantity_id($row['outbound_qty_period'] ?? 0) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500">Stok Saat Ini</dt>
                                    <dd class="font-medium tabular-nums text-gray-900">{{ format_quantity_id($row['current_stock'] ?? 0) }}</dd>
                                </div>
                                <div class="col-span-2">
                                    <dt class="text-gray-500">Nilai Keluar</dt>
                                    <dd class="font-medium tabular-nums text-gray-900">{{ format_currency_id($row['outbound_value_period'] ?? 0) }}</dd>
                                </div>
                            </dl>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
        @endif

        @if (in_array($tab, ['movement', 'slow'], true))
        {{-- Produk Lambat Bergerak --}}
        <section id="section-slow" class="rounded-lg border border-gray-200 bg-white shadow-sm scroll-mt-24">
            <div class="border-b border-gray-200 px-4 py-3">
                <h2 class="text-base font-semibold text-gray-900">Produk Lambat Bergerak</h2>
                <p class="text-sm text-gray-500">Stok positif dengan keluar ≤ ambang pergerakan lambat ({{ format_quantity_id($slowThreshold) }}).</p>
            </div>
            @if ($slowMoving->isEmpty())
                @include('inventory.analytics._empty-state')
            @else
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-gray-500">
                                <th scope="col" class="px-4 py-3 font-medium">Produk</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Jumlah Keluar</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Stok Saat Ini</th>
                                <th scope="col" class="px-4 py-3 text-right font-medium">Nilai Stok</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($slowMoving as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3">
                                        <p class="font-medium text-gray-900">{{ $row['product_name'] }}</p>
                                        <p class="text-xs text-gray-500">{{ $row['product_code'] }}</p>
                                    </td>
                                    <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_quantity_id($row['outbound_qty_period'] ?? 0) }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_quantity_id($row['current_stock'] ?? 0) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-gray-700">{{ format_currency_id($row['stock_value'] ?? 0) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="divide-y divide-gray-100 md:hidden analytics-mobile-cards">
                    @foreach ($slowMoving as $row)
                        <article class="p-4">
                            <h3 class="font-semibold text-gray-900">{{ $row['product_name'] }}</h3>
                            <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                                <div>
                                    <dt class="text-gray-500">Jumlah Keluar</dt>
                                    <dd class="font-medium tabular-nums">{{ format_quantity_id($row['outbound_qty_period'] ?? 0) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500">Stok Saat Ini</dt>
                                    <dd class="font-medium tabular-nums">{{ format_quantity_id($row['current_stock'] ?? 0) }}</dd>
                                </div>
                            </dl>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
        @endif

        @if (in_array($tab, ['movement', 'dead'], true))
        {{-- Stok Mati --}}
        <section id="section-dead" class="rounded-lg border border-gray-200 bg-white shadow-sm scroll-mt-24">
            <div class="border-b border-gray-200 px-4 py-3">
                <h2 class="text-base font-semibold text-gray-900">Stok Mati</h2>
                <p class="text-sm text-gray-500">Stok positif tanpa keluar dalam {{ format_number_id((int) $deadStockDays) }} hari terakhir.</p>
            </div>
            @if ($deadStock->isEmpty())
                @include('inventory.analytics._empty-state')
            @else
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-gray-500">
                                <th scope="col" class="px-4 py-3 font-medium">Produk</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Stok Saat Ini</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Hari Tanpa Keluar</th>
                                <th scope="col" class="px-3 py-3 font-medium">Tanggal Keluar Terakhir</th>
                                <th scope="col" class="px-4 py-3 text-right font-medium">Nilai Stok</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($deadStock as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3">
                                        <p class="font-medium text-gray-900">{{ $row['product_name'] }}</p>
                                        <p class="text-xs text-gray-500">{{ $row['product_code'] }}</p>
                                    </td>
                                    <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_quantity_id($row['current_stock'] ?? 0) }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ $row['days_since_last_out'] !== null ? format_number_id($row['days_since_last_out']) : '—' }}</td>
                                    <td class="px-3 py-3 tabular-nums text-gray-700">{{ $row['last_out_date'] ? format_date_id($row['last_out_date']) : 'Belum pernah keluar' }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-gray-700">{{ format_currency_id($row['stock_value'] ?? 0) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="divide-y divide-gray-100 md:hidden analytics-mobile-cards">
                    @foreach ($deadStock as $row)
                        <article class="p-4">
                            <h3 class="font-semibold text-gray-900">{{ $row['product_name'] }}</h3>
                            <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                                <div>
                                    <dt class="text-gray-500">Stok Saat Ini</dt>
                                    <dd class="font-medium tabular-nums">{{ format_quantity_id($row['current_stock'] ?? 0) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500">Hari Tanpa Keluar</dt>
                                    <dd class="font-medium tabular-nums">{{ $row['days_since_last_out'] !== null ? format_number_id($row['days_since_last_out']) : '—' }}</dd>
                                </div>
                            </dl>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
        @endif

        @if ($tab === 'aging')
        {{-- Umur Persediaan --}}
        <section id="section-aging" class="rounded-lg border border-gray-200 bg-white shadow-sm scroll-mt-24">
            <div class="border-b border-gray-200 px-4 py-3">
                <h2 class="text-base font-semibold text-gray-900">Umur Persediaan</h2>
                <p class="text-sm text-gray-500">
                    Granularitas: {{ $aging['granularity'] === 'batch' ? 'Per Batch' : 'Per Produk' }}.
                    Kelompok usia berdasarkan hari sejak masuk terakhir.
                </p>
            </div>
            @if (! empty($aging['buckets']))
                <div class="grid gap-3 border-b border-gray-100 p-4 sm:grid-cols-2 lg:grid-cols-5">
                    @foreach ($aging['buckets'] as $bucketKey => $bucket)
                        <div class="rounded-lg border border-gray-100 bg-gray-50 p-3">
                            @include('inventory.analytics._age-bucket-badge', ['bucket' => $bucketKey, 'label' => $bucket['label']])
                            <p class="mt-2 text-xs text-gray-500">{{ format_number_id($bucket['product_count']) }} item</p>
                            <p class="text-sm font-semibold tabular-nums text-gray-900">{{ format_currency_id($bucket['total_value']) }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
            @if ($aging['items']->isEmpty())
                @include('inventory.analytics._empty-state')
            @elseif ($aging['granularity'] === 'batch')
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-gray-500">
                                <th scope="col" class="px-4 py-3 font-medium">Batch</th>
                                <th scope="col" class="px-3 py-3 font-medium">Produk</th>
                                <th scope="col" class="px-3 py-3 font-medium">Lokasi</th>
                                <th scope="col" class="px-3 py-3 font-medium">Kelompok Usia</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Stok Batch</th>
                                <th scope="col" class="px-4 py-3 text-right font-medium">Nilai</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($aging['items'] as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3">
                                        <p class="font-medium text-gray-900">{{ $row['batch_number'] }}</p>
                                        @if ($row['lot_number'])
                                            <p class="text-xs text-gray-500">Lot: {{ $row['lot_number'] }}</p>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-gray-700">{{ $row['product_name'] }}</td>
                                    <td class="px-3 py-3 text-gray-600">{{ $row['inventory_location_name'] }}</td>
                                    <td class="px-3 py-3">
                                        @include('inventory.analytics._age-bucket-badge', [
                                            'bucket' => $row['age_bucket'],
                                            'label' => $aging['buckets'][$row['age_bucket']]['label'] ?? $row['age_bucket'],
                                        ])
                                    </td>
                                    <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_quantity_id($row['batch_stock']) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-gray-700">{{ format_currency_id($row['batch_value']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="divide-y divide-gray-100 md:hidden analytics-mobile-cards">
                    @foreach ($aging['items'] as $row)
                        <article class="p-4">
                            <h3 class="font-semibold text-gray-900">{{ $row['batch_number'] }}</h3>
                            <p class="text-sm text-gray-600">{{ $row['product_name'] }}</p>
                            <p class="mt-2 text-sm tabular-nums text-gray-700">Stok: {{ format_quantity_id($row['batch_stock']) }}</p>
                        </article>
                    @endforeach
                </div>
            @else
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-gray-500">
                                <th scope="col" class="px-4 py-3 font-medium">Produk</th>
                                <th scope="col" class="px-3 py-3 font-medium">Kelompok Usia</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Usia (hari)</th>
                                <th scope="col" class="px-3 py-3 font-medium">Tanggal Masuk Terakhir</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Stok Saat Ini</th>
                                <th scope="col" class="px-4 py-3 text-right font-medium">Nilai Stok</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($aging['items'] as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3">
                                        <p class="font-medium text-gray-900">{{ $row['product_name'] }}</p>
                                        <p class="text-xs text-gray-500">{{ $row['product_code'] }}</p>
                                    </td>
                                    <td class="px-3 py-3">
                                        @include('inventory.analytics._age-bucket-badge', [
                                            'bucket' => $row['age_bucket'],
                                            'label' => $aging['buckets'][$row['age_bucket']]['label'] ?? $row['age_bucket'],
                                        ])
                                    </td>
                                    <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_number_id($row['age_days'] ?? 0) }}</td>
                                    <td class="px-3 py-3 tabular-nums text-gray-700">{{ $row['last_in_date'] ? format_date_id($row['last_in_date']) : '—' }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_quantity_id($row['current_stock'] ?? 0) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-gray-700">{{ format_currency_id($row['stock_value'] ?? 0) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="divide-y divide-gray-100 md:hidden analytics-mobile-cards">
                    @foreach ($aging['items'] as $row)
                        <article class="p-4">
                            <h3 class="font-semibold text-gray-900">{{ $row['product_name'] }}</h3>
                            <p class="text-sm text-gray-600">Usia: {{ format_number_id($row['age_days'] ?? 0) }} hari</p>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
        @endif

        @if ($tab === 'turnover')
        {{-- Perputaran Persediaan --}}
        <section id="section-turnover" class="rounded-lg border border-gray-200 bg-white shadow-sm scroll-mt-24">
            <div class="border-b border-gray-200 px-4 py-3">
                <h2 class="text-base font-semibold text-gray-900">Perputaran Persediaan</h2>
                <p class="text-sm text-gray-500">Rasio keluar periode ÷ rata-rata stok periode.</p>
            </div>
            @if ($turnover->isEmpty())
                @include('inventory.analytics._empty-state')
            @else
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-gray-500">
                                <th scope="col" class="px-4 py-3 font-medium">Produk</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Jumlah Keluar</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Rata-rata Stok</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Rasio Perputaran</th>
                                <th scope="col" class="px-4 py-3 text-right font-medium">Nilai Keluar</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($turnover as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3">
                                        <p class="font-medium text-gray-900">{{ $row['product_name'] }}</p>
                                        <p class="text-xs text-gray-500">{{ $row['product_code'] }}</p>
                                    </td>
                                    <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_quantity_id($row['outbound_qty_period'] ?? 0) }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_quantity_id($row['avg_stock_period'] ?? 0) }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums font-semibold text-teal-800">
                                        {{ ($row['turnover_ratio_qty'] ?? null) !== null ? format_number_id($row['turnover_ratio_qty'], 2) : '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums text-gray-700">{{ format_currency_id($row['outbound_value_period'] ?? 0) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="divide-y divide-gray-100 md:hidden analytics-mobile-cards">
                    @foreach ($turnover as $row)
                        <article class="p-4">
                            <h3 class="font-semibold text-gray-900">{{ $row['product_name'] }}</h3>
                            <p class="text-sm text-gray-600">Rasio: {{ ($row['turnover_ratio_qty'] ?? null) !== null ? format_number_id($row['turnover_ratio_qty'], 2) : '—' }}</p>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
        @endif

        @if ($tab === 'value')
        {{-- Nilai per Kategori & Lokasi --}}
        <section id="section-value" class="rounded-lg border border-gray-200 bg-white shadow-sm scroll-mt-24">
            <div class="border-b border-gray-200 px-4 py-3">
                <h2 class="text-base font-semibold text-gray-900">Nilai per Kategori</h2>
                <p class="text-sm text-gray-500">Nilai stok saat ini dikelompokkan per kategori produk.</p>
            </div>
            @if ($valueByCategory->isEmpty())
                @include('inventory.analytics._empty-state')
            @else
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-gray-500">
                                <th scope="col" class="px-4 py-3 font-medium">Kategori</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Total Stok</th>
                                <th scope="col" class="px-4 py-3 text-right font-medium">Nilai Persediaan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($valueByCategory as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 font-medium text-gray-900">{{ $row->category_name ?? $row->name ?? '—' }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_quantity_id($row->total_stock ?? 0) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-gray-700">{{ format_currency_id($row->inventory_value ?? 0) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="divide-y divide-gray-100 md:hidden analytics-mobile-cards">
                    @foreach ($valueByCategory as $row)
                        <article class="p-4">
                            <h3 class="font-semibold text-gray-900">{{ $row->category_name ?? $row->name ?? '—' }}</h3>
                            <p class="text-sm tabular-nums text-gray-700">{{ format_currency_id($row->inventory_value ?? 0) }}</p>
                        </article>
                    @endforeach
                </div>
            @endif

            <div class="border-t border-gray-200 px-4 py-3">
                <h3 class="text-base font-semibold text-gray-900">Nilai per Lokasi</h3>
                <p class="text-sm text-gray-500">Nilai stok saat ini dikelompokkan per lokasi persediaan.</p>
            </div>
            @if ($valueByLocation->isEmpty())
                @include('inventory.analytics._empty-state')
            @else
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-gray-500">
                                <th scope="col" class="px-4 py-3 font-medium">Lokasi</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Total Stok</th>
                                <th scope="col" class="px-4 py-3 text-right font-medium">Nilai Persediaan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($valueByLocation as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 font-medium text-gray-900">{{ $row->name }}</td>
                                    <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_quantity_id($row->total_stock ?? 0) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-gray-700">{{ format_currency_id($row->inventory_value ?? 0) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="divide-y divide-gray-100 md:hidden analytics-mobile-cards">
                    @foreach ($valueByLocation as $row)
                        <article class="p-4">
                            <h3 class="font-semibold text-gray-900">{{ $row->name }}</h3>
                            <p class="text-sm tabular-nums text-gray-700">{{ format_currency_id($row->inventory_value ?? 0) }}</p>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
        @endif

        @if ($tab === 'trend')
        {{-- Tren Nilai Keluar --}}
        <section id="section-trend" class="rounded-lg border border-gray-200 bg-white shadow-sm scroll-mt-24">
            <div class="border-b border-gray-200 px-4 py-3">
                <h2 class="text-base font-semibold text-gray-900">Tren Nilai Keluar</h2>
                <p class="text-sm text-gray-500">Nilai keluar bulanan dalam periode — <strong>bukan</strong> nilai stok historis on-hand.</p>
            </div>
            @if (empty($outboundTrend))
                @include('inventory.analytics._empty-state')
            @else
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-gray-500">
                                <th scope="col" class="px-4 py-3 font-medium">Bulan</th>
                                <th scope="col" class="px-4 py-3 text-right font-medium">Nilai Keluar Bulanan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($outboundTrend as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 font-medium text-gray-900">{{ format_month_id($row['month']) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums text-gray-700">{{ format_currency_id($row['outbound_value']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="divide-y divide-gray-100 md:hidden analytics-mobile-cards">
                    @foreach ($outboundTrend as $row)
                        <article class="p-4">
                            <h3 class="font-semibold text-gray-900">{{ format_month_id($row['month']) }}</h3>
                            <p class="text-sm tabular-nums text-gray-700">{{ format_currency_id($row['outbound_value']) }}</p>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
        @endif

        @if ($tab === 'supplier')
            @include('inventory.analytics._tab-supplier')
        @endif

        @if ($tab === 'reorder')
            @include('inventory.analytics._tab-reorder')
        @endif

        @if ($tab === 'procurement')
            @include('inventory.analytics._tab-procurement')
        @endif

        @if ($tab === 'branch-comparison')
            @include('inventory.analytics._tab-branch-comparison')
        @endif
    </div>
</x-settings-shell>
