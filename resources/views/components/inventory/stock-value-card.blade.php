@props([
    'summary' => [],
    'alertSummary' => [],
    'scopeLabel' => 'Cabang aktif',
])

@php
    $alertSummary = array_merge([
        'out_of_stock_count' => 0,
        'critical_stock_count' => 0,
        'low_stock_count' => 0,
        'batch_expired_count' => 0,
        'batch_expiring_soon_count' => 0,
    ], $alertSummary);
@endphp

<x-inventory.dashboard-section title="Ringkasan Nilai Persediaan" description="Nilai persediaan dan peringatan stok berbasis ledger untuk cabang saat ini." density="compact">
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

    <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <div class="rounded-lg border border-rose-100 bg-rose-50 p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-rose-700">Stok Habis</p>
            <p class="mt-1 text-xl font-semibold tabular-nums text-rose-900">{{ format_number_id((int) $alertSummary['out_of_stock_count']) }}</p>
        </div>
        <div class="rounded-lg border border-orange-100 bg-orange-50 p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-orange-700">Stok Kritis</p>
            <p class="mt-1 text-xl font-semibold tabular-nums text-orange-900">{{ format_number_id((int) $alertSummary['critical_stock_count']) }}</p>
        </div>
        <div class="rounded-lg border border-amber-100 bg-amber-50 p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Stok Rendah</p>
            <p class="mt-1 text-xl font-semibold tabular-nums text-amber-900">{{ format_number_id((int) $alertSummary['low_stock_count']) }}</p>
        </div>
        <div class="rounded-lg border border-rose-100 bg-rose-50 p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-rose-700">Batch Kedaluwarsa</p>
            <p class="mt-1 text-xl font-semibold tabular-nums text-rose-900">{{ format_number_id((int) $alertSummary['batch_expired_count']) }}</p>
        </div>
        <div class="rounded-lg border border-amber-100 bg-amber-50 p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Segera Kedaluwarsa</p>
            <p class="mt-1 text-xl font-semibold tabular-nums text-amber-900">{{ format_number_id((int) $alertSummary['batch_expiring_soon_count']) }}</p>
        </div>
    </div>
</x-inventory.dashboard-section>
