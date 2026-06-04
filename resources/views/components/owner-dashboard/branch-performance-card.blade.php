@props([
    'branch',
])

@php
    $health = data_get($branch, 'health', 'No data');
    $severity = data_get($branch, 'severity', 'neutral');
    $badgeClasses = [
        'critical' => 'bg-rose-50 text-rose-700',
        'danger' => 'bg-rose-50 text-rose-700',
        'warning' => 'bg-amber-50 text-amber-700',
        'success' => 'bg-emerald-50 text-emerald-700',
        'neutral' => 'bg-gray-100 text-gray-600',
    ][$severity] ?? 'bg-gray-100 text-gray-600';
@endphp

<article {{ $attributes->merge(['class' => 'rounded-lg border border-gray-200 bg-white p-4 shadow-sm']) }}>
    <div class="flex items-start justify-between gap-3">
        <div>
            <h4 class="text-sm font-semibold text-gray-900">{{ data_get($branch, 'name', 'Branch') }}</h4>
            <p class="mt-1 text-xs text-gray-500">{{ data_get($branch, 'description', 'Branch performance summary') }}</p>
        </div>
        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $badgeClasses }}">{{ $health }}</span>
    </div>

    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
        <div>
            <dt class="text-xs text-gray-500">Revenue</dt>
            <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ data_get($branch, 'revenue', 'Rp 0.00') }}</dd>
        </div>
        <div>
            <dt class="text-xs text-gray-500">Orders</dt>
            <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ data_get($branch, 'orders', 0) }}</dd>
        </div>
        <div>
            <dt class="text-xs text-gray-500">Completion Time</dt>
            <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ data_get($branch, 'completionTime', 'No data') }}</dd>
        </div>
        <div>
            <dt class="text-xs text-gray-500">Outstanding</dt>
            <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ data_get($branch, 'outstanding', 'Rp 0.00') }}</dd>
        </div>
    </dl>

    @if (data_get($branch, 'href'))
        <a href="{{ data_get($branch, 'href') }}" class="mt-4 inline-flex text-xs font-semibold text-teal-700 hover:text-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
            View branch details
        </a>
    @endif
</article>
