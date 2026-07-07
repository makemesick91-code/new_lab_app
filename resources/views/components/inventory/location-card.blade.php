@props([
    'location',
])

@php
    $qty = (float) data_get($location, 'total_stock', 0);
    $value = (float) data_get($location, 'inventory_value', 0);
    $href = route('inventory.stock.index', ['inventory_location_id' => data_get($location, 'id')]);
@endphp

<a href="{{ $href }}" class="block rounded-lg border border-gray-200 bg-white p-4 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
    <div class="flex items-start justify-between gap-3">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ data_get($location, 'code') ?: 'Lokasi' }}</p>
            <h4 class="mt-1 text-sm font-semibold text-gray-900">{{ data_get($location, 'name', '-') }}</h4>
        </div>
        <span class="rounded-full bg-info-50 px-2 py-0.5 text-xs font-medium text-info-700">Lokasi</span>
    </div>
    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
        <div>
            <dt class="text-xs text-gray-500">Jumlah</dt>
            <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ format_quantity_id($qty) }}</dd>
        </div>
        <div>
            <dt class="text-xs text-gray-500">Nilai</dt>
            <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ format_currency_id($value) }}</dd>
        </div>
    </dl>
</a>
