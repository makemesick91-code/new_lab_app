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
                    · {{ format_date_id($stockTransfer->transfer_date) }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @can('update', $stockTransfer)
                    @if ($stockTransfer->status === StockTransfer::STATUS_DRAFT)
                        <a href="{{ route('inventory.stock-transfers.edit', $stockTransfer) }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                            Ubah
                        </a>
                    @endif
                @endcan

                @can('submit', $stockTransfer)
                    @if ($stockTransfer->status === StockTransfer::STATUS_DRAFT)
                        <form method="POST" action="{{ route('inventory.stock-transfers.submit', $stockTransfer) }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center rounded-lg bg-yellow-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-yellow-500 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2">
                                Ajukan Transfer
                            </button>
                        </form>
                    @endif
                @endcan

                @can('complete', $stockTransfer)
                    @if ($stockTransfer->status === StockTransfer::STATUS_SUBMITTED)
                        <form method="POST" action="{{ route('inventory.stock-transfers.complete', $stockTransfer) }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                                Selesaikan Transfer
                            </button>
                        </form>
                    @endif
                @endcan

                @can('cancel', $stockTransfer)
                    @if (in_array($stockTransfer->status, [StockTransfer::STATUS_DRAFT, StockTransfer::STATUS_SUBMITTED], true))
                        <form method="POST" action="{{ route('inventory.stock-transfers.cancel', $stockTransfer) }}" class="flex flex-wrap items-center gap-2">
                            @csrf
                            <input type="text" name="notes" placeholder="Alasan pembatalan" value="{{ old('notes') }}"
                                   class="rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            <button type="submit" class="inline-flex items-center rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
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

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 px-4 py-3">
                        <h3 class="text-base font-semibold text-gray-900">Item Transfer</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr class="text-left text-gray-500">
                                    <th scope="col" class="px-4 py-3 font-medium">Produk</th>
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
                                        <td colspan="3" class="px-4 py-12">
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
                </div>

                @if ($stockTransfer->status === StockTransfer::STATUS_COMPLETED && ($ledgerMovements ?? collect())->isNotEmpty())
                    <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
                        <div class="border-b border-gray-200 px-4 py-3">
                            <h3 class="text-base font-semibold text-gray-900">Referensi Pergerakan Ledger</h3>
                            <p class="mt-1 text-sm text-gray-500">Pergerakan stok yang dihasilkan saat transfer diselesaikan.</p>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50">
                                    <tr class="text-left text-gray-500">
                                        <th scope="col" class="px-4 py-3 font-medium">Produk</th>
                                        <th scope="col" class="px-3 py-3 font-medium">Lokasi</th>
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
                                            <td class="px-3 py-3 text-gray-600">{{ $movementLabels[$movement->movement_type] ?? $movement->movement_type }}</td>
                                            <td class="px-3 py-3 text-right tabular-nums text-green-700">
                                                @if ((float) $movement->quantity_in > 0)
                                                    +{{ format_quantity_id($movement->quantity_in) }}
                                                @else
                                                    -
                                                @endif
                                            </td>
                                            <td class="px-3 py-3 text-right tabular-nums text-red-700">
                                                @if ((float) $movement->quantity_out > 0)
                                                    -{{ format_quantity_id($movement->quantity_out) }}
                                                @else
                                                    -
                                                @endif
                                            </td>
                                            <td class="px-3 py-3 text-gray-600">{{ format_date_id($movement->movement_date) }}</td>
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
                    <h3 class="text-base font-semibold text-gray-900">Ringkasan</h3>
                    <dl class="mt-4 space-y-3">
                        <div class="flex justify-between gap-3">
                            <dt class="text-sm text-gray-600">Status</dt>
                            <dd>@include('inventory.stock-transfers._status-badge', ['status' => $stockTransfer->status])</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-sm text-gray-600">Lokasi Sumber</dt>
                            <dd class="text-right text-sm text-gray-900">{{ $stockTransfer->sourceInventoryLocation?->name ?? '-' }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-sm text-gray-600">Lokasi Tujuan</dt>
                            <dd class="text-right text-sm text-gray-900">{{ $stockTransfer->destinationInventoryLocation?->name ?? '-' }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-sm text-gray-600">Tanggal Transfer</dt>
                            <dd class="text-sm text-gray-900">{{ format_date_id($stockTransfer->transfer_date) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-sm text-gray-600">Diminta Oleh</dt>
                            <dd class="text-sm text-gray-900">{{ $stockTransfer->requestedBy?->name ?? '-' }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-sm text-gray-600">Dibuat Oleh</dt>
                            <dd class="text-sm text-gray-900">{{ $stockTransfer->createdBy?->name ?? '-' }}</dd>
                        </div>
                        @if ($stockTransfer->approvedBy)
                            <div class="flex justify-between gap-3">
                                <dt class="text-sm text-gray-600">Disetujui Oleh</dt>
                                <dd class="text-sm text-gray-900">{{ $stockTransfer->approvedBy->name }}</dd>
                            </div>
                        @endif
                        @if ($stockTransfer->completed_at)
                            <div class="flex justify-between gap-3">
                                <dt class="text-sm text-gray-600">Selesai Pada</dt>
                                <dd class="text-sm text-gray-900">{{ format_datetime_id($stockTransfer->completed_at) }}</dd>
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
