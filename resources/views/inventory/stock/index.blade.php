<x-settings-shell title="Stok Persediaan">
    <div class="space-y-6">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <form method="GET" action="{{ route('inventory.stock.index') }}" class="flex flex-wrap items-end gap-2">
                <div>
                    <label class="block text-xs text-gray-500">Lokasi</label>
                    <select name="inventory_location_id" class="mt-1 rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Semua lokasi</option>
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}" @selected(($filters['inventory_location_id'] ?? null) == $location->id)>{{ $location->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Terapkan</button>
                <a href="{{ route('inventory.stock.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Atur Ulang</a>
            </form>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="bg-white shadow-sm sm:rounded-lg p-4">
                <p class="text-xs text-gray-500">Nilai Persediaan</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">{{ format_currency_id($summary['inventory_value']) }}</p>
            </div>
            <div class="bg-white shadow-sm sm:rounded-lg p-4">
                <p class="text-xs text-gray-500">Stok Kritis</p>
                <p class="mt-1 text-2xl font-semibold text-orange-700">{{ format_number_id((int) $alertSummary['critical_stock_count']) }}</p>
            </div>
            <div class="bg-white shadow-sm sm:rounded-lg p-4">
                <p class="text-xs text-gray-500">Stok Rendah</p>
                <p class="mt-1 text-2xl font-semibold text-amber-700">{{ format_number_id((int) $alertSummary['low_stock_count']) }}</p>
            </div>
            <div class="bg-white shadow-sm sm:rounded-lg p-4">
                <p class="text-xs text-gray-500">Stok Habis</p>
                <p class="mt-1 text-2xl font-semibold text-red-700">{{ format_number_id((int) $alertSummary['out_of_stock_count']) }}</p>
            </div>
        </div>

        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="px-3 py-2 font-medium">Produk</th>
                            <th class="px-3 py-2 font-medium">Lokasi</th>
                            <th class="px-3 py-2 font-medium text-right">Stok Saat Ini</th>
                            <th class="px-3 py-2 font-medium text-right">Nilai Persediaan</th>
                            <th class="px-3 py-2 font-medium">Stok</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($stockRows as $row)
                            @php($product = $row->product)
                            @php($current = (float) $row->current_stock)
                            <tr>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $product?->name ?? '-' }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $row->inventoryLocation?->name ?? '-' }}</td>
                                <td class="px-3 py-2 text-right text-gray-700">{{ format_quantity_id($current) }}</td>
                                <td class="px-3 py-2 text-right text-gray-700">{{ format_currency_id($current * (float) ($product?->average_cost ?? 0)) }}</td>
                                <td class="px-3 py-2">@include('inventory._low-stock-badge', ['current' => $current, 'minimum' => $product?->minimum_stock ?? 0])</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-6 text-center text-gray-400">Belum ada pergerakan stok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-settings-shell>
