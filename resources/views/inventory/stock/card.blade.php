@php
    $currentStock = (float) $currentStock;
    $minimumStock = (float) $product->minimum_stock;
    $isOut = $currentStock <= 0;
    $isLow = ! $isOut && $currentStock <= $minimumStock;
    $selectedLocation = ($filters['inventory_location_id'] ?? null)
        ? collect($locations)->firstWhere('id', (int) $filters['inventory_location_id'])
        : null;
    $scopeLabel = $selectedLocation ? $selectedLocation->name : 'Semua lokasi aktif';
    $movementTypes = [
        'OPENING' => 'Stok Awal',
        'PURCHASE' => 'Pembelian / Terima Stok',
        'ADJUSTMENT_IN' => 'Penyesuaian Masuk',
        'ADJUSTMENT_OUT' => 'Penyesuaian Keluar',
    ];
@endphp

<x-settings-shell title="Kartu Stok">
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Kartu Stok Berbasis Ledger</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">{{ $product->name }}</h2>
                <p class="mt-1 text-sm text-gray-500">Stok dihitung dari pergerakan persediaan. Tidak ada kolom stok mutable yang digunakan.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('inventory.products.show', $product) }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Detail Produk
                </a>
                <a href="{{ route('inventory.stock.index') }}" class="inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Daftar Stok
                </a>
            </div>
        </div>

        <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem] lg:items-start">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        @include('inventory._low-stock-badge', ['current' => $currentStock, 'minimum' => $minimumStock])
                        <span class="inline-flex rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700">{{ $scopeLabel }}</span>
                    </div>
                    <h3 class="mt-3 text-lg font-semibold text-gray-900">{{ $product->code }} - {{ $product->name }}</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ $product->category?->name ?? 'Tanpa kategori' }} / {{ $product->unit?->symbol ?? '-' }}
                        @if ($selectedLocation)
                            - difilter ke lokasi tipe {{ $selectedLocation->type }}.
                        @else
                            - total cabang di seluruh lokasi persediaan aktif.
                        @endif
                    </p>

                    <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-3">
                        <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Stok Minimum</dt>
                            <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ number_format($minimumStock, 2) }}</dd>
                        </div>
                        <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Lingkup Filter</dt>
                            <dd class="mt-1 font-semibold text-gray-900">{{ $selectedLocation ? 'Lokasi' : 'Cabang' }}</dd>
                        </div>
                        <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Jumlah Pergerakan</dt>
                            <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ number_format($stockCard->count()) }}</dd>
                        </div>
                    </dl>
                </div>

                <div @class([
                    'rounded-lg border p-5 text-right',
                    'border-rose-100 bg-rose-50' => $isOut,
                    'border-amber-100 bg-amber-50' => $isLow,
                    'border-teal-100 bg-teal-50' => ! $isOut && ! $isLow,
                ])>
                    <p @class([
                        'text-xs font-semibold uppercase tracking-wide',
                        'text-rose-700' => $isOut,
                        'text-amber-700' => $isLow,
                        'text-teal-700' => ! $isOut && ! $isLow,
                    ])>Stok Saat Ini</p>
                    <p @class([
                        'mt-2 text-3xl font-semibold tabular-nums',
                        'text-rose-900' => $isOut,
                        'text-amber-900' => $isLow,
                        'text-teal-900' => ! $isOut && ! $isLow,
                    ])>{{ number_format($currentStock, 2) }}</p>
                    <p @class([
                        'mt-1 text-sm',
                        'text-rose-700' => $isOut,
                        'text-amber-700' => $isLow,
                        'text-teal-700' => ! $isOut && ! $isLow,
                    ])>{{ $product->unit?->symbol ?? 'satuan' }} di {{ $scopeLabel }}</p>
                </div>
            </div>
        </section>

        <form method="GET" action="{{ route('inventory.products.stock-card', $product) }}" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div class="grid gap-3 md:grid-cols-5 md:items-end">
                <div>
                    <label for="inventory_location_id" class="text-sm font-medium text-gray-700">Lokasi Persediaan</label>
                    <select id="inventory_location_id" name="inventory_location_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">Semua lokasi aktif</option>
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}" @selected(($filters['inventory_location_id'] ?? null) == $location->id)>
                                {{ $location->name }}{{ $location->code ? ' ('.$location->code.')' : '' }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-500">Filter kartu stok ke satu lokasi penyimpanan fisik.</p>
                </div>
                <div>
                    <label for="movement_type" class="text-sm font-medium text-gray-700">Tipe Pergerakan</label>
                    <select id="movement_type" name="movement_type" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">Semua tipe pergerakan</option>
                        @foreach ($movementTypes as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['movement_type'] ?? null) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="date_from" class="text-sm font-medium text-gray-700">Dari</label>
                    <input id="date_from" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div>
                    <label for="date_to" class="text-sm font-medium text-gray-700">Sampai</label>
                    <input id="date_to" type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div class="flex gap-2">
                    <button class="flex-1 rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-700 focus:ring-offset-2">
                        Terapkan
                    </button>
                    <a href="{{ route('inventory.products.stock-card', $product) }}" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                        Atur Ulang
                    </a>
                </div>
            </div>
        </form>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-200 px-4 py-4">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Riwayat Pergerakan Stok</h3>
                    <p class="mt-1 text-sm text-gray-500">Pergerakan masuk dan keluar membentuk saldo berjalan berdasarkan urutan tanggal pergerakan dan id.</p>
                </div>
                <span class="inline-flex rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600">Fokus Saldo Berjalan</span>
            </div>

            @if ($stockCard->isEmpty())
                <div class="px-4 py-12 text-center">
                    <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                        <span class="text-lg font-semibold">0</span>
                    </div>
                    <p class="mt-3 text-sm font-medium text-gray-900">Tidak ada pergerakan stok yang cocok dengan filter ini.</p>
                    <p class="mt-1 text-sm text-gray-500">Stok awal, penerimaan stok, dan penyesuaian akan muncul di sini setelah dicatat.</p>
                </div>
            @else
                <div class="p-4 lg:hidden">
                    <ol class="relative space-y-4 border-l border-gray-200 pl-4">
                        @foreach ($stockCard as $movement)
                            @php
                                $quantityIn = (float) $movement->quantity_in;
                                $quantityOut = (float) $movement->quantity_out;
                                $isInbound = $quantityIn > 0;
                                $delta = $quantityIn - $quantityOut;
                                $reference = $movement->reference_type
                                    ? $movement->reference_type.' #'.$movement->reference_id
                                    : 'Pergerakan persediaan manual';
                            @endphp
                            <li class="relative">
                                <span @class([
                                    'absolute -left-[1.3125rem] mt-1.5 h-2.5 w-2.5 rounded-full',
                                    'bg-emerald-500' => $isInbound,
                                    'bg-amber-500' => ! $isInbound,
                                ])></span>
                                <article class="rounded-lg border border-gray-200 p-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <p class="text-sm font-semibold text-gray-900">{{ str_replace('_', ' ', $movement->movement_type) }}</p>
                                            <p class="mt-1 text-xs text-gray-500">{{ optional($movement->movement_date)->format('Y-m-d') }} - {{ $movement->inventoryLocation?->name ?? '-' }}</p>
                                        </div>
                                        <div class="text-right">
                                            <p @class([
                                                'text-base font-semibold tabular-nums',
                                                'text-emerald-700' => $isInbound,
                                                'text-amber-700' => ! $isInbound,
                                            ])>{{ $delta >= 0 ? '+' : '-' }}{{ number_format(abs($delta), 2) }}</p>
                                            <p class="text-xs text-gray-500">Perubahan</p>
                                        </div>
                                    </div>
                                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                                        <div class="rounded-lg bg-emerald-50 p-3">
                                            <dt class="text-xs text-emerald-700">Jumlah Masuk</dt>
                                            <dd class="mt-1 font-semibold tabular-nums text-emerald-900">{{ number_format($quantityIn, 2) }}</dd>
                                        </div>
                                        <div class="rounded-lg bg-amber-50 p-3">
                                            <dt class="text-xs text-amber-700">Jumlah Keluar</dt>
                                            <dd class="mt-1 font-semibold tabular-nums text-amber-900">{{ number_format($quantityOut, 2) }}</dd>
                                        </div>
                                        <div class="rounded-lg bg-teal-50 p-3">
                                            <dt class="text-xs text-teal-700">Saldo Berjalan</dt>
                                            <dd class="mt-1 font-semibold tabular-nums text-teal-900">{{ number_format((float) $movement->running_balance, 2) }}</dd>
                                        </div>
                                        <div class="rounded-lg bg-gray-50 p-3">
                                            <dt class="text-xs text-gray-500">Biaya per Unit</dt>
                                            <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ (float) $movement->unit_cost > 0 ? number_format((float) $movement->unit_cost, 2) : 'Biaya tidak dicatat' }}</dd>
                                        </div>
                                    </dl>
                                    <div class="mt-4 rounded-lg bg-gray-50 p-3 text-sm text-gray-600">
                                        <p><span class="font-medium text-gray-900">Referensi:</span> {{ $reference }}</p>
                                        <p class="mt-1"><span class="font-medium text-gray-900">Catatan:</span> {{ $movement->notes ?: 'Tidak ada catatan.' }}</p>
                                        <p class="mt-1"><span class="font-medium text-gray-900">Dibuat oleh:</span> {{ $movement->createdBy?->name ?? '-' }}</p>
                                    </div>
                                </article>
                            </li>
                        @endforeach
                    </ol>
                </div>

                <div class="hidden overflow-x-auto lg:block">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-gray-500">
                                <th scope="col" class="px-4 py-3 font-medium">Pergerakan</th>
                                <th scope="col" class="px-3 py-3 font-medium">Lokasi</th>
                                <th scope="col" class="px-3 py-3 font-medium">Referensi / Catatan</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Jumlah Masuk</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Jumlah Keluar</th>
                                <th scope="col" class="px-3 py-3 text-right font-medium">Saldo Berjalan</th>
                                <th scope="col" class="px-4 py-3 text-right font-medium">Biaya per Unit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($stockCard as $movement)
                                @php
                                    $quantityIn = (float) $movement->quantity_in;
                                    $quantityOut = (float) $movement->quantity_out;
                                    $isInbound = $quantityIn > 0;
                                    $reference = $movement->reference_type
                                        ? $movement->reference_type.' #'.$movement->reference_id
                                        : 'Pergerakan persediaan manual';
                                @endphp
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3">
                                        <div class="flex items-start gap-3">
                                            <span @class([
                                                'mt-1 h-9 w-1 rounded-full',
                                                'bg-emerald-400' => $isInbound,
                                                'bg-amber-400' => ! $isInbound,
                                            ])></span>
                                            <div>
                                                <p class="font-semibold text-gray-900">{{ str_replace('_', ' ', $movement->movement_type) }}</p>
                                                <p class="mt-0.5 text-xs text-gray-500">{{ optional($movement->movement_date)->format('Y-m-d') }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-3 py-3 text-gray-600">{{ $movement->inventoryLocation?->name ?? '-' }}</td>
                                    <td class="px-3 py-3">
                                        <p class="font-medium text-gray-900">{{ $reference }}</p>
                                        <p class="mt-0.5 max-w-xs truncate text-xs text-gray-500">{{ $movement->notes ?: 'Tidak ada catatan.' }}</p>
                                        <p class="mt-0.5 text-xs text-gray-400">{{ $movement->createdBy?->name ?? '-' }}</p>
                                    </td>
                                    <td class="px-3 py-3 text-right tabular-nums">
                                        <span class="font-semibold text-emerald-700">{{ $quantityIn > 0 ? '+'.number_format($quantityIn, 2) : number_format($quantityIn, 2) }}</span>
                                    </td>
                                    <td class="px-3 py-3 text-right tabular-nums">
                                        <span class="font-semibold text-amber-700">{{ $quantityOut > 0 ? '-'.number_format($quantityOut, 2) : number_format($quantityOut, 2) }}</span>
                                    </td>
                                    <td class="px-3 py-3 text-right tabular-nums">
                                        <span class="rounded-full bg-teal-50 px-2.5 py-1 font-semibold text-teal-800">{{ number_format((float) $movement->running_balance, 2) }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums text-gray-700">
                                        {{ (float) $movement->unit_cost > 0 ? number_format((float) $movement->unit_cost, 2) : 'Biaya tidak dicatat' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-settings-shell>
