@props([
    'item' => [],
])

@php
    $severity = data_get($item, 'severity', 'neutral');
    $badgeClasses = [
        'danger' => 'bg-rose-50 text-rose-700',
        'critical' => 'bg-rose-50 text-rose-700',
        'warning' => 'bg-amber-50 text-amber-700',
        'success' => 'bg-emerald-50 text-emerald-700',
        'info' => 'bg-sky-50 text-sky-700',
        'neutral' => 'bg-gray-100 text-gray-600',
    ][$severity] ?? 'bg-gray-100 text-gray-600';
@endphp

<article class="rounded-lg border border-gray-200 bg-white p-3 shadow-sm">
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <p class="truncate text-sm font-semibold text-gray-900">{{ data_get($item, 'identifier', 'Tanpa item') }}</p>
            <p class="mt-1 truncate text-sm text-gray-600">{{ data_get($item, 'title', 'Tanpa judul') }}</p>
        </div>
        <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium {{ $badgeClasses }}">
            {{ data_get($item, 'status', 'Terbuka') }}
        </span>
    </div>

    @if (data_get($item, 'subtitle'))
        <p class="mt-2 text-xs text-gray-500">{{ data_get($item, 'subtitle') }}</p>
    @endif

    <div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-gray-500">
        @if (data_get($item, 'priority'))
            <span>{{ data_get($item, 'priority') }}</span>
        @endif
        @if (data_get($item, 'dueDate'))
            <span>Jatuh tempo {{ data_get($item, 'dueDate') }}</span>
        @endif
        @if (data_get($item, 'ageLabel'))
            <span>{{ data_get($item, 'ageLabel') }}</span>
        @endif
    </div>

    @if (data_get($item, 'href'))
        <a href="{{ data_get($item, 'href') }}" class="mt-3 inline-flex text-xs font-semibold text-teal-700 hover:text-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
            {{ data_get($item, 'actionLabel', 'Buka') }}
        </a>
    @endif
</article>
