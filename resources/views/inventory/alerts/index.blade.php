<x-settings-shell title="Peringatan Persediaan">
    <div class="space-y-6">
        <x-ui.page-header
            title="Peringatan stok dan batch cabang aktif"
            subtitle="Semua peringatan dihitung dari ledger pergerakan persediaan. Tidak ada kolom stok mutable yang digunakan.">
            <x-slot:breadcrumb>Persediaan / Peringatan</x-slot:breadcrumb>
            <x-slot:actions>
                <x-ui.button variant="secondary" :href="route('inventory.dashboard')">Kembali ke Dasbor</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.filter-bar :action="route('inventory.alerts.index')">
            <div class="md:w-56">
                <x-ui.select label="Lokasi Persediaan" id="alert-location" name="inventory_location_id">
                    <option value="">Semua lokasi (total cabang)</option>
                    @foreach ($locations as $location)
                        <option value="{{ $location->id }}" @selected((int) ($filters['inventory_location_id'] ?? 0) === $location->id)>{{ $location->name }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            <div class="md:w-40">
                <x-ui.select label="Tipe" id="alert-type" name="type">
                    <option value="">Semua tipe</option>
                    <option value="stock" @selected(($filters['type'] ?? '') === 'stock')>Stok</option>
                    <option value="batch" @selected(($filters['type'] ?? '') === 'batch')>Batch</option>
                </x-ui.select>
            </div>
            <div class="md:w-48">
                <x-ui.select label="Tingkat" id="alert-severity" name="severity">
                    <option value="">Semua tingkat</option>
                    <option value="out_of_stock" @selected(($filters['severity'] ?? '') === 'out_of_stock')>Stok Habis</option>
                    <option value="critical" @selected(($filters['severity'] ?? '') === 'critical')>Stok Kritis</option>
                    <option value="low" @selected(($filters['severity'] ?? '') === 'low')>Stok Rendah</option>
                    <option value="batch_expired" @selected(($filters['severity'] ?? '') === 'batch_expired')>Batch Kedaluwarsa</option>
                    <option value="batch_expiring_soon" @selected(($filters['severity'] ?? '') === 'batch_expiring_soon')>Akan Kedaluwarsa</option>
                </x-ui.select>
            </div>
            <x-slot:actions>
                <x-ui.button type="submit" variant="primary">Terapkan</x-ui.button>
                <x-ui.button variant="secondary" :href="route('inventory.alerts.index')">Atur Ulang</x-ui.button>
            </x-slot:actions>
        </x-ui.filter-bar>

        <x-inventory.alert-summary-widget :summary="$summary" />

        <x-ui.card padding="">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-4 py-3">
                <div>
                    <h3 class="text-base font-semibold text-navy">Peringatan Kedaluwarsa Batch</h3>
                    <p class="text-sm text-ink-soft">Batch dengan stok positif yang sudah kedaluwarsa atau mendekati kedaluwarsa (≤ 90 hari).</p>
                </div>
            </div>

            @if ($batchExpiryAlerts->isEmpty())
                <div class="px-4 py-10">
                    <x-ui.empty-state title="Tidak ada batch yang kedaluwarsa atau mendekati kedaluwarsa." class="border-0 bg-transparent shadow-none" />
                </div>
            @else
                <div class="hidden md:block">
                    <x-ui.table class="!border-0 !shadow-none !rounded-none">
                        <thead class="bg-navy-50">
                            <tr class="text-left text-ink-soft">
                                <th scope="col" class="px-4 py-3 font-medium">Status</th>
                                <th scope="col" class="px-4 py-3 font-medium">Produk</th>
                                <th scope="col" class="px-4 py-3 font-medium">Batch</th>
                                <th scope="col" class="px-4 py-3 font-medium">Kedaluwarsa</th>
                                <th scope="col" class="px-4 py-3 font-medium text-right">Stok Tersedia</th>
                                <th scope="col" class="px-4 py-3 font-medium">Tindakan Terakhir</th>
                                <th scope="col" class="px-4 py-3 font-medium">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-hairline">
                            @foreach ($batchExpiryAlerts as $alert)
                                @php
                                    $latestAction = $latestBatchActions[$alert['inventory_batch_id']] ?? null;
                                    $batchModel = $expiryAlertBatches[$alert['inventory_batch_id']] ?? null;
                                @endphp
                                <tr class="hover:bg-navy-50">
                                    <td class="px-4 py-3">@include('inventory.alerts._stock-severity-badge', ['severity' => $alert['severity']])</td>
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-navy">{{ $alert['product_name'] }}</div>
                                        <div class="text-xs text-ink-soft">{{ $alert['product_code'] ?? '-' }}</div>
                                    </td>
                                    <td class="px-4 py-3 font-medium text-navy">{{ $alert['batch_number'] }}</td>
                                    <td class="px-4 py-3">
                                        <div class="tabular-nums text-ink">{{ format_date_id($alert['expiry_date']) }}</div>
                                        <div class="text-xs text-ink-soft">{{ $alert['days_text'] }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums text-ink">{{ format_quantity_id((float) $alert['batch_stock']) }}</td>
                                    <td class="px-4 py-3">
                                        @if ($latestAction)
                                            @include('inventory.batches._batch-action-type-badge', ['actionType' => $latestAction->action_type])
                                        @else
                                            <span class="text-xs text-ink-muted">Belum ada</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <a href="{{ route('inventory.batches.show', $alert['inventory_batch_id']) }}#catat-tindakan-batch" class="text-sm font-medium text-brand-700 hover:text-brand-800">Batch</a>
                                            @if ($batchModel)
                                                @can('createForBatch', [\App\Modules\Inventory\Models\InventoryBatchDisposalRequest::class, $batchModel])
                                                    <a href="{{ route('inventory.batches.show', $alert['inventory_batch_id']) }}#buat-permintaan-disposal" class="text-sm font-medium text-danger-700 hover:text-danger-700">Buat Disposal</a>
                                                @endcan
                                                @can('recordAction', $batchModel)
                                                    <a href="{{ route('inventory.batches.show', $alert['inventory_batch_id']) }}#catat-tindakan-batch" class="text-sm font-medium text-warning-700 hover:text-warning-700">Catat Tindakan</a>
                                                @endcan
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-ui.table>
                </div>

                <div class="divide-y divide-hairline md:hidden">
                    @foreach ($batchExpiryAlerts as $alert)
                        @php
                            $latestAction = $latestBatchActions[$alert['inventory_batch_id']] ?? null;
                            $batchModel = $expiryAlertBatches[$alert['inventory_batch_id']] ?? null;
                        @endphp
                        <article class="p-4">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                @include('inventory.alerts._stock-severity-badge', ['severity' => $alert['severity']])
                                <span class="text-xs text-ink-soft">{{ $alert['days_text'] }}</span>
                            </div>
                            <h4 class="mt-2 font-semibold text-navy">{{ $alert['batch_number'] }}</h4>
                            <p class="text-sm text-ink-soft">{{ $alert['product_name'] }}</p>
                            @if ($latestAction)
                                <div class="mt-2">
                                    @include('inventory.batches._batch-action-type-badge', ['actionType' => $latestAction->action_type])
                                </div>
                            @endif
                            <dl class="mt-3 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                                <div>
                                    <dt class="text-ink-soft">Kedaluwarsa</dt>
                                    <dd class="font-medium tabular-nums text-navy">{{ format_date_id($alert['expiry_date']) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-ink-soft">Stok tersedia</dt>
                                    <dd class="font-medium tabular-nums text-navy">{{ format_quantity_id((float) $alert['batch_stock']) }}</dd>
                                </div>
                            </dl>
                            <div class="mt-3 flex flex-wrap gap-3">
                                <a href="{{ route('inventory.batches.show', $alert['inventory_batch_id']) }}#catat-tindakan-batch" class="text-sm font-medium text-brand-700 hover:text-brand-800">Lihat batch</a>
                                @if ($batchModel)
                                    @can('createForBatch', [\App\Modules\Inventory\Models\InventoryBatchDisposalRequest::class, $batchModel])
                                        <a href="{{ route('inventory.batches.show', $alert['inventory_batch_id']) }}#buat-permintaan-disposal" class="text-sm font-medium text-danger-700 hover:text-danger-700">Buat Disposal</a>
                                    @endcan
                                    @can('recordAction', $batchModel)
                                        <a href="{{ route('inventory.batches.show', $alert['inventory_batch_id']) }}#catat-tindakan-batch" class="text-sm font-medium text-warning-700 hover:text-warning-700">Catat Tindakan</a>
                                    @endcan
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.card>

        <x-ui.card padding="">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-4 py-3">
                <div>
                    <h3 class="text-base font-semibold text-navy">Daftar Peringatan</h3>
                    <p class="text-sm text-ink-soft">{{ format_number_id($alerts->total()) }} peringatan dalam lingkup cabang aktif.</p>
                </div>
            </div>

            @if ($alerts->isEmpty())
                <div class="px-4 py-12">
                    <x-ui.empty-state title="Tidak ada peringatan aktif untuk cabang ini."
                        description="Ubah filter atau periksa kembali setelah ada pergerakan stok." class="border-0 bg-transparent shadow-none" />
                </div>
            @else
                <div class="hidden md:block">
                    <x-ui.table class="!border-0 !shadow-none !rounded-none">
                        <thead class="bg-navy-50">
                            <tr class="text-left text-ink-soft">
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
                        <tbody class="divide-y divide-hairline">
                            @foreach ($alerts as $alert)
                                <tr class="hover:bg-navy-50">
                                    <td class="px-4 py-3">@include('inventory.alerts._stock-severity-badge', ['severity' => $alert['severity']])</td>
                                    <td class="px-4 py-3 text-ink">{{ $alert['type'] === 'stock' ? 'Stok' : 'Batch' }}</td>
                                    <td class="px-4 py-3">
                                        @if ($alert['type'] === 'stock')
                                            <div class="font-medium text-navy">{{ $alert['product_name'] }}</div>
                                            <div class="text-xs text-ink-soft">{{ $alert['product_code'] }}</div>
                                        @else
                                            <div class="font-medium text-navy">{{ $alert['batch_number'] }}</div>
                                            <div class="text-xs text-ink-soft">{{ $alert['product_name'] }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums text-ink">
                                        @if ($alert['type'] === 'stock')
                                            {{ format_quantity_id((float) $alert['current_stock']) }}
                                        @else
                                            {{ format_quantity_id((float) $alert['batch_stock']) }}
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums text-ink">
                                        @if ($alert['type'] === 'stock')
                                            {{ format_quantity_id((float) $alert['effective_reorder_point']) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right tabular-nums text-ink">
                                        @if ($alert['type'] === 'stock' && ($alert['reorder_quantity'] ?? null) !== null)
                                            <span class="text-xs text-ink-soft">Rekomendasi Reorder</span>
                                            <div>{{ format_quantity_id((float) $alert['reorder_quantity']) }}</div>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 tabular-nums text-ink">
                                        {{ $alert['expiry_date'] ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap items-center gap-2">
                                            @if ($alert['type'] === 'stock')
                                                <a href="{{ route('inventory.products.show', $alert['product_id']) }}" class="text-sm font-medium text-brand-700 hover:text-brand-800">Produk</a>
                                                @can('create', \App\Modules\Inventory\Models\PurchaseRequest::class)
                                                    <a href="{{ route('inventory.purchase-requests.create', array_filter([
                                                        'product_id' => $alert['product_id'],
                                                        'inventory_location_id' => $alert['inventory_location_id'] ?? null,
                                                        'suggested_quantity' => $alert['reorder_quantity'] ?? null,
                                                    ])) }}" class="text-sm font-medium text-warning-700 hover:text-warning-700">Buat PR</a>
                                                @endcan
                                            @else
                                                <a href="{{ route('inventory.batches.show', $alert['inventory_batch_id']) }}" class="text-sm font-medium text-brand-700 hover:text-brand-800">Batch</a>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-ui.table>
                </div>

                <div class="divide-y divide-hairline md:hidden">
                    @foreach ($alerts as $alert)
                        <article class="p-4">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                @include('inventory.alerts._stock-severity-badge', ['severity' => $alert['severity']])
                                <span class="text-xs text-ink-soft">{{ $alert['type'] === 'stock' ? 'Stok' : 'Batch' }}</span>
                            </div>
                            <h4 class="mt-2 font-semibold text-navy">
                                {{ $alert['type'] === 'stock' ? $alert['product_name'] : $alert['batch_number'] }}
                            </h4>
                            <dl class="mt-3 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                                @if ($alert['type'] === 'stock')
                                    <div>
                                        <dt class="text-ink-soft">Stok saat ini</dt>
                                        <dd class="font-medium tabular-nums text-navy">{{ format_quantity_id((float) $alert['current_stock']) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-ink-soft">Titik pesan</dt>
                                        <dd class="font-medium tabular-nums text-navy">{{ format_quantity_id((float) $alert['effective_reorder_point']) }}</dd>
                                    </div>
                                    @if (($alert['reorder_quantity'] ?? null) !== null)
                                        <div class="col-span-2">
                                            <dt class="text-ink-soft">Jumlah Reorder</dt>
                                            <dd class="font-medium tabular-nums text-navy">{{ format_quantity_id((float) $alert['reorder_quantity']) }}</dd>
                                        </div>
                                    @endif
                                @else
                                    <div>
                                        <dt class="text-ink-soft">Produk</dt>
                                        <dd class="font-medium text-navy">{{ $alert['product_name'] }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-ink-soft">Kedaluwarsa</dt>
                                        <dd class="font-medium tabular-nums text-navy">{{ $alert['expiry_date'] }}</dd>
                                    </div>
                                @endif
                            </dl>
                            @if ($alert['type'] === 'stock')
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <a href="{{ route('inventory.products.show', $alert['product_id']) }}" class="text-sm font-medium text-brand-700 hover:text-brand-800">Produk</a>
                                    @can('create', \App\Modules\Inventory\Models\PurchaseRequest::class)
                                        <a href="{{ route('inventory.purchase-requests.create', array_filter([
                                            'product_id' => $alert['product_id'],
                                            'inventory_location_id' => $alert['inventory_location_id'] ?? null,
                                            'suggested_quantity' => $alert['reorder_quantity'] ?? null,
                                        ])) }}" class="text-sm font-medium text-warning-700 hover:text-warning-700">Buat PR</a>
                                    @endcan
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>

                @if ($alerts->hasPages())
                    <div class="border-t border-hairline px-4 py-3">
                        {{ $alerts->links() }}
                    </div>
                @endif
            @endif
        </x-ui.card>
    </div>
</x-settings-shell>
