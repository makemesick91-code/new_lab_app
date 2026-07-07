@props([
    'summary' => [],
    'href' => null,
])

@php
    $summary = array_merge([
        'out_of_stock_count' => 0,
        'critical_stock_count' => 0,
        'low_stock_count' => 0,
        'batch_expired_count' => 0,
        'batch_expiring_soon_count' => 0,
        'total_count' => 0,
    ], $summary);
@endphp

<x-inventory.dashboard-section
    title="Ringkasan Peringatan"
    description="Peringatan stok dan batch berbasis ledger untuk cabang aktif."
    :action-href="$href"
    action-label="Lihat semua peringatan"
>
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <div class="rounded-lg border border-danger-100 bg-danger-50 p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-danger-700">Stok Habis</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-danger-700">{{ format_number_id((int) $summary['out_of_stock_count']) }}</p>
        </div>
        <div class="rounded-lg border border-orange-100 bg-orange-50 p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-orange-700">Stok Kritis</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-orange-900">{{ format_number_id((int) $summary['critical_stock_count']) }}</p>
        </div>
        <div class="rounded-lg border border-warning-100 bg-warning-50 p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-warning-700">Stok Rendah</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-warning-700">{{ format_number_id((int) $summary['low_stock_count']) }}</p>
        </div>
        <div class="rounded-lg border border-danger-100 bg-danger-50 p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-danger-700">Batch Kedaluwarsa</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-danger-700">{{ format_number_id((int) $summary['batch_expired_count']) }}</p>
        </div>
        <div class="rounded-lg border border-warning-100 bg-warning-50 p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-warning-700">Akan Kedaluwarsa</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-warning-700">{{ format_number_id((int) $summary['batch_expiring_soon_count']) }}</p>
        </div>
    </div>
</x-inventory.dashboard-section>
