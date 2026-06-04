@props([
    'summary' => [],
    'scopeLabel' => 'Active branch',
])

<x-inventory.dashboard-section title="Inventory Value Summary" description="Ledger-derived stock value and risk for the current branch." density="compact">
    <div class="grid gap-4 sm:grid-cols-3">
        <div class="rounded-lg bg-gray-50 p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Scope</p>
            <p class="mt-2 text-sm font-medium text-gray-900">{{ $scopeLabel }}</p>
        </div>
        <div class="rounded-lg bg-gray-50 p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Low Stock</p>
            <p class="mt-2 text-sm font-semibold tabular-nums text-amber-700">{{ number_format((int) data_get($summary, 'low_stock_count', 0)) }} items</p>
        </div>
        <div class="rounded-lg bg-gray-50 p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Out of Stock</p>
            <p class="mt-2 text-sm font-semibold tabular-nums text-rose-700">{{ number_format((int) data_get($summary, 'out_of_stock_count', 0)) }} items</p>
        </div>
    </div>
</x-inventory.dashboard-section>
