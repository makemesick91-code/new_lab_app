@php
    $styles = [
        'fresh' => 'bg-brand-50 text-brand-800 ring-brand-200',
        'aging' => 'bg-sky-50 text-sky-800 ring-sky-200',
        'stale' => 'bg-amber-50 text-amber-800 ring-amber-200',
        'old' => 'bg-orange-50 text-orange-800 ring-orange-200',
        'very_old' => 'bg-rose-50 text-rose-800 ring-rose-200',
    ];
    $classes = $styles[$bucket] ?? 'bg-gray-50 text-gray-700 ring-gray-200';
@endphp

<span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset {{ $classes }}">
    {{ $label }}
</span>
