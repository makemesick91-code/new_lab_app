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

    // Canonical domain status -> tone mapping. Keys are lowercase and the lookup
    // is case-insensitive (UIX-15), so uppercase lifecycle codes (invoice PAID,
    // procurement POSTED, lab DELIVERED, …) resolve to the same tone as their
    // lowercase form. Unknown statuses fall back to `neutral` (safe default).
    $statusTone = [
        // Generic / neutral
        'draft' => 'neutral',
        'normal' => 'neutral',
        // Warning (attention / outstanding)
        'waiting' => 'warning',
        'pending' => 'warning',
        'qc' => 'warning',
        'low_stock' => 'warning',
        'expired_soon' => 'warning',
        'unpaid' => 'warning',       // UIX-15 — RME invoice UNPAID
        'partial' => 'warning',      // UIX-15 — RME invoice PARTIAL (cicilan / sisa tagihan)
        'overstock' => 'warning',    // UIX-15 — inventory overstock
        // Info (in-flight / neutral-positive)
        'in_progress' => 'info',
        'info' => 'info',
        'registered' => 'info',      // UIX-15 — RME visit registered
        'submitted' => 'info',       // UIX-15 — procurement submitted
        // Financial hold (premium accent, cashier handoff)
        'cashier_pending' => 'gold',
        // Success (settled / approved / finished)
        'paid' => 'success',
        'approved' => 'success',
        'completed' => 'success',
        'delivered' => 'success',
        'success' => 'success',
        'posted' => 'success',       // UIX-15 — procurement goods-receipt posted
        'received' => 'success',     // UIX-15 — lab / procurement received
        // Danger (cancelled / rejected / void)
        'cancelled' => 'danger',
        'rejected' => 'danger',
        'out_of_stock' => 'danger',
        'expired' => 'danger',
        'void' => 'danger',          // UIX-15 — RME invoice VOID
        'danger' => 'danger',
        'warning' => 'warning',
    ];

    $statusKey = $status !== null ? \Illuminate\Support\Str::lower((string) $status) : null;
    $resolvedTone = $statusKey !== null ? ($statusTone[$statusKey] ?? 'neutral') : $tone;
    $classes = 'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset '.($tones[$resolvedTone] ?? $tones['neutral']);
    $autoLabel = $status !== null ? \Illuminate\Support\Str::of($status)->replace('_', ' ')->title() : null;
@endphp

<span {{ $attributes->merge(['class' => $classes]) }}>{{ $slot->isNotEmpty() ? $slot : $autoLabel }}</span>
