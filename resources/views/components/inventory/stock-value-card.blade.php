@props([
    'summary' => [],
    'scopeLabel' => 'Cabang aktif',
])

<x-inventory.dashboard-section title="Ringkasan Nilai Persediaan" description="Nilai persediaan berbasis ledger untuk cabang saat ini." density="compact">
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="rounded-lg bg-gray-50 p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Lingkup</p>
            <p class="mt-2 text-sm font-medium text-gray-900">{{ $scopeLabel }}</p>
        </div>
        <div class="rounded-lg bg-teal-50 p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Total Nilai Persediaan</p>
            <p class="mt-2 text-lg font-semibold tabular-nums text-teal-900">{{ format_currency_id((float) data_get($summary, 'inventory_value', 0)) }}</p>
        </div>
    </div>
</x-inventory.dashboard-section>
