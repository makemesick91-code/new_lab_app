@php
    use App\Modules\Inventory\Services\BatchExpiryStatusService;
    use App\Modules\Inventory\Services\InventoryBatchService;

    $batchService = app(InventoryBatchService::class);
    $expiryStatusService = app(BatchExpiryStatusService::class);
@endphp

<x-settings-shell title="Batch & Lot">
    <div class="space-y-6">
        <x-ui.page-header
            title="Direktori Batch & Lot"
            subtitle="Stok batch dihitung dari ledger pergerakan. Tidak ada kolom stok mutable pada batch.">
            <x-slot:breadcrumb>Persediaan / Batch &amp; Lot</x-slot:breadcrumb>
        </x-ui.page-header>

        <x-ui.alert variant="info" title="Halaman pemantauan Batch &amp; Lot">
            <p>Batch/Lot tidak dibuat manual dari halaman ini. Batch/Lot akan muncul otomatis setelah stok masuk melalui Goods Receipt, Opening Stock, atau Adjustment In.</p>
            <div class="mt-3 flex flex-wrap gap-2">
                @if (Route::has('inventory.goods-receipts.create'))
                    <x-ui.button size="sm" variant="primary" :href="route('inventory.goods-receipts.create')">Input via Goods Receipt</x-ui.button>
                @endif
                @if (Route::has('inventory.products.index'))
                    <x-ui.button size="sm" variant="secondary" :href="route('inventory.products.index')">Input Stok Awal Produk</x-ui.button>
                @endif
                @if (Route::has('inventory.products.index'))
                    <x-ui.button size="sm" variant="secondary" :href="route('inventory.products.index')">Lihat Produk</x-ui.button>
                @endif
                @if (Route::has('inventory.goods-receipts.index'))
                    <x-ui.button size="sm" variant="secondary" :href="route('inventory.goods-receipts.index')">Lihat Penerimaan Barang</x-ui.button>
                @endif
            </div>
            <p class="mt-2 text-xs">Stok awal per produk diisi dari halaman produk → tombol "Stok Awal" pada masing-masing produk.</p>
        </x-ui.alert>

        <x-ui.filter-bar :action="route('inventory.batches.index')">
            <div class="min-w-0 flex-1 md:max-w-xs">
                <x-ui.input label="Cari nomor batch/lot" id="batch-search" type="search" name="search"
                    :value="$filters['search'] ?? ''" placeholder="Nomor batch atau lot" />
            </div>
            <div class="md:w-48">
                <label for="batch-product" class="block text-sm font-medium text-navy">Produk</label>
                <x-inventory.searchable-product-select
                    id="batch-product"
                    name="product_id"
                    :products="$products"
                    :selected="$filters['product_id'] ?? null"
                    empty-label="Semua produk"
                    class="mt-1.5"
                />
            </div>
            <div class="md:w-44">
                <x-ui.select label="Pemasok" id="batch-supplier" name="supplier_id">
                    <option value="">Semua pemasok</option>
                    @foreach ($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected((int) ($filters['supplier_id'] ?? 0) === $supplier->id)>{{ $supplier->name }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            <div class="md:w-44">
                <x-ui.select label="Status kedaluwarsa" id="batch-expiry-status" name="expiry_status">
                    <option value="">Semua</option>
                    <option value="active" @selected(($filters['expiry_status'] ?? '') === 'active')>Aktif</option>
                    <option value="near_expiry" @selected(in_array($filters['expiry_status'] ?? '', ['near_expiry', 'expiring_soon'], true))>Akan Kedaluwarsa</option>
                    <option value="expired" @selected(($filters['expiry_status'] ?? '') === 'expired')>Kedaluwarsa</option>
                    <option value="no_expiry" @selected(($filters['expiry_status'] ?? '') === 'no_expiry')>Tanpa Expired</option>
                </x-ui.select>
            </div>
            <div class="md:w-36">
                <x-ui.select label="Status aktif" id="batch-active" name="is_active">
                    <option value="">Semua</option>
                    <option value="1" @selected(($filters['is_active'] ?? '') === true || ($filters['is_active'] ?? '') === '1')>Aktif</option>
                    <option value="0" @selected(($filters['is_active'] ?? '') === false || ($filters['is_active'] ?? '') === '0')>Nonaktif</option>
                </x-ui.select>
            </div>
            <x-slot:actions>
                <x-ui.button type="submit" variant="primary">Terapkan</x-ui.button>
                <x-ui.button variant="secondary" :href="route('inventory.batches.index')">Atur Ulang</x-ui.button>
            </x-slot:actions>
        </x-ui.filter-bar>

        <x-ui.card padding="">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-4 py-3">
                <div>
                    <h3 class="text-base font-semibold text-navy">Batch & Lot</h3>
                    <p class="text-sm text-ink-soft">{{ format_number_id($batches->total()) }} batch dalam lingkup cabang aktif.</p>
                </div>
                <x-ui.badge tone="info">Stok Terderivasi Ledger</x-ui.badge>
            </div>

            <div class="hidden md:block">
                <x-ui.table class="!border-0 !shadow-none !rounded-none">
                    <thead class="bg-navy-50">
                        <tr class="text-left text-ink-soft">
                            <th scope="col" class="px-4 py-3 font-medium">Produk</th>
                            <th scope="col" class="px-3 py-3 font-medium">Nomor Batch</th>
                            <th scope="col" class="px-3 py-3 font-medium">Nomor Lot</th>
                            <th scope="col" class="px-3 py-3 font-medium">Pemasok</th>
                            <th scope="col" class="px-3 py-3 font-medium">Tanggal Terima</th>
                            <th scope="col" class="px-3 py-3 font-medium">Kedaluwarsa</th>
                            <th scope="col" class="px-3 py-3 text-right font-medium">Stok</th>
                            <th scope="col" class="px-3 py-3 font-medium">Status</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @forelse ($batches as $batch)
                            @php
                                $displayStatus = $batch->is_active
                                    ? $expiryStatusService->status($batch->expiry_date)
                                    : 'inactive';
                                $derivedStock = (float) ($batch->derived_stock ?? 0);
                                $daysText = $batchService->expiryDaysText($batch);
                            @endphp
                            <tr @class([
                                'hover:bg-navy-50',
                                'bg-navy-50/60' => ! $batch->is_active,
                                'bg-danger-50/40' => $displayStatus === BatchExpiryStatusService::STATUS_EXPIRED,
                                'bg-warning-50/40' => $displayStatus === BatchExpiryStatusService::STATUS_NEAR_EXPIRY,
                            ])>
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-navy">{{ $batch->product?->name ?? '-' }}</p>
                                    <p class="mt-0.5 text-xs text-ink-soft">{{ $batch->product?->code ?? '-' }}</p>
                                </td>
                                <td class="px-3 py-3 font-medium text-navy">
                                    {{ $batch->batch_number }}
                                    @if (str_starts_with((string) $batch->batch_number, 'AUTO-'))
                                        <span class="ml-1 inline-flex rounded-full bg-info-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-info-700">Auto</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-ink-soft">{{ $batch->lot_number ?? '-' }}</td>
                                <td class="px-3 py-3 text-ink-soft">{{ $batch->supplier?->name ?? '-' }}</td>
                                <td class="px-3 py-3 text-ink-soft">{{ format_date_id($batch->received_date) }}</td>
                                <td class="px-3 py-3 text-ink-soft">
                                    @if ($batch->expiry_date)
                                        <div>{{ format_date_id($batch->expiry_date) }}</div>
                                        <div class="text-xs text-ink-soft">{{ $daysText }}</div>
                                    @else
                                        <span>{{ $daysText }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums font-semibold text-navy">{{ format_quantity_id($derivedStock) }}</td>
                                <td class="px-3 py-3">
                                    @if ($batch->is_active)
                                        @include('inventory.batches._batch-expiry-status-badge', ['expiryStatus' => $displayStatus])
                                    @else
                                        @include('inventory.batches._batch-status-badge', ['status' => 'inactive'])
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('inventory.batches.show', $batch) }}" class="font-medium text-brand-700 hover:text-brand-800">Lihat</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-12">
                                    <x-ui.empty-state title="Belum ada batch & lot"
                                        description="Batch muncul setelah penerimaan stok atau penyesuaian dengan identitas batch." class="border-0 bg-transparent shadow-none" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </x-ui.table>
            </div>

            <div class="divide-y divide-hairline md:hidden">
                @forelse ($batches as $batch)
                    @php
                        $displayStatus = $batch->is_active
                            ? $expiryStatusService->status($batch->expiry_date)
                            : 'inactive';
                        $derivedStock = (float) ($batch->derived_stock ?? 0);
                        $daysText = $batchService->expiryDaysText($batch);
                    @endphp
                    <article @class([
                        'p-4',
                        'bg-danger-50/40' => $displayStatus === BatchExpiryStatusService::STATUS_EXPIRED,
                        'bg-warning-50/40' => $displayStatus === BatchExpiryStatusService::STATUS_NEAR_EXPIRY,
                    ])>
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-navy">
                                    {{ $batch->batch_number }}
                                    @if (str_starts_with((string) $batch->batch_number, 'AUTO-'))
                                        <span class="ml-1 inline-flex rounded-full bg-info-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-info-700">Auto</span>
                                    @endif
                                </p>
                                <p class="mt-0.5 text-sm text-ink-soft">{{ $batch->product?->name ?? '-' }}</p>
                            </div>
                            @if ($batch->is_active)
                                @include('inventory.batches._batch-expiry-status-badge', ['expiryStatus' => $displayStatus])
                            @else
                                @include('inventory.batches._batch-status-badge', ['status' => 'inactive'])
                            @endif
                        </div>
                        <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                            <div><dt class="text-ink-soft">Lot</dt><dd class="font-medium text-navy">{{ $batch->lot_number ?? '-' }}</dd></div>
                            <div><dt class="text-ink-soft">Pemasok</dt><dd class="font-medium text-navy">{{ $batch->supplier?->name ?? '-' }}</dd></div>
                            <div><dt class="text-ink-soft">Terima</dt><dd class="font-medium text-navy">{{ format_date_id($batch->received_date) }}</dd></div>
                            <div><dt class="text-ink-soft">Kedaluwarsa</dt><dd class="font-medium text-navy">{{ $batch->expiry_date ? format_date_id($batch->expiry_date) : $daysText }}</dd></div>
                            <div class="col-span-2"><dt class="text-ink-soft">Sisa waktu</dt><dd class="font-medium text-navy">{{ $daysText }}</dd></div>
                            <div class="col-span-2"><dt class="text-ink-soft">Stok (ledger)</dt><dd class="font-semibold tabular-nums text-navy">{{ format_quantity_id($derivedStock) }}</dd></div>
                        </dl>
                        <div class="mt-3">
                            <a href="{{ route('inventory.batches.show', $batch) }}" class="text-sm font-medium text-brand-700 hover:text-brand-800">Lihat detail</a>
                        </div>
                    </article>
                @empty
                    <div class="p-4">
                        <x-ui.empty-state title="Belum ada batch & lot dalam cabang aktif." class="border-0 bg-transparent shadow-none" />
                    </div>
                @endforelse
            </div>

            @if ($batches->hasPages())
                <div class="border-t border-hairline px-4 py-3">{{ $batches->links() }}</div>
            @endif
        </x-ui.card>
    </div>
</x-settings-shell>
