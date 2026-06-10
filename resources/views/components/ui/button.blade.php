@props([
    'variant' => 'primary',
    'type' => 'button',
    'href' => null,
])

@php
    $base = 'inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2';
    $variants = [
        'primary' => 'bg-teal-700 text-white hover:bg-teal-600 focus:ring-teal-500',
        'secondary' => 'border border-gray-200 bg-white text-gray-700 hover:bg-gray-50 focus:ring-teal-500',
        'neutral' => 'bg-gray-900 text-white hover:bg-gray-800 focus:ring-gray-900',
        'danger' => 'bg-rose-700 text-white hover:bg-rose-600 focus:ring-rose-500',
    ];
    $classes = $base.' '.($variants[$variant] ?? $variants['primary']);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
