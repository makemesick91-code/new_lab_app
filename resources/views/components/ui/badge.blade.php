@props([
    'tone' => 'neutral',
    'status' => null,
])

@php
    // Token-based tones (UIX-1). Backward compatible with existing tone values.
    $tones = [
        'success' => 'bg-success-100 text-success-700 ring-success-100',
        'warning' => 'bg-warning-100 text-warning-700 ring-warning-100',
        'danger' => 'bg-danger-100 text-danger-700 ring-danger-100',
        'info' => 'bg-info-100 text-info-700 ring-info-100',
        'neutral' => 'bg-navy-50 text-ink-soft ring-hairline',
        'primary' => 'bg-brand-50 text-brand-700 ring-brand-100',
        'gold' => 'bg-gold-100 text-gold-700 ring-gold-200',
    ];

    // Domain status -> tone mapping (UIX-1 required statuses).
    $statusTone = [
        'draft' => 'neutral',
        'normal' => 'neutral',
        'waiting' => 'warning',
        'pending' => 'warning',
        'qc' => 'warning',
        'low_stock' => 'warning',
        'expired_soon' => 'warning',
        'in_progress' => 'info',
        'info' => 'info',
        'cashier_pending' => 'gold',
        'paid' => 'success',
        'approved' => 'success',
        'completed' => 'success',
        'delivered' => 'success',
        'success' => 'success',
        'cancelled' => 'danger',
        'rejected' => 'danger',
        'out_of_stock' => 'danger',
        'expired' => 'danger',
        'danger' => 'danger',
        'warning' => 'warning',
    ];

    $resolvedTone = $status !== null ? ($statusTone[$status] ?? 'neutral') : $tone;
    $classes = 'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset '.($tones[$resolvedTone] ?? $tones['neutral']);
    $autoLabel = $status !== null ? \Illuminate\Support\Str::of($status)->replace('_', ' ')->title() : null;
@endphp

<span {{ $attributes->merge(['class' => $classes]) }}>{{ $slot->isNotEmpty() ? $slot : $autoLabel }}</span>
