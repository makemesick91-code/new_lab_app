<x-settings-shell title="Inventory Products">
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Inventory Products</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">Product Stock Directory</h2>
                <p class="mt-1 text-sm text-gray-500">Branch-total stock is calculated from the movement ledger across active inventory locations.</p>
            </div>
            <a href="{{ route('inventory.products.create') }}" class="inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                Create Product
            </a>
        </div>

        <form method="GET" action="{{ route('inventory.products.index') }}" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_12rem_auto_auto] md:items-end">
                <div>
                    <label for="product-search" class="text-sm font-medium text-gray-700">Search product</label>
                    <input id="product-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Code, name, or category"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                </div>
                <div>
                    <label for="product-status" class="text-sm font-medium text-gray-700">Active status</label>
                    <select id="product-status" name="is_active" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <option value="">All products</option>
                        <option value="1" @selected(($filters['is_active'] ?? '') === true || ($filters['is_active'] ?? '') === '1')>Active only</option>
                        <option value="0" @selected(($filters['is_active'] ?? '') === false || ($filters['is_active'] ?? '') === '0')>Inactive only</option>
                    </select>
                </div>
                <button class="inline-flex justify-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-700 focus:ring-offset-2">
                    Apply
                </button>
                <a href="{{ route('inventory.products.index') }}" class="inline-flex justify-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                    Reset
                </a>
            </div>
        </form>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Products</h3>
                    <p class="text-sm text-gray-500">{{ number_format($products->total()) }} products in the active branch scope.</p>
                </div>
                <span class="inline-flex rounded-full bg-sky-50 px-3 py-1 text-xs font-medium text-sky-700">Branch Total Stock</span>
            </div>

            <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-gray-500">
                            <th scope="col" class="px-4 py-3 font-medium">Product</th>
                            <th scope="col" class="px-3 py-3 font-medium">Category / Unit</th>
                            <th scope="col" class="px-3 py-3 text-right font-medium">Minimum</th>
                            <th scope="col" class="px-3 py-3 text-right font-medium">Current Stock - Branch Total</th>
                            <th scope="col" class="px-3 py-3 font-medium">Stock Status</th>
                            <th scope="col" class="px-3 py-3 font-medium">Active Status</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Safe Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($products as $product)
                            @php
                                $currentStock = (float) ($currentStocks[$product->id] ?? 0);
                                $minimumStock = (float) $product->minimum_stock;
                                $isOut = $currentStock <= 0;
                                $isLow = ! $isOut && $currentStock <= $minimumStock;
                            @endphp
                            <tr @class([
                                'hover:bg-gray-50',
                                'bg-gray-50/60 text-gray-500' => ! $product->is_active,
                                'bg-rose-50/40' => $product->is_active && $isOut,
                                'bg-amber-50/40' => $product->is_active && $isLow,
                            ])>
                                <td class="px-4 py-3">
                                    <div class="flex items-start gap-3">
                                        <div @class([
                                            'mt-1 h-10 w-1 rounded-full',
                                            'bg-rose-400' => $isOut,
                                            'bg-amber-400' => $isLow,
                                            'bg-emerald-400' => ! $isOut && ! $isLow,
                                        ])></div>
                                        <div class="min-w-0">
                                            <p class="font-semibold text-gray-900">{{ $product->name }}</p>
                                            <p class="mt-0.5 text-xs text-gray-500">{{ $product->code }}</p>
                                            @if (! $product->is_active)
                                                <p class="mt-1 text-xs font-medium text-gray-500">Stock operations disabled for inactive product.</p>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-3 text-gray-600">
                                    <p>{{ $product->category?->name ?? '-' }}</p>
                                    <p class="mt-0.5 text-xs text-gray-500">{{ $product->unit?->symbol ?? '-' }}</p>
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ number_format($minimumStock, 2) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums">
                                    <span @class([
                                        'font-semibold',
                                        'text-rose-700' => $isOut,
                                        'text-amber-700' => $isLow,
                                        'text-gray-900' => ! $isOut && ! $isLow,
                                    ])>{{ number_format($currentStock, 2) }}</span>
                                </td>
                                <td class="px-3 py-3">@include('inventory._low-stock-badge', ['current' => $currentStock, 'minimum' => $minimumStock])</td>
                                <td class="px-3 py-3">@include('inventory._status-badge', ['active' => $product->is_active])</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                        <a href="{{ route('inventory.products.show', $product) }}" class="font-medium text-gray-700 hover:text-gray-950">View</a>
                                        <a href="{{ route('inventory.products.stock-card', $product) }}" class="font-medium text-teal-700 hover:text-teal-600">Stock Card</a>
                                        @if ($product->is_active)
                                            <a href="{{ route('inventory.products.receive-stock.create', $product) }}" class="font-medium text-emerald-700 hover:text-emerald-600">Receive</a>
                                        @endif
                                        <a href="{{ route('inventory.products.edit', $product) }}" class="font-medium text-gray-500 hover:text-gray-900">Edit</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-12">
                                    <div class="mx-auto max-w-sm text-center">
                                        <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                                            <span class="text-lg font-semibold">0</span>
                                        </div>
                                        <p class="mt-3 text-sm font-medium text-gray-900">No products found.</p>
                                        <p class="mt-1 text-sm text-gray-500">Create materials before receiving stock or recording opening balances.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-gray-100 md:hidden">
                @forelse ($products as $product)
                    @php
                        $currentStock = (float) ($currentStocks[$product->id] ?? 0);
                        $minimumStock = (float) $product->minimum_stock;
                        $isOut = $currentStock <= 0;
                        $isLow = ! $isOut && $currentStock <= $minimumStock;
                    @endphp
                    <article @class([
                        'p-4',
                        'bg-gray-50/60' => ! $product->is_active,
                        'bg-rose-50/40' => $product->is_active && $isOut,
                        'bg-amber-50/40' => $product->is_active && $isLow,
                    ])>
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $product->code }}</p>
                                <h3 class="mt-1 text-base font-semibold text-gray-900">{{ $product->name }}</h3>
                                <p class="mt-1 text-sm text-gray-500">{{ $product->category?->name ?? '-' }} · {{ $product->unit?->symbol ?? '-' }}</p>
                            </div>
                            <div class="shrink-0">@include('inventory._low-stock-badge', ['current' => $currentStock, 'minimum' => $minimumStock])</div>
                        </div>
                        <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div class="rounded-lg bg-white p-3 ring-1 ring-gray-100">
                                <p class="text-xs text-gray-500">Branch Total</p>
                                <p class="mt-1 font-semibold tabular-nums text-gray-900">{{ number_format($currentStock, 2) }}</p>
                            </div>
                            <div class="rounded-lg bg-white p-3 ring-1 ring-gray-100">
                                <p class="text-xs text-gray-500">Minimum</p>
                                <p class="mt-1 font-semibold tabular-nums text-gray-900">{{ number_format($minimumStock, 2) }}</p>
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            @include('inventory._status-badge', ['active' => $product->is_active])
                            @if (! $product->is_active)
                                <span class="text-xs font-medium text-gray-500">Stock operations disabled.</span>
                            @endif
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <a href="{{ route('inventory.products.show', $product) }}" class="rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-700">View</a>
                            <a href="{{ route('inventory.products.stock-card', $product) }}" class="rounded-lg border border-teal-200 px-3 py-2 text-sm font-medium text-teal-700">Stock Card</a>
                            @if ($product->is_active)
                                <a href="{{ route('inventory.products.receive-stock.create', $product) }}" class="rounded-lg border border-emerald-200 px-3 py-2 text-sm font-medium text-emerald-700">Receive</a>
                            @endif
                        </div>
                    </article>
                @empty
                    <div class="px-4 py-10 text-center">
                        <p class="text-sm font-medium text-gray-900">No products found.</p>
                        <p class="mt-1 text-sm text-gray-500">Create materials before receiving stock or recording opening balances.</p>
                    </div>
                @endforelse
            </div>

            <div class="border-t border-gray-200 px-4 py-3">{{ $products->links() }}</div>
        </section>
    </div>
</x-settings-shell>
