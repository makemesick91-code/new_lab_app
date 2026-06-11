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
        'success' => 'text-emerald-700 bg-emerald-50 border-emerald-100',
        'warning' => 'text-amber-700 bg-amber-50 border-amber-100',
        'critical' => 'text-rose-700 bg-rose-50 border-rose-100',
        'danger' => 'text-rose-700 bg-rose-50 border-rose-100',
        'info' => 'text-sky-700 bg-sky-50 border-sky-100',
        'neutral' => 'text-gray-900 bg-white border-gray-200',
    ];
    $tone = $tones[$severity] ?? $tones['neutral'];
    $content = trim($slot) !== '' ? $slot : null;
@endphp

<div {{ $attributes->merge(['class' => "rounded-xl border {$tone} p-4 shadow-sm transition-shadow hover:shadow-md"]) }}>
    <div class="flex min-h-28 flex-col justify-between gap-3">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $label }}</p>
            <p class="mt-2 text-2xl font-semibold tabular-nums">{{ $value }}</p>
            @if ($secondary)
                <p class="mt-1 text-xs text-gray-500">{{ $secondary }}</p>
            @endif
            @if ($trend)
                <p class="mt-1 text-xs font-medium text-gray-700">{{ $trend }}</p>
            @endif
            @if ($content)
                <div class="mt-2 text-xs text-gray-500">{{ $slot }}</div>
            @endif
        </div>

        @if ($href)
            <a href="{{ $href }}" class="text-xs font-semibold text-teal-700 hover:text-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                Lihat detail
            </a>
        @elseif ($showNoAccessHint)
            <span class="text-xs text-gray-400">Tidak ada akses detail</span>
        @endif
    </div>
</div>
