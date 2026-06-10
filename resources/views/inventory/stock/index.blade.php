<x-settings-shell title="Stok Persediaan">
    <div class="space-y-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Stok Persediaan</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">Saldo Stok Berbasis Ledger</h2>
            <p class="mt-1 text-sm text-gray-500">Stok dihitung dari pergerakan persediaan per lokasi dan produk.</p>
        </div>

        <x-ui.card padding="p-4">
            <form method="GET" action="{{ route('inventory.stock.index') }}">
                <div class="flex flex-wrap items-end gap-2">
                    <div>
                        <label for="inventory_location_id" class="text-sm font-medium text-gray-700">Lokasi</label>
                        <select id="inventory_location_id" name="inventory_location_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="">Semua lokasi</option>
                            @foreach ($locations as $location)
                                <option value="{{ $location->id }}" @selected(($filters['inventory_location_id'] ?? null) == $location->id)>{{ $location->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-ui.button type="submit" variant="neutral">Terapkan</x-ui.button>
                    <x-ui.button variant="secondary" :href="route('inventory.stock.index')">Atur Ulang</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.card padding="p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Nilai Persediaan</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-gray-900">{{ format_currency_id($summary['inventory_value']) }}</p>
            </x-ui.card>
            <x-ui.card padding="p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Stok Kritis</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-orange-700">{{ format_number_id((int) $alertSummary['critical_stock_count']) }}</p>
            </x-ui.card>
            <x-ui.card padding="p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Stok Rendah</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-amber-700">{{ format_number_id((int) $alertSummary['low_stock_count']) }}</p>
            </x-ui.card>
            <x-ui.card padding="p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Stok Habis</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-rose-700">{{ format_number_id((int) $alertSummary['out_of_stock_count']) }}</p>
            </x-ui.card>
        </div>

        <x-ui.card padding="">
            <div class="border-b border-gray-200 px-4 py-3">
                <h3 class="text-base font-semibold text-gray-900">Saldo per Produk & Lokasi</h3>
                <p class="text-sm text-gray-500">Nilai persediaan = stok saat ini × biaya rata-rata produk.</p>
            </div>

            <x-ui.table class="!border-0 !shadow-none !rounded-none">
                <thead class="bg-gray-50">
                    <tr class="text-left text-gray-500">
                        <th scope="col" class="px-4 py-3 font-medium">Produk</th>
                        <th scope="col" class="px-3 py-3 font-medium">Lokasi</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">Stok Saat Ini</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">Nilai Persediaan</th>
                        <th scope="col" class="px-4 py-3 font-medium">Stok</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($stockRows as $row)
                        @php($product = $row->product)
                        @php($current = (float) $row->current_stock)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-900">{{ $product?->name ?? '-' }}</td>
                            <td class="px-3 py-3 text-gray-600">{{ $row->inventoryLocation?->name ?? '-' }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_quantity_id($current) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_currency_id($current * (float) ($product?->average_cost ?? 0)) }}</td>
                            <td class="px-4 py-3">@include('inventory._low-stock-badge', ['current' => $current, 'minimum' => $product?->minimum_stock ?? 0])</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-12 text-center">
                                <p class="text-sm font-medium text-gray-900">Belum ada pergerakan stok.</p>
                                <p class="mt-1 text-sm text-gray-500">Stok awal atau penerimaan stok akan membuat saldo muncul di sini.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table>
        </x-ui.card>
    </div>
</x-settings-shell>
