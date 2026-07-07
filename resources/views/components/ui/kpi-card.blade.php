@props([
    'label' => null,
    'value' => null,
    'delta' => null,
    'trend' => null,   // 'up' | 'down' | 'flat'
    'accent' => false, // gold premium accent rail (revenue/key KPIs only)
])

@php
    $trendClass = match ($trend) {
        'up' => 'text-success',
        'down' => 'text-danger',
        default => 'text-ink-soft',
    };
@endphp

<div {{ $attributes->merge(['class' => 'ui-card p-5 '.($accent ? 'relative overflow-hidden before:absolute before:inset-y-0 before:left-0 before:w-1 before:bg-gold' : '')]) }}>
    @if ($label)
        <p class="text-xs font-medium uppercase tracking-wide text-ink-soft">{{ $label }}</p>
    @endif
    <p class="mt-2 text-2xl font-bold text-navy">{{ $value ?? $slot }}</p>
    @if ($delta !== null)
        <p class="mt-1 text-xs font-medium {{ $trendClass }}">
            @if ($trend === 'up') &#9650; @elseif ($trend === 'down') &#9660; @endif {{ $delta }}
        </p>
    @endif
</div>
