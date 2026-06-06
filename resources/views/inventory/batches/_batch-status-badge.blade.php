@php
    $status = $status ?? 'active';
    $labels = [
        'active' => 'Aktif',
        'inactive' => 'Nonaktif',
        'expired' => 'Kedaluwarsa',
        'expiring_soon' => 'Segera Kedaluwarsa',
    ];
    $styles = [
        'active' => 'bg-emerald-50 text-emerald-700',
        'inactive' => 'bg-gray-100 text-gray-600',
        'expired' => 'bg-rose-50 text-rose-700',
        'expiring_soon' => 'bg-amber-50 text-amber-700',
    ];
@endphp

<span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $styles[$status] ?? $styles['active'] }}">
    {{ $labels[$status] ?? $labels['active'] }}
</span>
