@php
    $currentStock = (float) $currentStock;
    $minimumStock = (float) $product->minimum_stock;
    $averageCost = (float) $product->average_cost;
    $inventoryValue = $currentStock * $averageCost;
    $isOut = $currentStock <= 0;
    $isLow = ! $isOut && $currentStock <= $minimumStock;
@endphp

<x-settings-shell title="Product Detail">
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Product Summary Card</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">{{ $product->name }}</h2>
                <p class="mt-1 text-sm text-gray-500">Code {{ $product->code }} · stock shown as active branch total from ledger movements.</p>
            </div>
            <a href="{{ route('inventory.products.index') }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                Back to Products
            </a>
        </div>

        @if (! $product->is_active)
            <div role="status" class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
                <p class="font-semibold text-gray-900">This product is inactive.</p>
                <p class="mt-1">Stock operations are disabled. You can still review the stock card and historical ledger movements.</p>
            </div>
        @elseif ($isOut)
            <div role="alert" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
                <p class="font-semibold">This product is out of stock across the active branch.</p>
                <p class="mt-1">Receive stock into an explicit Inventory Location before using this material operationally.</p>
            </div>
        @elseif ($isLow)
            <div role="status" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                <p class="font-semibold">This product is below minimum stock.</p>
                <p class="mt-1">Review location balances in the stock card before receiving or adjusting stock.</p>
            </div>
        @endif

        <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        @include('inventory._status-badge', ['active' => $product->is_active])
                        @include('inventory._low-stock-badge', ['current' => $currentStock, 'minimum' => $minimumStock])
                    </div>
                    <h3 class="mt-3 text-lg font-semibold text-gray-900">{{ $product->name }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ $product->description ?: 'No product description recorded.' }}</p>
                </div>
                <div class="rounded-lg bg-teal-50 px-4 py-3 text-right">
                    <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Current Stock - Branch Total</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-teal-900">{{ number_format($currentStock, 2) }}</p>
                    <p class="mt-1 text-xs text-teal-700">{{ $product->unit?->symbol ?? 'unit' }}</p>
                </div>
            </div>

            <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Category</dt>
                    <dd class="mt-1 font-semibold text-gray-900">{{ $product->category?->name ?? '-' }}</dd>
                </div>
                <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Unit</dt>
                    <dd class="mt-1 font-semibold text-gray-900">{{ $product->unit?->symbol ?? '-' }}</dd>
                </div>
                <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Minimum Stock</dt>
                    <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ number_format($minimumStock, 2) }}</dd>
                </div>
                <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Average Cost</dt>
                    <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ number_format($averageCost, 2) }}</dd>
                </div>
            </dl>
        </section>

        <div class="grid gap-6 lg:grid-cols-3">
            <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm lg:col-span-2">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">Branch / Location Stock Clarity</h3>
                        <p class="mt-1 text-sm text-gray-500">This page shows branch-total stock. Use the stock card to investigate movement history and filter by Inventory Location.</p>
                    </div>
                    <a href="{{ route('inventory.products.stock-card', $product) }}" class="text-sm font-semibold text-teal-700 hover:text-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                        Open Stock Card
                    </a>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg border border-gray-200 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Ledger Scope</p>
                        <p class="mt-2 text-sm font-medium text-gray-900">Active branch</p>
                        <p class="mt-1 text-xs text-gray-500">Aggregates all inventory locations for this product.</p>
                    </div>
                    <div class="rounded-lg border border-gray-200 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Inventory Value</p>
                        <p class="mt-2 text-lg font-semibold tabular-nums text-gray-900">{{ number_format($inventoryValue, 2) }}</p>
                        <p class="mt-1 text-xs text-gray-500">Current stock × average cost.</p>
                    </div>
                    <div class="rounded-lg border border-gray-200 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Location Detail</p>
                        <p class="mt-2 text-sm font-medium text-gray-900">Filter in Stock Card</p>
                        <p class="mt-1 text-xs text-gray-500">Per-location balances remain ledger-derived.</p>
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <h3 class="text-base font-semibold text-gray-900">Stock Actions</h3>
                <p class="mt-1 text-sm text-gray-500">Every stock operation requires a selected Inventory Location.</p>
                <div class="mt-4 grid gap-2">
                    <a href="{{ route('inventory.products.stock-card', $product) }}" class="inline-flex justify-center rounded-lg border border-gray-200 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Stock Card</a>
                    @if ($product->is_active)
                        <a href="{{ route('inventory.products.receive-stock.create', $product) }}" class="inline-flex justify-center rounded-lg bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-600">Receive Stock</a>
                        <a href="{{ route('inventory.products.opening-stock.create', $product) }}" class="inline-flex justify-center rounded-lg border border-green-200 px-3 py-2 text-sm font-semibold text-green-700 hover:bg-green-50">Opening Stock</a>
                        <a href="{{ route('inventory.products.adjust-in.create', $product) }}" class="inline-flex justify-center rounded-lg border border-teal-200 px-3 py-2 text-sm font-semibold text-teal-700 hover:bg-teal-50">Adjust In</a>
                        <a href="{{ route('inventory.products.adjust-out.create', $product) }}" class="inline-flex justify-center rounded-lg border border-amber-200 px-3 py-2 text-sm font-semibold text-amber-700 hover:bg-amber-50">Adjust Out</a>
                    @else
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm text-gray-600">
                            Stock operations disabled for inactive product.
                        </div>
                    @endif
                    <a href="{{ route('inventory.products.edit', $product) }}" class="inline-flex justify-center rounded-lg bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-gray-800">Edit Product</a>
                </div>
            </section>
        </div>
    </div>
</x-settings-shell>
