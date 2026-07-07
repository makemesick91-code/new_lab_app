@php
    use App\Modules\Inventory\Models\InventoryMovement;

    $movementLabels = [
        InventoryMovement::TYPE_OPENING => 'Stok Awal',
        InventoryMovement::TYPE_PURCHASE => 'Pembelian / Terima Stok',
        InventoryMovement::TYPE_ADJUSTMENT_IN => 'Penyesuaian Masuk',
        InventoryMovement::TYPE_ADJUSTMENT_OUT => 'Penyesuaian Keluar',
        InventoryMovement::TYPE_TRANSFER_OUT => 'Transfer Keluar',
        InventoryMovement::TYPE_TRANSFER_IN => 'Transfer Masuk',
    ];
@endphp

<x-settings-shell title="Detail Batch {{ $batch->batch_number }}">
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">Detail Batch & Lot</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">
                    {{ $batch->batch_number }}
                    @if (str_starts_with((string) $batch->batch_number, 'AUTO-'))
                        <span class="ml-2 inline-flex rounded-full bg-sky-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-800 align-middle">Auto</span>
                    @endif
                </h2>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $batch->product?->name ?? '-' }}
                    @if ($batch->lot_number)
                        · Lot {{ $batch->lot_number }}
                    @endif
                </p>
            </div>
            <a href="{{ route('inventory.batches.index') }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                Kembali
            </a>
        </div>

        @if ($isExpired)
            <div class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
                <p class="font-semibold">Peringatan: Batch Kedaluwarsa</p>
                <p class="mt-1">Batch ini sudah melewati tanggal kedaluwarsa ({{ format_date_id($batch->expiry_date) }}). {{ $expiryDaysText }}.</p>
            </div>
        @elseif ($isExpiringSoon)
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800" role="alert">
                <p class="font-semibold">Peringatan: Akan Kedaluwarsa</p>
                <p class="mt-1">Batch ini akan kedaluwarsa pada {{ format_date_id($batch->expiry_date) }} ({{ $expiryDaysText }}).</p>
            </div>
        @endif

        @if ($latestActionLog)
            <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm">
                <span class="text-gray-600">Tindakan terakhir:</span>
                @include('inventory.batches._batch-action-type-badge', ['actionType' => $latestActionLog->action_type])
                <span class="text-gray-500">· {{ format_datetime_id($latestActionLog->acted_at) }} · {{ $latestActionLog->actor?->name ?? '—' }}</span>
            </div>
        @endif

        @if (session('status'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800" role="status">
                {{ session('status') }}
            </div>
        @endif

        <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-base font-semibold text-gray-900">Identitas Batch</h3>
                @if ($batch->is_active)
                    @include('inventory.batches._batch-expiry-status-badge', ['expiryStatus' => $expiryStatus])
                @else
                    @include('inventory.batches._batch-status-badge', ['status' => 'inactive'])
                @endif
            </div>
            <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div><dt class="text-gray-500">Nomor Batch</dt><dd class="font-medium text-gray-900">{{ $batch->batch_number }}</dd></div>
                <div><dt class="text-gray-500">Nomor Lot</dt><dd class="font-medium text-gray-900">{{ $batch->lot_number ?? '-' }}</dd></div>
                <div><dt class="text-gray-500">Produk</dt><dd class="font-medium text-gray-900">{{ $batch->product?->name ?? '-' }} ({{ $batch->product?->code ?? '-' }})</dd></div>
                <div><dt class="text-gray-500">Pemasok</dt><dd class="font-medium text-gray-900">{{ $batch->supplier?->name ?? '-' }}</dd></div>
                <div><dt class="text-gray-500">Cabang</dt><dd class="font-medium text-gray-900">{{ $batch->branch?->name ?? '-' }}</dd></div>
                <div><dt class="text-gray-500">Tanggal Terima</dt><dd class="font-medium text-gray-900">{{ format_date_id($batch->received_date) }}</dd></div>
                <div><dt class="text-gray-500">Tanggal Kedaluwarsa</dt><dd class="font-medium text-gray-900">{{ $batch->expiry_date ? format_date_id($batch->expiry_date) : 'Tanpa tanggal kedaluwarsa' }}</dd></div>
                <div><dt class="text-gray-500">Status Kedaluwarsa</dt><dd class="font-medium text-gray-900">{{ $expiryDaysText }}</dd></div>
                <div><dt class="text-gray-500">Dibuat Oleh</dt><dd class="font-medium text-gray-900">{{ $batch->createdBy?->name ?? '-' }}</dd></div>
                @if ($batch->notes)
                    <div class="sm:col-span-2 lg:col-span-3"><dt class="text-gray-500">Catatan</dt><dd class="font-medium text-gray-900">{{ $batch->notes }}</dd></div>
                @endif
            </dl>
        </section>

        <section class="rounded-lg border border-brand-100 bg-brand-50 p-6 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">Stok Terderivasi Ledger</p>
            <p class="mt-2 text-3xl font-semibold tabular-nums text-brand-800">{{ format_quantity_id($totalStock) }}</p>
            <p class="mt-1 text-sm text-brand-700">{{ $batch->product?->unit?->symbol ?? 'satuan' }} total di seluruh lokasi cabang aktif.</p>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-200 px-4 py-3">
                <h3 class="text-base font-semibold text-gray-900">Stok per Lokasi</h3>
                <p class="text-sm text-gray-500">Jumlah dihitung dari SUM(masuk) − SUM(keluar) per lokasi.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-gray-500">
                            <th scope="col" class="px-4 py-3 font-medium">Lokasi</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Stok</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($stockByLocation as $row)
                            @php $locStock = (float) ($row->derived_stock ?? 0); @endphp
                            @if ($locStock != 0)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-gray-900">{{ $row->inventoryLocation?->name ?? '-' }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums font-semibold text-gray-900">{{ format_quantity_id($locStock) }}</td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="2" class="px-4 py-8 text-center text-gray-500">Belum ada pergerakan stok untuk batch ini.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        @include('inventory.batches._batch-action-log-form', ['batch' => $batch])
        @include('inventory.batches._batch-action-log-history', ['actionLogHistory' => $actionLogHistory])

        @if (($disposalRequests ?? collect())->isNotEmpty())
            <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 px-4 py-3 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-base font-semibold text-gray-900">Permintaan Disposal/Adjustment</h3>
                    @can('viewAny', \App\Modules\Inventory\Models\InventoryBatchDisposalRequest::class)
                        <a href="{{ route('inventory.batch-disposal-requests.index') }}" class="text-sm font-medium text-brand-700 hover:text-brand-600">Lihat semua</a>
                    @endcan
                </div>
                <div class="divide-y divide-gray-100">
                    @foreach ($disposalRequests as $disposalReq)
                        <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm">
                            <div>
                                @include('inventory.batch-disposal-requests._request-type-badge', ['requestType' => $disposalReq->request_type])
                                @include('inventory.batch-disposal-requests._status-badge', ['status' => $disposalReq->status])
                                <p class="mt-1 text-gray-600">{{ $disposalReq->location?->name ?? '—' }} · {{ format_quantity_id((float) $disposalReq->quantity_requested) }}</p>
                            </div>
                            <a href="{{ route('inventory.batch-disposal-requests.show', $disposalReq) }}" class="font-medium text-brand-700 hover:text-brand-600">Detail</a>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @include('inventory.batches._batch-disposal-request-form', [
            'batch' => $batch,
            'stockByLocation' => $stockByLocation,
            'latestActionLog' => $latestActionLog,
        ])

        @if ($transferReferences->isNotEmpty())
            <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 px-4 py-3">
                    <h3 class="text-base font-semibold text-gray-900">Referensi Transfer Stok</h3>
                </div>
                <div class="divide-y divide-gray-100">
                    @foreach ($transferReferences as $item)
                        <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm">
                            <div>
                                <p class="font-medium text-gray-900">{{ $item->stockTransfer?->transfer_number ?? '-' }}</p>
                                <p class="mt-0.5 text-gray-500">
                                    {{ $item->stockTransfer?->sourceInventoryLocation?->name ?? '-' }}
                                    → {{ $item->stockTransfer?->destinationInventoryLocation?->name ?? '-' }}
                                    · {{ format_quantity_id((float) $item->quantity) }} {{ $batch->product?->unit?->symbol ?? '' }}
                                </p>
                            </div>
                            @if ($item->stockTransfer)
                                <a href="{{ route('inventory.stock-transfers.show', $item->stockTransfer) }}" class="font-medium text-brand-700 hover:text-brand-600">Lihat transfer</a>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-200 px-4 py-3">
                <h3 class="text-base font-semibold text-gray-900">Riwayat Pergerakan Batch</h3>
                <p class="text-sm text-gray-500">{{ format_number_id($movements->count()) }} pergerakan ledger untuk batch ini.</p>
            </div>
            <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-gray-500">
                            <th scope="col" class="px-4 py-3 font-medium">Tanggal</th>
                            <th scope="col" class="px-3 py-3 font-medium">Tipe</th>
                            <th scope="col" class="px-3 py-3 font-medium">Lokasi</th>
                            <th scope="col" class="px-3 py-3 text-right font-medium">Masuk</th>
                            <th scope="col" class="px-3 py-3 text-right font-medium">Keluar</th>
                            <th scope="col" class="px-3 py-3 font-medium">Catatan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($movements as $movement)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-gray-600">{{ format_date_id($movement->movement_date) }}</td>
                                <td class="px-3 py-3 text-gray-900">{{ $movementLabels[$movement->movement_type] ?? $movement->movement_type }}</td>
                                <td class="px-3 py-3 text-gray-600">{{ $movement->inventoryLocation?->name ?? '-' }}</td>
                                <td class="px-3 py-3 text-right tabular-nums text-emerald-700">{{ $movement->quantity_in > 0 ? '+'.format_quantity_id((float) $movement->quantity_in) : '-' }}</td>
                                <td class="px-3 py-3 text-right tabular-nums text-rose-700">{{ $movement->quantity_out > 0 ? '-'.format_quantity_id((float) $movement->quantity_out) : '-' }}</td>
                                <td class="px-3 py-3 text-gray-500">{{ $movement->notes ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-gray-500">Belum ada pergerakan untuk batch ini.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="divide-y divide-gray-100 md:hidden">
                @forelse ($movements as $movement)
                    <article class="p-4 text-sm">
                        <div class="flex items-center justify-between gap-2">
                            <p class="font-medium text-gray-900">{{ $movementLabels[$movement->movement_type] ?? $movement->movement_type }}</p>
                            <p class="text-gray-500">{{ format_date_id($movement->movement_date) }}</p>
                        </div>
                        <p class="mt-1 text-gray-600">{{ $movement->inventoryLocation?->name ?? '-' }}</p>
                        <p class="mt-2 tabular-nums">
                            @if ($movement->quantity_in > 0)
                                <span class="font-semibold text-emerald-700">+{{ format_quantity_id((float) $movement->quantity_in) }}</span>
                            @endif
                            @if ($movement->quantity_out > 0)
                                <span class="font-semibold text-rose-700">-{{ format_quantity_id((float) $movement->quantity_out) }}</span>
                            @endif
                        </p>
                    </article>
                @empty
                    <div class="p-8 text-center text-gray-500">Belum ada pergerakan untuk batch ini.</div>
                @endforelse
            </div>
        </section>
    </div>
</x-settings-shell>
