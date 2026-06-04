<x-settings-shell title="Inventory Dashboard">
    <div class="space-y-6">
        <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Inventory Core</p>
                    <h1 class="mt-1 text-2xl font-semibold text-gray-900">Stock visibility for the active branch</h1>
                    <p class="mt-2 max-w-3xl text-sm text-gray-600">
                        Stock is calculated from the inventory movement ledger by branch, location, and product. Use this dashboard to spot low stock, inspect movement history, and jump into stock operations safely.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('inventory.stock.index') }}" class="rounded-md bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-900 focus:ring-offset-2">
                        Open Stock
                    </a>
                    <a href="{{ route('inventory.products.index') }}" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                        Products
                    </a>
                </div>
            </div>
        </section>

        <section aria-labelledby="inventory-kpis">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3 id="inventory-kpis" class="text-base font-semibold text-gray-900">Inventory KPI Cards</h3>
                <p class="text-xs text-gray-500">Ledger-derived branch summary</p>
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <x-inventory.kpi-card
                    label="Total Inventory Value"
                    :value="number_format((float) $summary['inventory_value'], 2)"
                    hint="Current branch stock value"
                    tone="primary"
                    :href="route('inventory.stock.index')"
                />
                <x-inventory.kpi-card
                    label="Low Stock Count"
                    :value="number_format((int) $summary['low_stock_count'])"
                    hint="At or below minimum stock"
                    tone="warning"
                    :href="route('inventory.stock.index')"
                />
                <x-inventory.kpi-card
                    label="Out Of Stock Count"
                    :value="number_format((int) $summary['out_of_stock_count'])"
                    hint="Current stock is zero or below"
                    tone="danger"
                    :href="route('inventory.stock.index')"
                />
            </div>
        </section>

        <x-inventory.stock-value-card :summary="$summary" scope-label="Active branch" />

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <x-inventory.dashboard-section
                title="Stock by Location"
                description="Inventory value and quantity grouped by physical inventory location."
                :action-href="route('inventory.stock.index')"
                action-label="View stock"
            >
                @if ($stockByLocation->isEmpty())
                    <div class="rounded-lg border border-dashed border-gray-200 px-4 py-10 text-center">
                        <p class="text-sm font-medium text-gray-900">No stock movements yet.</p>
                        <p class="mt-1 text-sm text-gray-500">Opening stock or receive stock movements will create location summaries.</p>
                        @if ($locations->isNotEmpty())
                            <p class="mt-2 text-xs text-gray-500">{{ number_format($locations->count()) }} active locations are ready for stock operations.</p>
                        @endif
                    </div>
                @else
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($stockByLocation as $location)
                            <x-inventory.location-card :location="$location" />
                        @endforeach
                    </div>
                @endif
            </x-inventory.dashboard-section>

            <x-inventory.low-stock-widget
                :items="$lowStockProducts"
                :href="route('inventory.stock.index')"
            />
        </div>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <x-inventory.movement-timeline
                :movements="$recentMovements"
                :href="route('inventory.stock.index')"
            />

            <x-inventory.dashboard-section title="Top Consumed Materials" description="Future-ready production usage insight." density="compact">
                <div class="rounded-lg border border-dashed border-gray-200 px-4 py-10 text-center">
                    <p class="text-sm font-medium text-gray-900">Coming in a future sprint.</p>
                    <p class="mt-1 text-sm text-gray-500">Production usage is out of scope for Inventory Core, so this widget stays intentionally empty.</p>
                </div>
            </x-inventory.dashboard-section>
        </div>
    </div>
</x-settings-shell>
