<x-settings-shell title="Inventory Products">
    <div class="bg-white shadow-sm sm:rounded-lg">
        <div class="p-6 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <form method="GET" action="{{ route('inventory.products.index') }}" class="flex flex-wrap items-center gap-2">
                    <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Search product"
                           class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Search</button>
                    <a href="{{ route('inventory.products.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Reset</a>
                </form>
                <a href="{{ route('inventory.products.create') }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Create Product</a>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="px-3 py-2 font-medium">Code</th>
                            <th class="px-3 py-2 font-medium">Product</th>
                            <th class="px-3 py-2 font-medium">Category</th>
                            <th class="px-3 py-2 font-medium">Unit</th>
                            <th class="px-3 py-2 font-medium text-right">Minimum</th>
                            <th class="px-3 py-2 font-medium text-right">Current</th>
                            <th class="px-3 py-2 font-medium">Stock</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 font-medium text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($products as $product)
                            @php($currentStock = (float) ($currentStocks[$product->id] ?? 0))
                            <tr>
                                <td class="px-3 py-2 text-gray-600">{{ $product->code }}</td>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $product->name }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $product->category?->name ?? '-' }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $product->unit?->symbol ?? '-' }}</td>
                                <td class="px-3 py-2 text-right text-gray-700">{{ number_format((float) $product->minimum_stock, 2) }}</td>
                                <td class="px-3 py-2 text-right text-gray-700">{{ number_format($currentStock, 2) }}</td>
                                <td class="px-3 py-2">@include('inventory._low-stock-badge', ['current' => $currentStock, 'minimum' => $product->minimum_stock])</td>
                                <td class="px-3 py-2">@include('inventory._status-badge', ['active' => $product->is_active])</td>
                                <td class="px-3 py-2">
                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                        <a href="{{ route('inventory.products.show', $product) }}" class="text-gray-600 hover:text-gray-900">View</a>
                                        <a href="{{ route('inventory.products.stock-card', $product) }}" class="text-indigo-600 hover:text-indigo-500">Card</a>
                                        @if ($product->is_active)
                                            <a href="{{ route('inventory.products.opening-stock.create', $product) }}" class="text-green-600 hover:text-green-500">Opening</a>
                                            <a href="{{ route('inventory.products.receive-stock.create', $product) }}" class="text-emerald-600 hover:text-emerald-500">Receive</a>
                                        @endif
                                        <a href="{{ route('inventory.products.edit', $product) }}" class="text-indigo-600 hover:text-indigo-500">Edit</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="px-3 py-6 text-center text-gray-400">No products found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $products->links() }}</div>
        </div>
    </div>
</x-settings-shell>
