@props([
    'tone' => 'neutral',
])

@php
    $tones = [
        'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-100',
        'warning' => 'bg-amber-50 text-amber-700 ring-amber-100',
        'danger' => 'bg-rose-50 text-rose-700 ring-rose-100',
        'info' => 'bg-sky-50 text-sky-700 ring-sky-100',
        'neutral' => 'bg-gray-100 text-gray-600 ring-gray-200',
        'primary' => 'bg-teal-50 text-teal-700 ring-teal-100',
    ];
    $classes = 'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset '.($tones[$tone] ?? $tones['neutral']);
@endphp

<span {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</span>
