@php
    $status = $status ?? 'active';
    $labels = [
        'active' => 'Aktif',
        'inactive' => 'Nonaktif',
        'expired' => 'Kedaluwarsa',
        'expiring_soon' => 'Segera Kedaluwarsa',
    ];
    $styles = [
        'active' => 'bg-success-50 text-success-700',
        'inactive' => 'bg-gray-100 text-gray-600',
        'expired' => 'bg-danger-50 text-danger-700',
        'expiring_soon' => 'bg-warning-50 text-warning-700',
    ];
@endphp

<span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $styles[$status] ?? $styles['active'] }}">
    {{ $labels[$status] ?? $labels['active'] }}
</span>
