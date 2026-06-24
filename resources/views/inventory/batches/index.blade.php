@php
    use App\Modules\Inventory\Services\InventoryBatchService;

    $batchService = app(InventoryBatchService::class);
@endphp

<x-settings-shell title="Batch & Lot">
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Batch & Lot</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">Direktori Batch & Lot</h2>
                <p class="mt-1 text-sm text-gray-500">Stok batch dihitung dari ledger pergerakan. Tidak ada kolom stok mutable pada batch.</p>
            </div>
        </div>

        <div class="rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900" role="note">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="max-w-2xl">
                    <p class="font-semibold text-sky-900">Halaman pemantauan Batch &amp; Lot</p>
                    <p class="mt-1 text-sky-800">Batch/Lot tidak dibuat manual dari halaman ini. Batch/Lot akan muncul otomatis setelah stok masuk melalui Goods Receipt, Opening Stock, atau Adjustment In.</p>
                </div>
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
                @if (Route::has('inventory.goods-receipts.create'))
                    <a href="{{ route('inventory.goods-receipts.create') }}" class="inline-flex items-center rounded-lg bg-teal-700 px-3 py-2 text-xs font-semibold text-white hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                        Input via Goods Receipt
                    </a>
                @endif
                @if (Route::has('inventory.products.index'))
                    <a href="{{ route('inventory.products.index') }}" class="inline-flex items-center rounded-lg border border-sky-300 bg-white px-3 py-2 text-xs font-semibold text-sky-800 hover:bg-sky-100 focus:outline-none focus:ring-2 focus:ring-sky-400 focus:ring-offset-2">
                        Input Stok Awal Produk
                    </a>
                @endif
                @if (Route::has('inventory.products.index'))
                    <a href="{{ route('inventory.products.index') }}" class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-300 focus:ring-offset-2">
                        Lihat Produk
                    </a>
                @endif
                @if (Route::has('inventory.goods-receipts.index'))
                    <a href="{{ route('inventory.goods-receipts.index') }}" class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-300 focus:ring-offset-2">
                        Lihat Penerimaan Barang
                    </a>
                @endif
            </div>
            <p class="mt-2 text-xs text-sky-700">Stok awal per produk diisi dari halaman produk → tombol "Stok Awal" pada masing-masing produk.</p>
        </div>

        <form method="GET" action="{{ route('inventory.batches.index') }}" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 md:items-end">
                <div class="xl:col-span-2">
                    <label for="batch-search" class="text-sm font-medium text-gray-700">Cari nomor batch/lot</label>
                    <input id="batch-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Nomor batch atau lot"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div>
                    <label for="batch-product" class="text-sm font-medium text-gray-700">Produk</label>
                    <select id="batch-product" name="product_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">Semua produk</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}" @selected((int) ($filters['product_id'] ?? 0) === $product->id)>{{ $product->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="batch-supplier" class="text-sm font-medium text-gray-700">Pemasok</label>
                    <select id="batch-supplier" name="supplier_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">Semua pemasok</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}" @selected((int) ($filters['supplier_id'] ?? 0) === $supplier->id)>{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="batch-expiry-status" class="text-sm font-medium text-gray-700">Status kedaluwarsa</label>
                    <select id="batch-expiry-status" name="expiry_status" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">Semua</option>
                        <option value="expired" @selected(($filters['expiry_status'] ?? '') === 'expired')>Kedaluwarsa</option>
                        <option value="expiring_soon" @selected(($filters['expiry_status'] ?? '') === 'expiring_soon')>Segera Kedaluwarsa</option>
                        <option value="valid" @selected(($filters['expiry_status'] ?? '') === 'valid')>Belum Kedaluwarsa</option>
                    </select>
                </div>
                <div>
                    <label for="batch-active" class="text-sm font-medium text-gray-700">Status aktif</label>
                    <select id="batch-active" name="is_active" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">Semua</option>
                        <option value="1" @selected(($filters['is_active'] ?? '') === true || ($filters['is_active'] ?? '') === '1')>Aktif</option>
                        <option value="0" @selected(($filters['is_active'] ?? '') === false || ($filters['is_active'] ?? '') === '0')>Nonaktif</option>
                    </select>
                </div>
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
                <button class="inline-flex justify-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-700 focus:ring-offset-2">
                    Terapkan
                </button>
                <a href="{{ route('inventory.batches.index') }}" class="inline-flex justify-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Atur Ulang
                </a>
            </div>
        </form>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Batch & Lot</h3>
                    <p class="text-sm text-gray-500">{{ format_number_id($batches->total()) }} batch dalam lingkup cabang aktif.</p>
                </div>
                <span class="inline-flex rounded-full bg-sky-50 px-3 py-1 text-xs font-medium text-sky-700">Stok Terderivasi Ledger</span>
            </div>

            <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-gray-500">
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
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($batches as $batch)
                            @php
                                $displayStatus = $batchService->resolveDisplayStatus($batch);
                                $derivedStock = (float) ($batch->derived_stock ?? 0);
                            @endphp
                            <tr @class([
                                'hover:bg-gray-50',
                                'bg-gray-50/60' => ! $batch->is_active,
                                'bg-rose-50/40' => $displayStatus === 'expired',
                                'bg-amber-50/40' => $displayStatus === 'expiring_soon',
                            ])>
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-gray-900">{{ $batch->product?->name ?? '-' }}</p>
                                    <p class="mt-0.5 text-xs text-gray-500">{{ $batch->product?->code ?? '-' }}</p>
                                </td>
                                <td class="px-3 py-3 font-medium text-gray-900">{{ $batch->batch_number }}</td>
                                <td class="px-3 py-3 text-gray-600">{{ $batch->lot_number ?? '-' }}</td>
                                <td class="px-3 py-3 text-gray-600">{{ $batch->supplier?->name ?? '-' }}</td>
                                <td class="px-3 py-3 text-gray-600">{{ format_date_id($batch->received_date) }}</td>
                                <td class="px-3 py-3 text-gray-600">{{ $batch->expiry_date ? format_date_id($batch->expiry_date) : '-' }}</td>
                                <td class="px-3 py-3 text-right tabular-nums font-semibold text-gray-900">{{ format_quantity_id($derivedStock) }}</td>
                                <td class="px-3 py-3">@include('inventory.batches._batch-status-badge', ['status' => $displayStatus])</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('inventory.batches.show', $batch) }}" class="font-medium text-teal-700 hover:text-teal-600">Lihat</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-12">
                                    <div class="mx-auto max-w-sm text-center">
                                        <p class="text-sm font-medium text-gray-900">Belum ada batch & lot</p>
                                        <p class="mt-1 text-sm text-gray-500">Batch muncul setelah penerimaan stok atau penyesuaian dengan identitas batch.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-gray-100 md:hidden">
                @forelse ($batches as $batch)
                    @php
                        $displayStatus = $batchService->resolveDisplayStatus($batch);
                        $derivedStock = (float) ($batch->derived_stock ?? 0);
                    @endphp
                    <article @class([
                        'p-4',
                        'bg-rose-50/40' => $displayStatus === 'expired',
                        'bg-amber-50/40' => $displayStatus === 'expiring_soon',
                    ])>
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900">{{ $batch->batch_number }}</p>
                                <p class="mt-0.5 text-sm text-gray-600">{{ $batch->product?->name ?? '-' }}</p>
                            </div>
                            @include('inventory.batches._batch-status-badge', ['status' => $displayStatus])
                        </div>
                        <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                            <div><dt class="text-gray-500">Lot</dt><dd class="font-medium text-gray-900">{{ $batch->lot_number ?? '-' }}</dd></div>
                            <div><dt class="text-gray-500">Pemasok</dt><dd class="font-medium text-gray-900">{{ $batch->supplier?->name ?? '-' }}</dd></div>
                            <div><dt class="text-gray-500">Terima</dt><dd class="font-medium text-gray-900">{{ format_date_id($batch->received_date) }}</dd></div>
                            <div><dt class="text-gray-500">Kedaluwarsa</dt><dd class="font-medium text-gray-900">{{ $batch->expiry_date ? format_date_id($batch->expiry_date) : '-' }}</dd></div>
                            <div class="col-span-2"><dt class="text-gray-500">Stok (ledger)</dt><dd class="font-semibold tabular-nums text-gray-900">{{ format_quantity_id($derivedStock) }}</dd></div>
                        </dl>
                        <div class="mt-3">
                            <a href="{{ route('inventory.batches.show', $batch) }}" class="text-sm font-medium text-teal-700 hover:text-teal-600">Lihat detail</a>
                        </div>
                    </article>
                @empty
                    <div class="p-8 text-center text-sm text-gray-500">Belum ada batch & lot dalam cabang aktif.</div>
                @endforelse
            </div>

            @if ($batches->hasPages())
                <div class="border-t border-gray-200 px-4 py-3">{{ $batches->links() }}</div>
            @endif
        </section>
    </div>
</x-settings-shell>
