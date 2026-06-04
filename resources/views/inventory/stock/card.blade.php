<x-settings-shell title="Stock Card">
    <div class="space-y-6">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-gray-500">{{ $product->code }} - {{ $product->unit?->symbol ?? '-' }}</p>
                    <h3 class="text-xl font-semibold text-gray-900">{{ $product->name }}</h3>
                </div>
                <div class="text-right">
                    <p class="text-xs text-gray-500">Current Stock</p>
                    <p class="text-2xl font-semibold text-gray-900">{{ number_format((float) $currentStock, 2) }}</p>
                </div>
            </div>

            <form method="GET" action="{{ route('inventory.products.stock-card', $product) }}" class="mt-5 flex flex-wrap items-end gap-2">
                <div>
                    <label class="block text-xs text-gray-500">Location</label>
                    <select name="inventory_location_id" class="mt-1 rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">All locations</option>
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}" @selected(($filters['inventory_location_id'] ?? null) == $location->id)>{{ $location->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500">From</label>
                    <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="mt-1 rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-xs text-gray-500">To</label>
                    <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="mt-1 rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Apply</button>
                <a href="{{ route('inventory.products.stock-card', $product) }}" class="text-sm text-gray-500 hover:text-gray-700">Reset</a>
            </form>
        </div>

        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="px-3 py-2 font-medium">Movement Date</th>
                            <th class="px-3 py-2 font-medium">Movement Type</th>
                            <th class="px-3 py-2 font-medium">Location</th>
                            <th class="px-3 py-2 font-medium">Reference</th>
                            <th class="px-3 py-2 font-medium text-right">Quantity In</th>
                            <th class="px-3 py-2 font-medium text-right">Quantity Out</th>
                            <th class="px-3 py-2 font-medium text-right">Running Balance</th>
                            <th class="px-3 py-2 font-medium text-right">Unit Cost</th>
                            <th class="px-3 py-2 font-medium">Notes</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($stockCard as $movement)
                            <tr>
                                <td class="px-3 py-2 text-gray-600">{{ optional($movement->movement_date)->format('Y-m-d') }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $movement->movement_type }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $movement->inventoryLocation?->name ?? '-' }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $movement->reference_type ? $movement->reference_type.' #'.$movement->reference_id : '-' }}</td>
                                <td class="px-3 py-2 text-right text-gray-700">{{ number_format((float) $movement->quantity_in, 2) }}</td>
                                <td class="px-3 py-2 text-right text-gray-700">{{ number_format((float) $movement->quantity_out, 2) }}</td>
                                <td class="px-3 py-2 text-right font-medium text-gray-900">{{ number_format((float) $movement->running_balance, 2) }}</td>
                                <td class="px-3 py-2 text-right text-gray-700">{{ number_format((float) $movement->unit_cost, 2) }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $movement->notes ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="px-3 py-6 text-center text-gray-400">No stock card movements found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-settings-shell>
