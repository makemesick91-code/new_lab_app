@php
    $labels = [
        'out_of_stock' => 'Stok Habis',
        'critical' => 'Stok Kritis',
        'low' => 'Stok Rendah',
        'batch_expired' => 'Batch Kedaluwarsa',
        'batch_expiring_soon' => 'Akan Kedaluwarsa',
    ];
    $styles = [
        'out_of_stock' => 'bg-danger-50 text-danger-700',
        'critical' => 'bg-orange-50 text-orange-700',
        'low' => 'bg-warning-50 text-warning-700',
        'batch_expired' => 'bg-danger-50 text-danger-700',
        'batch_expiring_soon' => 'bg-warning-50 text-warning-700',
    ];
    $severity = $severity ?? 'low';
@endphp

<span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $styles[$severity] ?? $styles['low'] }}">
    {{ $labels[$severity] ?? $severity }}
</span>
