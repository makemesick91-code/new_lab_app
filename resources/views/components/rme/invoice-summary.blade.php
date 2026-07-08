@props([
    'invoice',
])

{{--
    UIX-12 — Standardized RME financial summary cards (Total / Sudah Dibayar /
    Sisa Tagihan / Status). Presentation-only: it reads existing invoice
    accessors (grand_total, paidAmount(), remainingAmount(), status) and never
    computes, mutates, or changes any payment/receivable/invoice-status value.
    Reuse this on every cashier/payment/receivable financial surface so the
    "remaining amount" is always visible and partial payment is always clear.
--}}
@php
    $paidAmount = $invoice->paidAmount();
    $remainingAmount = $invoice->remainingAmount();

    $summaryStatusLabels = [
        'DRAFT'   => 'Draft',
        'UNPAID'  => 'Belum Dibayar',
        'PARTIAL' => 'Cicilan / Sebagian',
        'PAID'    => 'Lunas',
        'VOID'    => 'Dibatalkan',
    ];
    $summaryStatusTone = [
        'DRAFT'   => 'info',
        'UNPAID'  => 'warning',
        'PARTIAL' => 'warning',
        'PAID'    => 'success',
        'VOID'    => 'danger',
    ];

    // Presentation-only colour: settled (remaining Rp 0) reads calm/positive,
    // outstanding balance reads as a warning. No amount is recalculated here.
    $remainingClass = $remainingAmount > 0 ? 'text-warning-700' : 'text-success-700';
@endphp

<div {{ $attributes->merge(['class' => 'grid grid-cols-2 gap-4 lg:grid-cols-4']) }}>
    <div class="ui-card p-5">
        <p class="text-xs font-medium uppercase tracking-wide text-ink-soft">Total Tagihan</p>
        <p class="mt-2 text-2xl font-bold tabular-nums text-navy">Rp {{ number_format($invoice->grand_total, 0, ',', '.') }}</p>
    </div>
    <div class="ui-card p-5">
        <p class="text-xs font-medium uppercase tracking-wide text-ink-soft">Sudah Dibayar</p>
        <p class="mt-2 text-2xl font-bold tabular-nums text-success-700">Rp {{ number_format($paidAmount, 0, ',', '.') }}</p>
    </div>
    <div class="ui-card p-5">
        <p class="text-xs font-medium uppercase tracking-wide text-ink-soft">Sisa Tagihan</p>
        <p class="mt-2 text-2xl font-bold tabular-nums {{ $remainingClass }}">Rp {{ number_format($remainingAmount, 0, ',', '.') }}</p>
    </div>
    <div class="ui-card p-5">
        <p class="text-xs font-medium uppercase tracking-wide text-ink-soft">Status Tagihan</p>
        <div class="mt-2">
            <x-ui.badge :tone="$summaryStatusTone[$invoice->status] ?? 'neutral'">
                {{ $summaryStatusLabels[$invoice->status] ?? $invoice->status }}
            </x-ui.badge>
        </div>
    </div>
</div>
