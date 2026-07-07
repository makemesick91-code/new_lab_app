@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
    'href' => null,
    'loading' => false,
    'disabled' => false,
])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-lg font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60';

    $sizes = [
        'sm' => 'px-3 py-1.5 text-xs',
        'md' => 'px-4 py-2 text-sm',
        'lg' => 'px-5 py-2.5 text-base',
    ];

    // Primary CTA = blue (brand). Gold is accent-only, never used as `primary`.
    $variants = [
        'primary' => 'bg-brand-600 text-white hover:bg-brand-700 focus:ring-brand-500',
        'secondary' => 'border border-hairline bg-surface text-navy hover:bg-navy-50 focus:ring-brand-500',
        'neutral' => 'bg-navy text-white hover:bg-navy-700 focus:ring-navy',
        'danger' => 'bg-danger text-white hover:bg-danger-700 focus:ring-danger',
        'success' => 'bg-success text-white hover:bg-success-700 focus:ring-success',
        'warning' => 'bg-warning text-white hover:bg-warning-700 focus:ring-warning',
        'ghost' => 'bg-transparent text-brand-700 hover:bg-brand-50 focus:ring-brand-500',
        // Accent-only premium highlight (NOT a primary CTA).
        'gold' => 'bg-gold text-gold-700 ring-1 ring-inset ring-gold-200 hover:bg-gold-100 focus:ring-gold-500',
    ];

    $classes = $base.' '.($sizes[$size] ?? $sizes['md']).' '.($variants[$variant] ?? $variants['primary']);
    $isDisabled = $disabled || $loading;
@endphp

@php
    $spinner = '<svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>';
@endphp

@if ($href && ! $isDisabled)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($loading) {!! $spinner !!} @endif
        {{ $slot }}
    </a>
@else
    <button
        type="{{ $type }}"
        @if ($isDisabled) disabled aria-disabled="true" @endif
        @if ($loading) aria-busy="true" @endif
        {{ $attributes->merge(['class' => $classes]) }}
    >
        @if ($loading) {!! $spinner !!} @endif
        {{ $slot }}
    </button>
@endif
