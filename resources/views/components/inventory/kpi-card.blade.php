@props([
    'label',
    'value' => '0',
    'hint' => null,
    'tone' => 'neutral',
    'href' => null,
])

@php
    $tones = [
        'success' => 'border-success-100 bg-success-50 text-success-700',
        'warning' => 'border-warning-100 bg-warning-50 text-warning-700',
        'danger' => 'border-danger-100 bg-danger-50 text-danger-700',
        'info' => 'border-info-100 bg-info-50 text-info-700',
        'primary' => 'border-brand-100 bg-brand-50 text-brand-700',
        'neutral' => 'border-hairline bg-surface text-navy',
    ];
    $classes = $tones[$tone] ?? $tones['neutral'];
@endphp

<article {{ $attributes->merge(['class' => "rounded-lg border p-4 shadow-sm {$classes}"]) }}>
    <div class="flex min-h-24 flex-col justify-between gap-3">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-soft">{{ $label }}</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums">{{ $value }}</p>
            @if ($hint)
                <p class="mt-1 text-xs text-ink-soft">{{ $hint }}</p>
            @endif
        </div>

        @if ($href)
            <a href="{{ $href }}" class="text-xs font-semibold text-brand-700 hover:text-brand-800 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                Lihat detail
            </a>
        @endif
    </div>
</article>
