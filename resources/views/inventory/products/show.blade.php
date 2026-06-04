<x-settings-shell title="Product Detail">
    <div class="space-y-6">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-gray-500">{{ $product->code }}</p>
                    <h3 class="text-xl font-semibold text-gray-900">{{ $product->name }}</h3>
                </div>
                @include('inventory._status-badge', ['active' => $product->is_active])
            </div>
            <dl class="mt-5 grid gap-4 sm:grid-cols-3 text-sm">
                <div><dt class="text-gray-500">Category</dt><dd class="font-medium text-gray-900">{{ $product->category?->name ?? '-' }}</dd></div>
                <div><dt class="text-gray-500">Unit</dt><dd class="font-medium text-gray-900">{{ $product->unit?->symbol ?? '-' }}</dd></div>
                <div><dt class="text-gray-500">Current Stock</dt><dd class="font-medium text-gray-900">{{ number_format((float) $currentStock, 2) }}</dd></div>
                <div><dt class="text-gray-500">Minimum Stock</dt><dd class="font-medium text-gray-900">{{ number_format((float) $product->minimum_stock, 2) }}</dd></div>
                <div><dt class="text-gray-500">Average Cost</dt><dd class="font-medium text-gray-900">{{ number_format((float) $product->average_cost, 2) }}</dd></div>
                <div><dt class="text-gray-500">Stock Status</dt><dd>@include('inventory._low-stock-badge', ['current' => $currentStock, 'minimum' => $product->minimum_stock])</dd></div>
            </dl>
            <p class="mt-4 text-sm text-gray-600">{{ $product->description }}</p>
        </div>

        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h3 class="font-semibold text-gray-800">Stock Actions</h3>
            <div class="mt-3 flex flex-wrap gap-2">
                <a href="{{ route('inventory.products.stock-card', $product) }}" class="rounded-md bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200">Stock Card</a>
                <a href="{{ route('inventory.products.opening-stock.create', $product) }}" class="rounded-md bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-500">Opening Stock</a>
                <a href="{{ route('inventory.products.receive-stock.create', $product) }}" class="rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-500">Receive Stock</a>
                <a href="{{ route('inventory.products.adjust-in.create', $product) }}" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">Adjust In</a>
                <a href="{{ route('inventory.products.adjust-out.create', $product) }}" class="rounded-md bg-amber-600 px-3 py-2 text-sm font-medium text-white hover:bg-amber-500">Adjust Out</a>
                <a href="{{ route('inventory.products.edit', $product) }}" class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Edit Product</a>
            </div>
        </div>
    </div>
</x-settings-shell>
