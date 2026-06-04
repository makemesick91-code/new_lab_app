<x-settings-shell title="Inventory Stock">
    <div class="space-y-6">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <form method="GET" action="{{ route('inventory.stock.index') }}" class="flex flex-wrap items-end gap-2">
                <div>
                    <label class="block text-xs text-gray-500">Location</label>
                    <select name="inventory_location_id" class="mt-1 rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">All locations</option>
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}" @selected(($filters['inventory_location_id'] ?? null) == $location->id)>{{ $location->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Apply</button>
                <a href="{{ route('inventory.stock.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Reset</a>
            </form>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="bg-white shadow-sm sm:rounded-lg p-4">
                <p class="text-xs text-gray-500">Inventory Value</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">{{ number_format((float) $summary['inventory_value'], 2) }}</p>
            </div>
            <div class="bg-white shadow-sm sm:rounded-lg p-4">
                <p class="text-xs text-gray-500">Low Stock</p>
                <p class="mt-1 text-2xl font-semibold text-amber-700">{{ number_format((int) $summary['low_stock_count']) }}</p>
            </div>
            <div class="bg-white shadow-sm sm:rounded-lg p-4">
                <p class="text-xs text-gray-500">Out of Stock</p>
                <p class="mt-1 text-2xl font-semibold text-red-700">{{ number_format((int) $summary['out_of_stock_count']) }}</p>
            </div>
        </div>

        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="px-3 py-2 font-medium">Product</th>
                            <th class="px-3 py-2 font-medium">Location</th>
                            <th class="px-3 py-2 font-medium text-right">Current Stock</th>
                            <th class="px-3 py-2 font-medium text-right">Inventory Value</th>
                            <th class="px-3 py-2 font-medium">Stock</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($stockRows as $row)
                            @php($product = $row->product)
                            @php($current = (float) $row->current_stock)
                            <tr>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $product?->name ?? '-' }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $row->inventoryLocation?->name ?? '-' }}</td>
                                <td class="px-3 py-2 text-right text-gray-700">{{ number_format($current, 2) }}</td>
                                <td class="px-3 py-2 text-right text-gray-700">{{ number_format($current * (float) ($product?->average_cost ?? 0), 2) }}</td>
                                <td class="px-3 py-2">@include('inventory._low-stock-badge', ['current' => $current, 'minimum' => $product?->minimum_stock ?? 0])</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-6 text-center text-gray-400">No stock movements found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-settings-shell>
