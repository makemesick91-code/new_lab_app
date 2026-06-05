@props([
    'summary' => [],
    'scopeLabel' => 'Cabang aktif',
])

<x-inventory.dashboard-section title="Ringkasan Nilai Persediaan" description="Nilai dan risiko stok berbasis ledger untuk cabang saat ini." density="compact">
    <div class="grid gap-4 sm:grid-cols-3">
        <div class="rounded-lg bg-gray-50 p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Lingkup</p>
            <p class="mt-2 text-sm font-medium text-gray-900">{{ $scopeLabel }}</p>
        </div>
        <div class="rounded-lg bg-gray-50 p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Stok Menipis</p>
            <p class="mt-2 text-sm font-semibold tabular-nums text-amber-700">{{ format_number_id((int) data_get($summary, 'low_stock_count', 0)) }} item</p>
        </div>
        <div class="rounded-lg bg-gray-50 p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Stok Habis</p>
            <p class="mt-2 text-sm font-semibold tabular-nums text-rose-700">{{ format_number_id((int) data_get($summary, 'out_of_stock_count', 0)) }} item</p>
        </div>
    </div>
</x-inventory.dashboard-section>
