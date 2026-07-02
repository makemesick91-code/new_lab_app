<x-settings-shell title="Peringatan Persediaan">
    <div class="space-y-6">
        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Peringatan Persediaan</p>
                    <h1 class="mt-1 text-2xl font-semibold text-gray-900">Peringatan stok dan batch cabang aktif</h1>
                    <p class="mt-2 max-w-3xl text-sm text-gray-600">
                        Semua peringatan dihitung dari ledger pergerakan persediaan. Tidak ada kolom stok mutable yang digunakan.
                    </p>
                </div>
                <a href="{{ route('inventory.dashboard') }}" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Kembali ke Dasbor
                </a>
            </div>
        </section>

        <form method="GET" action="{{ route('inventory.alerts.index') }}" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-4 md:items-end">
                <div>
                    <label for="alert-location" class="text-sm font-medium text-gray-700">Lokasi Persediaan</label>
                    <select id="alert-location" name="inventory_location_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">Semua lokasi (total cabang)</option>
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}" @selected((int) ($filters['inventory_location_id'] ?? 0) === $location->id)>{{ $location->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="alert-type" class="text-sm font-medium text-gray-700">Tipe</label>
                    <select id="alert-type" name="type" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">Semua tipe</option>
                        <option value="stock" @selected(($filters['type'] ?? '') === 'stock')>Stok</option>
                        <option value="batch" @selected(($filters['type'] ?? '') === 'batch')>Batch</option>
                    </select>
                </div>
                <div>
                    <label for="alert-severity" class="text-sm font-medium text-gray-700">Tingkat</label>
                    <select id="alert-severity" name="severity" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">Semua tingkat</option>
                        <option value="out_of_stock" @selected(($filters['severity'] ?? '') === 'out_of_stock')>Stok Habis</option>
                        <option value="critical" @selected(($filters['severity'] ?? '') === 'critical')>Stok Kritis</option>
                        <option value="low" @selected(($filters['severity'] ?? '') === 'low')>Stok Rendah</option>
                        <option value="batch_expired" @selected(($filters['severity'] ?? '') === 'batch_expired')>Batch Kedaluwarsa</option>
                        <option value="batch_expiring_soon" @selected(($filters['severity'] ?? '') === 'batch_expiring_soon')>Akan Kedaluwarsa</option>
                    </select>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button class="inline-flex justify-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-700 focus:ring-offset-2">
                        Terapkan
                    </button>
                    <a href="{{ route('inventory.alerts.index') }}" class="inline-flex justify-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                        Atur Ulang
                    </a>
                </div>
            </div>
        </form>

        <x-inventory.alert-summary-widget :summary="$summary" />

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Peringatan Kedaluwarsa Batch</h3>
                    <p class="text-sm text-gray-500">Batch dengan stok positif yang sudah kedaluwarsa atau mendekati kedaluwarsa (≤ 90 hari).</p>
                </div>
            </div>

            @if ($batchExpiryAlerts->isEmpty())
                <div class="px-4 py-10 text-center">
                    <p class="text-sm font-medium text-gray-900">Tidak ada batch yang kedaluwarsa atau mendekati kedaluwarsa.</p>
                </div>
            @else
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-gray-500">
                                <th scope="col" class="px-4 py-3 font-medium">Status</th>
                                <th scope="col" class="px-4 py-3 font-medium">Produk</th>
                                <th scope="col" class="px-4 py-3 font-medium">Batch</th>
                                <th scope="col" class="px-4 py-3 font-medium">Kedaluwarsa</th>
                                <th scope="col" class="px-4 py-3 font-medium text-right">Stok Tersedia</th>
                                <th scope="col" class="px-4 py-3 font-medium">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @foreach ($batchExpiryAlerts as $alert)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3">@include('inventory.alerts._stock-severity-badge', ['severity' => $alert['severity']])</td>
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-gray-900">{{ $alert['product_name'] }}</div>
                                        <div class="text-xs text-gray-500">{{ $alert['product_code'] ?? '-' }}</div>
                                    </td>
                                    <td class="px-4 py-3 font-medium text-gray-900">{{ $alert['batch_number'] }}</td>
                                    <td class="px-4 py-3">
                                        <div class="tabular-nums text-gray-700">{{ format_date_id($alert['expiry_date']) }}</div>
                                        <div class="text-xs text-gray-500">{{ $alert['days_text'] }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums text-gray-700">{{ format_quantity_id((float) $alert['batch_stock']) }}</td>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('inventory.batches.show', $alert['inventory_batch_id']) }}" class="text-sm font-medium text-teal-700 hover:text-teal-600">Batch</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="divide-y divide-gray-100 md:hidden">
                    @foreach ($batchExpiryAlerts as $alert)
                        <article class="p-4">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                @include('inventory.alerts._stock-severity-badge', ['severity' => $alert['severity']])
                                <span class="text-xs text-gray-500">{{ $alert['days_text'] }}</span>
                            </div>
                            <h4 class="mt-2 font-semibold text-gray-900">{{ $alert['batch_number'] }}</h4>
                            <p class="text-sm text-gray-600">{{ $alert['product_name'] }}</p>
                            <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                                <div>
                                    <dt class="text-gray-500">Kedaluwarsa</dt>
                                    <dd class="font-medium tabular-nums text-gray-900">{{ format_date_id($alert['expiry_date']) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500">Stok tersedia</dt>
                                    <dd class="font-medium tabular-nums text-gray-900">{{ format_quantity_id((float) $alert['batch_stock']) }}</dd>
                                </div>
                            </dl>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Daftar Peringatan</h3>
                    <p class="text-sm text-gray-500">{{ format_number_id($alerts->total()) }} peringatan dalam lingkup cabang aktif.</p>
                </div>
            </div>

            @if ($alerts->isEmpty())
                <div class="px-4 py-12 text-center">
                    <p class="text-sm font-medium text-gray-900">Tidak ada peringatan aktif untuk cabang ini.</p>
                    <p class="mt-1 text-sm text-gray-500">Ubah filter atau periksa kembali setelah ada pergerakan stok.</p>
                </div>
            @else
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr class="text-left text-gray-500">
                                <th scope="col" class="px-4 py-3 font-medium">Tingkat</th>
                                <th scope="col" class="px-4 py-3 font-medium">Tipe</th>
                                <th scope="col" class="px-4 py-3 font-medium">Produk / Batch</th>
                                <th scope="col" class="px-4 py-3 font-medium text-right">Stok / Batch</th>
                                <th scope="col" class="px-4 py-3 font-medium text-right">Titik Pesan</th>
                                <th scope="col" class="px-4 py-3 font-medium text-right">Jumlah Reorder</th>
                                <th scope="col" class="px-4 py-3 font-medium">Kedaluwarsa</th>
                                <th scope="col" class="px-4 py-3 font-medium">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @foreach ($alerts as $alert)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3">@include('inventory.alerts._stock-severity-badge', ['severity' => $alert['severity']])</td>
                                    <td class="px-4 py-3 text-gray-700">{{ $alert['type'] === 'stock' ? 'Stok' : 'Batch' }}</td>
                                    <td class="px-4 py-3">
                                        @if ($alert['type'] === 'stock')
                                            <div class="font-medium text-gray-900">{{ $alert['product_name'] }}</div>
                                            <div class="text-xs text-gray-500">{{ $alert['product_code'] }}</div>
                                        @else
                                            <div class="font-medium text-gray-900">{{ $alert['batch_number'] }}</div>
                                            <div class="text-xs text-gray-500">{{ $alert['product_name'] }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums text-gray-700">
                                        @if ($alert['type'] === 'stock')
                                            {{ format_quantity_id((float) $alert['current_stock']) }}
                                        @else
                                            {{ format_quantity_id((float) $alert['batch_stock']) }}
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums text-gray-700">
                                        @if ($alert['type'] === 'stock')
                                            {{ format_quantity_id((float) $alert['effective_reorder_point']) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums text-gray-700">
                                        @if ($alert['type'] === 'stock' && ($alert['reorder_quantity'] ?? null) !== null)
                                            <span class="text-xs text-gray-500">Rekomendasi Reorder</span>
                                            <div>{{ format_quantity_id((float) $alert['reorder_quantity']) }}</div>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 tabular-nums text-gray-700">
                                        {{ $alert['expiry_date'] ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap items-center gap-2">
                                            @if ($alert['type'] === 'stock')
                                                <a href="{{ route('inventory.products.show', $alert['product_id']) }}" class="text-sm font-medium text-teal-700 hover:text-teal-600">Produk</a>
                                                @can('create', \App\Modules\Inventory\Models\PurchaseRequest::class)
                                                    <a href="{{ route('inventory.purchase-requests.create', array_filter([
                                                        'product_id' => $alert['product_id'],
                                                        'inventory_location_id' => $alert['inventory_location_id'] ?? null,
                                                        'suggested_quantity' => $alert['reorder_quantity'] ?? null,
                                                    ])) }}" class="text-sm font-medium text-orange-700 hover:text-orange-600">Buat PR</a>
                                                @endcan
                                            @else
                                                <a href="{{ route('inventory.batches.show', $alert['inventory_batch_id']) }}" class="text-sm font-medium text-teal-700 hover:text-teal-600">Batch</a>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="divide-y divide-gray-100 md:hidden">
                    @foreach ($alerts as $alert)
                        <article class="p-4">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                @include('inventory.alerts._stock-severity-badge', ['severity' => $alert['severity']])
                                <span class="text-xs text-gray-500">{{ $alert['type'] === 'stock' ? 'Stok' : 'Batch' }}</span>
                            </div>
                            <h4 class="mt-2 font-semibold text-gray-900">
                                {{ $alert['type'] === 'stock' ? $alert['product_name'] : $alert['batch_number'] }}
                            </h4>
                            <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                                @if ($alert['type'] === 'stock')
                                    <div>
                                        <dt class="text-gray-500">Stok saat ini</dt>
                                        <dd class="font-medium tabular-nums text-gray-900">{{ format_quantity_id((float) $alert['current_stock']) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-gray-500">Titik pesan</dt>
                                        <dd class="font-medium tabular-nums text-gray-900">{{ format_quantity_id((float) $alert['effective_reorder_point']) }}</dd>
                                    </div>
                                    @if (($alert['reorder_quantity'] ?? null) !== null)
                                        <div class="col-span-2">
                                            <dt class="text-gray-500">Jumlah Reorder</dt>
                                            <dd class="font-medium tabular-nums text-gray-900">{{ format_quantity_id((float) $alert['reorder_quantity']) }}</dd>
                                        </div>
                                    @endif
                                @else
                                    <div>
                                        <dt class="text-gray-500">Produk</dt>
                                        <dd class="font-medium text-gray-900">{{ $alert['product_name'] }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-gray-500">Kedaluwarsa</dt>
                                        <dd class="font-medium tabular-nums text-gray-900">{{ $alert['expiry_date'] }}</dd>
                                    </div>
                                @endif
                            </dl>
                            @if ($alert['type'] === 'stock')
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <a href="{{ route('inventory.products.show', $alert['product_id']) }}" class="text-sm font-medium text-teal-700 hover:text-teal-600">Produk</a>
                                    @can('create', \App\Modules\Inventory\Models\PurchaseRequest::class)
                                        <a href="{{ route('inventory.purchase-requests.create', array_filter([
                                            'product_id' => $alert['product_id'],
                                            'inventory_location_id' => $alert['inventory_location_id'] ?? null,
                                            'suggested_quantity' => $alert['reorder_quantity'] ?? null,
                                        ])) }}" class="text-sm font-medium text-orange-700 hover:text-orange-600">Buat PR</a>
                                    @endcan
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>

                @if ($alerts->hasPages())
                    <div class="border-t border-gray-200 px-4 py-3">
                        {{ $alerts->links() }}
                    </div>
                @endif
            @endif
        </section>
    </div>
</x-settings-shell>
