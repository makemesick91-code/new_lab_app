@props([
    'status' => null,
    'label' => null,
])

{{--
    UIX-7 Lab status badge. Maps the Lab pipeline's uppercase lifecycle/priority/QC/
    delivery status codes to a semantic UIX-1 tone and Indonesian label, then renders
    through x-ui.badge. Presentation-only — no lifecycle logic. Pass an explicit
    `label` to override the default Indonesian label, or a slot for custom content.
--}}
@php
    $code = $status !== null ? strtoupper((string) $status) : null;

    // status code => [tone, default Indonesian label]
    $map = [
        // Lab order lifecycle
        'DRAFT' => ['neutral', 'Draft'],
        'RECEIVED' => ['info', 'Diterima'],
        'ASSIGNED' => ['info', 'Ditugaskan'],
        'REASSIGNED' => ['info', 'Ditugaskan Ulang'],
        'IN_PRODUCTION' => ['info', 'Dalam Produksi'],
        'ON_HOLD' => ['warning', 'Dijeda'],
        'QC_PENDING' => ['warning', 'Menunggu QC'],
        'QC_PASSED' => ['success', 'QC Lulus'],
        'READY_FOR_DELIVERY' => ['info', 'Siap Dikirim'],
        'IN_DELIVERY' => ['info', 'Dalam Pengiriman'],
        'DELIVERED' => ['success', 'Terkirim'],
        'COMPLETED' => ['success', 'Selesai'],
        'CANCELLED' => ['danger', 'Dibatalkan'],
        'REMAKE' => ['danger', 'Perbaikan'],

        // Priority
        'NORMAL' => ['neutral', 'Normal'],
        'URGENT' => ['warning', 'Mendesak'],
        'SUPER_URGENT' => ['danger', 'Sangat Mendesak'],

        // QC / checklist results
        'PENDING' => ['warning', 'Menunggu'],
        'PASS' => ['success', 'Lulus'],
        'PASSED' => ['success', 'Lulus'],
        'FAIL' => ['danger', 'Gagal'],
        'REJECTED' => ['danger', 'Ditolak'],
        'REVISION' => ['warning', 'Revisi'],
        'N_A' => ['neutral', 'N/A'],

        // Production step / delivery states
        'OPEN' => ['neutral', 'Terbuka'],
        'IN_PROGRESS' => ['info', 'Berjalan'],
        'DONE' => ['success', 'Selesai'],
        'SKIPPED' => ['neutral', 'Dilewati'],
    ];

    [$tone, $autoLabel] = $map[$code] ?? ['neutral', \Illuminate\Support\Str::of((string) $status)->replace('_', ' ')->title()];
@endphp

<x-ui.badge :tone="$tone" {{ $attributes }}>{{ $slot->isNotEmpty() ? $slot : ($label ?? $autoLabel) }}</x-ui.badge>
