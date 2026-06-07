@php
    $meta = $meta ?? [];
@endphp

<div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700">
    <p class="font-medium text-gray-900">{{ $meta['analytics_mode_label'] ?? 'Live ledger mode' }}</p>
    <p class="mt-1 text-gray-600">{{ $meta['refresh_status_label'] ?? 'Summary belum di-refresh' }}</p>
</div>
