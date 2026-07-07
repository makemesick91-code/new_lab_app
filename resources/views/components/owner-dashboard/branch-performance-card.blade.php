@props([
    'branch',
])

@php
    $health = data_get($branch, 'health', 'Belum ada data');
    $severity = data_get($branch, 'severity', 'neutral');
    $badgeClasses = [
        'critical' => 'bg-danger-50 text-danger-700',
        'danger' => 'bg-danger-50 text-danger-700',
        'warning' => 'bg-warning-50 text-warning-700',
        'success' => 'bg-success-50 text-success-700',
        'neutral' => 'bg-navy-50 text-ink-soft',
    ][$severity] ?? 'bg-navy-50 text-ink-soft';
@endphp

<article {{ $attributes->merge(['class' => 'ui-card p-4']) }}>
    <div class="flex items-start justify-between gap-3">
        <div>
            <h4 class="text-sm font-semibold text-navy">{{ data_get($branch, 'name', 'Cabang') }}</h4>
            <p class="mt-1 text-xs text-ink-soft">{{ data_get($branch, 'description', 'Ringkasan performa cabang') }}</p>
        </div>
        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $badgeClasses }}">{{ $health }}</span>
    </div>

    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
        <div>
            <dt class="text-xs text-ink-soft">Pendapatan</dt>
            <dd class="mt-1 font-semibold tabular-nums text-navy">{{ format_currency_id(data_get($branch, 'revenue', 0)) }}</dd>
        </div>
        <div>
            <dt class="text-xs text-ink-soft">Order</dt>
            <dd class="mt-1 font-semibold tabular-nums text-navy">{{ format_number_id(data_get($branch, 'orders', 0)) }}</dd>
        </div>
        <div>
            <dt class="text-xs text-ink-soft">Waktu Selesai</dt>
            <dd class="mt-1 font-semibold tabular-nums text-navy">{{ data_get($branch, 'completionTime', 'Belum ada data') }}</dd>
        </div>
        <div>
            <dt class="text-xs text-ink-soft">Tertunggak</dt>
            <dd class="mt-1 font-semibold tabular-nums text-navy">{{ format_currency_id(data_get($branch, 'outstanding', 0)) }}</dd>
        </div>
    </dl>

    @if (data_get($branch, 'href'))
        <a href="{{ data_get($branch, 'href') }}" class="mt-4 inline-flex text-xs font-semibold text-brand-700 hover:text-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
            Lihat detail cabang
        </a>
    @endif
</article>
