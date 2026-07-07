@props([
    'variant' => 'info',
    'title' => null,
    'dismissible' => false,
])

@php
    $variants = [
        'info' => 'bg-info-50 text-info-700 border-info-100',
        'success' => 'bg-success-50 text-success-700 border-success-100',
        'warning' => 'bg-warning-50 text-warning-700 border-warning-100',
        'danger' => 'bg-danger-50 text-danger-700 border-danger-100',
    ];
    $classes = 'flex items-start gap-3 rounded-lg border px-4 py-3 text-sm '.($variants[$variant] ?? $variants['info']);
@endphp

<div
    role="alert"
    @if ($dismissible) x-data="{ show: true }" x-show="show" @endif
    {{ $attributes->merge(['class' => $classes]) }}
>
    <div class="min-w-0 flex-1">
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        <div @class(['mt-0.5' => $title])>{{ $slot }}</div>
    </div>

    @if ($dismissible)
        <button type="button" class="shrink-0 rounded p-0.5 opacity-70 hover:opacity-100 focus:outline-none focus:ring-2 focus:ring-current" @click="show = false" aria-label="Tutup">
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>
        </button>
    @endif
</div>
