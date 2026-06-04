<x-settings-shell title="Inventory Dashboard">
    <div class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="bg-white shadow-sm sm:rounded-lg p-4">
                <p class="text-xs text-gray-500">Total Inventory Value</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">{{ number_format((float) $summary['inventory_value'], 2) }}</p>
            </div>
            <div class="bg-white shadow-sm sm:rounded-lg p-4">
                <p class="text-xs text-gray-500">Low Stock Count</p>
                <p class="mt-1 text-2xl font-semibold text-amber-700">{{ number_format((int) $summary['low_stock_count']) }}</p>
            </div>
            <div class="bg-white shadow-sm sm:rounded-lg p-4">
                <p class="text-xs text-gray-500">Out of Stock Count</p>
                <p class="mt-1 text-2xl font-semibold text-red-700">{{ number_format((int) $summary['out_of_stock_count']) }}</p>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="flex items-center justify-between">
                    <h3 class="font-semibold text-gray-800">Stock by Location</h3>
                    <a href="{{ route('inventory.stock.index') }}" class="text-sm text-indigo-600 hover:text-indigo-500">View stock</a>
                </div>
                <table class="mt-3 min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="px-2 py-1 font-medium">Location</th>
                            <th class="px-2 py-1 font-medium text-right">Qty</th>
                            <th class="px-2 py-1 font-medium text-right">Value</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($stockByLocation as $row)
                            <tr>
                                <td class="px-2 py-2 font-medium text-gray-900">{{ $row->name }}</td>
                                <td class="px-2 py-2 text-right text-gray-700">{{ number_format((float) $row->total_stock, 2) }}</td>
                                <td class="px-2 py-2 text-right text-gray-700">{{ number_format((float) $row->inventory_value, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-2 py-6 text-center text-gray-400">No stock movements yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800">Recent Movements</h3>
                <table class="mt-3 min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="px-2 py-1 font-medium">Date</th>
                            <th class="px-2 py-1 font-medium">Product</th>
                            <th class="px-2 py-1 font-medium">Type</th>
                            <th class="px-2 py-1 font-medium text-right">Qty</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($recentMovements as $movement)
                            <tr>
                                <td class="px-2 py-2 text-gray-600">{{ optional($movement->movement_date)->format('Y-m-d') }}</td>
                                <td class="px-2 py-2 font-medium text-gray-900">{{ $movement->product?->name }}</td>
                                <td class="px-2 py-2 text-gray-600">{{ $movement->movement_type }}</td>
                                <td class="px-2 py-2 text-right text-gray-700">{{ number_format((float) $movement->quantity_in - (float) $movement->quantity_out, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-2 py-6 text-center text-gray-400">No recent movements.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h3 class="font-semibold text-gray-800">Low Stock Products</h3>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="px-3 py-2 font-medium">Code</th>
                            <th class="px-3 py-2 font-medium">Product</th>
                            <th class="px-3 py-2 font-medium text-right">Current</th>
                            <th class="px-3 py-2 font-medium text-right">Minimum</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($lowStockProducts as $product)
                            <tr>
                                <td class="px-3 py-2 text-gray-600">{{ $product->code }}</td>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $product->name }}</td>
                                <td class="px-3 py-2 text-right text-gray-700">{{ number_format((float) $product->current_stock, 2) }}</td>
                                <td class="px-3 py-2 text-right text-gray-700">{{ number_format((float) $product->minimum_stock, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-6 text-center text-gray-400">No low stock products.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-settings-shell>
