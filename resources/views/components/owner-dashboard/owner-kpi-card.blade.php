@props([
    'label',
    'value' => '0',
    'secondary' => null,
    'trend' => null,
    'severity' => 'neutral',
    'href' => null,
    'showNoAccessHint' => false,
])

@php
    $tones = [
        'success' => 'text-success-700 bg-success-50 border-success-100',
        'warning' => 'text-warning-700 bg-warning-50 border-warning-100',
        'critical' => 'text-danger-700 bg-danger-50 border-danger-100',
        'danger' => 'text-danger-700 bg-danger-50 border-danger-100',
        'info' => 'text-info-700 bg-info-50 border-info-100',
        'neutral' => 'text-navy bg-surface border-hairline',
    ];
    $tone = $tones[$severity] ?? $tones['neutral'];
    $content = trim($slot) !== '' ? $slot : null;
@endphp

<div {{ $attributes->merge(['class' => "rounded-xl border {$tone} p-4 shadow-sm transition-shadow hover:shadow-md"]) }}>
    <div class="flex min-h-28 flex-col justify-between gap-3">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-soft">{{ $label }}</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums text-navy">{{ $value }}</p>
            @if ($secondary)
                <p class="mt-1 text-xs text-ink-soft">{{ $secondary }}</p>
            @endif
            @if ($trend)
                <p class="mt-1 text-xs font-medium text-ink">{{ $trend }}</p>
            @endif
            @if ($content)
                <div class="mt-2 text-xs text-ink-soft">{{ $slot }}</div>
            @endif
        </div>

        @if ($href)
            <a href="{{ $href }}" class="text-xs font-semibold text-brand-700 hover:text-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                Lihat detail
            </a>
        @elseif ($showNoAccessHint)
            <span class="text-xs text-ink-muted">Tidak ada akses detail</span>
        @endif
    </div>
</div>
