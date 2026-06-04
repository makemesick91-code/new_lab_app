@props([
    'label',
    'value' => '0',
    'context' => null,
    'severity' => 'neutral',
    'href' => null,
])

@php
    $tones = [
        'success' => 'border-emerald-100 bg-emerald-50 text-emerald-800',
        'warning' => 'border-amber-100 bg-amber-50 text-amber-800',
        'danger' => 'border-rose-100 bg-rose-50 text-rose-800',
        'critical' => 'border-rose-100 bg-rose-50 text-rose-800',
        'info' => 'border-sky-100 bg-sky-50 text-sky-800',
        'neutral' => 'border-gray-200 bg-white text-gray-900',
    ];
    $classes = $tones[$severity] ?? $tones['neutral'];
@endphp

<article {{ $attributes->merge(['class' => "rounded-lg border p-4 shadow-sm {$classes}"]) }}>
    <div class="flex min-h-24 flex-col justify-between gap-3">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $label }}</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums">{{ $value }}</p>
            @if ($context)
                <p class="mt-1 text-xs text-gray-500">{{ $context }}</p>
            @endif
        </div>

        @if ($href)
            <a href="{{ $href }}" class="text-xs font-semibold text-teal-700 hover:text-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                Open queue
            </a>
        @endif
    </div>
</article>
