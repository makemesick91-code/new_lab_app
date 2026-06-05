<x-settings-shell title="Tinjau Selisih - {{ $stockOpname->opname_number }}">
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Tinjau Stok Opname</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">{{ $stockOpname->opname_number }}</h2>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $stockOpname->inventoryLocation?->name ?? '-' }} · {{ format_date_id($stockOpname->opname_date) }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('inventory.stock-opnames.show', $stockOpname) }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Kembali ke Detail
                </a>
                @if ($stockOpname->status === \App\Modules\Inventory\Models\StockOpname::STATUS_COUNTING)
                    <form method="POST" action="{{ route('inventory.stock-opnames.finalize', $stockOpname) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                            Finalisasi Opname
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-4">
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase text-gray-500">Total Produk</p>
                <p class="mt-1 text-2xl font-bold text-gray-900">{{ $totalProducts }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase text-gray-500">Total Selisih</p>
                <p class="mt-1 text-2xl font-bold text-gray-900">{{ $totalVariances }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase text-gray-500">Selisih Lebih</p>
                <p class="mt-1 text-2xl font-bold text-green-600">{{ $overages }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-medium uppercase text-gray-500">Selisih Kurang</p>
                <p class="mt-1 text-2xl font-bold text-red-600">{{ $shortages }}</p>
            </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-200 px-4 py-3">
                <h3 class="text-base font-semibold text-gray-900">Detail Selisih</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-gray-500">
                            <th scope="col" class="px-4 py-3 font-medium">Produk</th>
                            <th scope="col" class="px-3 py-3 font-medium">SKU</th>
                            <th scope="col" class="px-3 py-3 font-medium">Satuan</th>
                            <th scope="col" class="px-3 py-3 text-right font-medium">Qty Sistem</th>
                            <th scope="col" class="px-3 py-3 text-right font-medium">Qty Terhitung</th>
                            <th scope="col" class="px-3 py-3 text-right font-medium">Selisih</th>
                            <th scope="col" class="px-4 py-3 font-medium">Tipe Selisih</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($stockOpname->items as $item)
                            @php
                                $variance = (float)$item->variance_quantity;
                                $isOver = $variance > 0;
                                $isShort = $variance < 0;
                                $isZero = $variance === 0;
                            @endphp
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-gray-900">{{ $item->product?->name ?? '-' }}</p>
                                </td>
                                <td class="px-3 py-3 text-gray-600">{{ $item->product?->code ?? '-' }}</td>
                                <td class="px-3 py-3 text-gray-600">{{ $item->product?->unit?->symbol ?? '-' }}</td>
                                <td class="px-3 py-3 text-right tabular-nums text-gray-600">{{ format_quantity_id($item->system_quantity) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums text-gray-600">{{ format_quantity_id($item->counted_quantity) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">
                                    <span @class([
                                        'font-semibold',
                                        'text-green-600' => $isOver,
                                        'text-red-600' => $isShort,
                                        'text-gray-500' => $isZero,
                                    ])>
                                        {{ $isOver ? '+' : '' }}{{ format_quantity_id($variance) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span @class([
                                        'inline-flex rounded-full px-3 py-1 text-xs font-medium',
                                        'bg-green-100 text-green-800' => $isOver,
                                        'bg-red-100 text-red-800' => $isShort,
                                        'bg-gray-100 text-gray-800' => $isZero,
                                    ])>
                                        @if ($isOver)
                                            Lebih
                                        @elseif ($isShort)
                                            Kurang
                                        @else
                                            Sesuai
                                        @endif
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-12">
                                    <div class="mx-auto max-w-sm text-center">
                                        <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                                            <span class="text-lg font-semibold">0</span>
                                        </div>
                                        <p class="mt-3 text-sm font-medium text-gray-900">Tidak ada item.</p>
                                        <p class="mt-1 text-sm text-gray-500">Tidak ada produk pada stok opname ini.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-settings-shell>
