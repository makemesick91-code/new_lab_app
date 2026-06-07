@php
    use App\Modules\Inventory\Models\InventoryMovement;
    use App\Modules\Inventory\Models\StockTransfer;

    $movementLabels = [
        InventoryMovement::TYPE_TRANSFER_OUT => 'Transfer Keluar',
        InventoryMovement::TYPE_TRANSFER_IN => 'Transfer Masuk',
    ];
@endphp

<x-settings-shell title="Transfer Stok {{ $stockTransfer->transfer_number }}">
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Detail Transfer Stok</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">{{ $stockTransfer->transfer_number }}</h2>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $stockTransfer->sourceInventoryLocation?->name ?? '-' }} → {{ $stockTransfer->destinationInventoryLocation?->name ?? '-' }}
                </p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    @include('inventory.stock-transfers._status-badge', ['status' => $stockTransfer->status])
                </div>
            </div>
            <div class="flex flex-col items-stretch gap-2 sm:items-end">
                <div class="flex flex-wrap items-center justify-end gap-2">
                    @can('submit', $stockTransfer)
                        @if ($stockTransfer->status === StockTransfer::STATUS_DRAFT)
                            <form method="POST" action="{{ route('inventory.stock-transfers.submit', $stockTransfer) }}">
                                @csrf
                                <button type="submit" class="inline-flex items-center rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2">
                                    Ajukan Transfer
                                </button>
                            </form>
                        @endif
                    @endcan

                    @can('ship', $stockTransfer)
                        @if ($stockTransfer->status === StockTransfer::STATUS_SUBMITTED)
                            <form method="POST" action="{{ route('inventory.stock-transfers.ship', $stockTransfer) }}">
                                @csrf
                                <button type="submit" class="inline-flex items-center rounded-lg bg-sky-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:ring-offset-2">
                                    Kirim Transfer
                                </button>
                            </form>
                        @endif
                    @endcan

                    @can('receive', $stockTransfer)
                        @if ($stockTransfer->status === StockTransfer::STATUS_IN_TRANSIT)
                            <form method="POST" action="{{ route('inventory.stock-transfers.receive', $stockTransfer) }}">
                                @csrf
                                <button type="submit" class="inline-flex items-center rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                                    Terima Transfer
                                </button>
                            </form>
                        @endif
                    @endcan
                </div>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    @can('downloadChecklist', $stockTransfer)
                        @if ($stockTransfer->isInTransit() || $stockTransfer->isReceived())
                            <a href="{{ route('inventory.stock-transfers.checklist', $stockTransfer) }}" class="inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                                Download Checklist PDF
                            </a>
                        @endif
                    @endcan

                    @can('update', $stockTransfer)
                        @if ($stockTransfer->status === StockTransfer::STATUS_DRAFT)
                            <a href="{{ route('inventory.stock-transfers.edit', $stockTransfer) }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                                Ubah
                            </a>
                        @endif
                    @endcan

                    @can('cancel', $stockTransfer)
                        @if (in_array($stockTransfer->status, [StockTransfer::STATUS_DRAFT, StockTransfer::STATUS_SUBMITTED], true))
                            <form method="POST" action="{{ route('inventory.stock-transfers.cancel', $stockTransfer) }}" class="flex flex-wrap items-center gap-2">
                                @csrf
                                <input type="text" name="notes" placeholder="Alasan pembatalan" value="{{ old('notes') }}"
                                       class="rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                                <button type="submit" class="inline-flex items-center rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2">
                                    Batalkan
                                </button>
                            </form>
                        @endif
                    @endcan

                    <a href="{{ route('inventory.stock-transfers.index') }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                        Kembali
                    </a>
                </div>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium text-gray-500">Tanggal Transfer</p>
                <p class="mt-1 text-sm font-semibold tabular-nums text-gray-900">{{ format_date_id($stockTransfer->transfer_date) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium text-gray-500">Dibuat Oleh</p>
                <p class="mt-1 text-sm font-semibold text-gray-900">{{ $stockTransfer->createdBy?->name ?? '—' }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium text-gray-500">Jumlah Item</p>
                <p class="mt-1 text-sm font-semibold tabular-nums text-gray-900">{{ format_number_id($stockTransfer->items->count()) }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium text-gray-500">Status</p>
                <div class="mt-2">
                    @include('inventory.stock-transfers._status-badge', ['status' => $stockTransfer->status])
                </div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 px-4 py-3">
                        <h3 class="text-base font-semibold text-gray-900">Item Transfer</h3>
                    </div>
                    <div class="hidden overflow-x-auto md:block">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr class="text-left text-gray-500">
                                    <th scope="col" class="px-4 py-3 font-medium">Produk</th>
                                    <th scope="col" class="px-3 py-3 font-medium">Batch</th>
                                    <th scope="col" class="px-3 py-3 text-right font-medium">Jumlah</th>
                                    <th scope="col" class="px-4 py-3 font-medium">Catatan</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($stockTransfer->items as $item)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3">
                                            <p class="font-semibold text-gray-900">{{ $item->product?->name ?? '-' }}</p>
                                            <p class="text-xs text-gray-500">{{ $item->product?->code ?? '-' }}</p>
                                        </td>
                                        <td class="px-3 py-3 text-gray-600">
                                            @if ($item->inventoryBatch)
                                                <p class="font-medium text-gray-900">{{ $item->inventoryBatch->batch_number }}</p>
                                                @if ($item->inventoryBatch->lot_number)
                                                    <p class="text-xs text-gray-500">Lot {{ $item->inventoryBatch->lot_number }}</p>
                                                @endif
                                                @if ($item->inventoryBatch->expiry_date)
                                                    <p class="text-xs text-gray-500">Kedaluwarsa {{ format_date_id($item->inventoryBatch->expiry_date) }}</p>
                                                @endif
                                            @else
                                                <span class="text-gray-400">—</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3 text-right tabular-nums text-gray-700">
                                            {{ format_quantity_id($item->quantity) }}
                                            @if ($item->product?->unit?->symbol)
                                                <span class="text-xs text-gray-500">{{ $item->product->unit->symbol }}</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-gray-600">{{ $item->notes ?? '-' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="px-4 py-12">
                                            <div class="mx-auto max-w-sm text-center">
                                                <p class="text-sm font-medium text-gray-900">Belum ada item transfer.</p>
                                                <p class="mt-1 text-sm text-gray-500">Tambahkan produk saat transfer masih draft.</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="divide-y divide-gray-100 md:hidden">
                        @forelse ($stockTransfer->items as $item)
                            <article class="p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $item->product?->code ?? '-' }}</p>
                                        <h4 class="mt-1 text-base font-semibold text-gray-900">{{ $item->product?->name ?? '-' }}</h4>
                                    </div>
                                    <p class="shrink-0 text-sm font-semibold tabular-nums text-gray-900">
                                        {{ format_quantity_id($item->quantity) }}
                                        @if ($item->product?->unit?->symbol)
                                            <span class="text-xs font-normal text-gray-500">{{ $item->product->unit->symbol }}</span>
                                        @endif
                                    </p>
                                </div>
                                @if ($item->inventoryBatch)
                                    <p class="mt-2 text-sm text-gray-600">Batch {{ $item->inventoryBatch->batch_number }}</p>
                                @endif
                                @if ($item->notes)
                                    <p class="mt-2 text-sm text-gray-500">{{ $item->notes }}</p>
                                @endif
                            </article>
                        @empty
                            <div class="px-4 py-10 text-center">
                                <p class="text-sm font-medium text-gray-900">Belum ada item transfer.</p>
                                <p class="mt-1 text-sm text-gray-500">Tambahkan produk saat transfer masih draft.</p>
                            </div>
                        @endforelse
                    </div>
                </div>

                @if (($stockTransfer->isInTransit() || $stockTransfer->isReceived()) && ($ledgerMovements ?? collect())->isNotEmpty())
                    <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
                        <div class="border-b border-gray-200 px-4 py-3">
                            <h3 class="text-base font-semibold text-gray-900">Referensi Pergerakan Ledger</h3>
                            <p class="mt-1 text-sm text-gray-500">Pergerakan stok yang dihasilkan saat transfer dikirim atau diterima.</p>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50">
                                    <tr class="text-left text-gray-500">
                                        <th scope="col" class="px-4 py-3 font-medium">Produk</th>
                                        <th scope="col" class="px-3 py-3 font-medium">Lokasi</th>
                                        <th scope="col" class="px-3 py-3 font-medium">Batch</th>
                                        <th scope="col" class="px-3 py-3 font-medium">Tipe</th>
                                        <th scope="col" class="px-3 py-3 text-right font-medium">Masuk</th>
                                        <th scope="col" class="px-3 py-3 text-right font-medium">Keluar</th>
                                        <th scope="col" class="px-3 py-3 font-medium">Tanggal</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach ($ledgerMovements as $movement)
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-3">
                                                <p class="font-medium text-gray-900">{{ $movement->product?->name ?? '-' }}</p>
                                                <p class="text-xs text-gray-500">{{ $movement->product?->code ?? '-' }}</p>
                                            </td>
                                            <td class="px-3 py-3 text-gray-600">{{ $movement->inventoryLocation?->name ?? '-' }}</td>
                                            <td class="px-3 py-3 text-gray-600">
                                                @if ($movement->inventoryBatch)
                                                    {{ $movement->inventoryBatch->batch_number }}
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td class="px-3 py-3 text-gray-600">{{ $movementLabels[$movement->movement_type] ?? $movement->movement_type }}</td>
                                            <td class="px-3 py-3 text-right tabular-nums text-emerald-700">
                                                @if ((float) $movement->quantity_in > 0)
                                                    +{{ format_quantity_id($movement->quantity_in) }}
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td class="px-3 py-3 text-right tabular-nums text-rose-700">
                                                @if ((float) $movement->quantity_out > 0)
                                                    -{{ format_quantity_id($movement->quantity_out) }}
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td class="px-3 py-3 tabular-nums text-gray-600">{{ format_date_id($movement->movement_date) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>

            <div class="space-y-6">
                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <h3 class="text-base font-semibold text-gray-900">Riwayat & Catatan</h3>
                    <dl class="mt-4 space-y-3">
                        <div class="flex justify-between gap-3">
                            <dt class="text-sm text-gray-600">Lokasi Sumber</dt>
                            <dd class="text-right text-sm text-gray-900">{{ $stockTransfer->sourceInventoryLocation?->name ?? '-' }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-sm text-gray-600">Lokasi Tujuan</dt>
                            <dd class="text-right text-sm text-gray-900">{{ $stockTransfer->destinationInventoryLocation?->name ?? '-' }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-sm text-gray-600">Diminta Oleh</dt>
                            <dd class="text-sm text-gray-900">{{ $stockTransfer->requestedBy?->name ?? '-' }}</dd>
                        </div>
                        @if ($stockTransfer->shippedBy)
                            <div class="flex justify-between gap-3">
                                <dt class="text-sm text-gray-600">Dikirim Oleh</dt>
                                <dd class="text-sm text-gray-900">{{ $stockTransfer->shippedBy->name }}</dd>
                            </div>
                        @endif
                        @if ($stockTransfer->shipped_at)
                            <div class="flex justify-between gap-3">
                                <dt class="text-sm text-gray-600">Dikirim Pada</dt>
                                <dd class="text-sm tabular-nums text-gray-900">{{ format_datetime_id($stockTransfer->shipped_at) }}</dd>
                            </div>
                        @endif
                        @if ($stockTransfer->approvedBy)
                            <div class="flex justify-between gap-3">
                                <dt class="text-sm text-gray-600">Diterima Oleh</dt>
                                <dd class="text-sm text-gray-900">{{ $stockTransfer->approvedBy->name }}</dd>
                            </div>
                        @endif
                        @if ($stockTransfer->completed_at)
                            <div class="flex justify-between gap-3">
                                <dt class="text-sm text-gray-600">Diterima Pada</dt>
                                <dd class="text-sm tabular-nums text-gray-900">{{ format_datetime_id($stockTransfer->completed_at) }}</dd>
                            </div>
                        @endif
                        @if ($stockTransfer->notes)
                            <div>
                                <dt class="text-sm text-gray-600">Catatan</dt>
                                <dd class="mt-1 text-sm text-gray-900">{{ $stockTransfer->notes }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>
            </div>
        </div>
    </div>
</x-settings-shell>
