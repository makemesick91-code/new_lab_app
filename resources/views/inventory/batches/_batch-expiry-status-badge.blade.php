@php
    use App\Modules\Inventory\Services\BatchExpiryStatusService;

    $expiryStatus = $expiryStatus ?? BatchExpiryStatusService::STATUS_ACTIVE;
    $labels = [
        BatchExpiryStatusService::STATUS_EXPIRED => 'Kedaluwarsa',
        BatchExpiryStatusService::STATUS_NEAR_EXPIRY => 'Akan Kedaluwarsa',
        BatchExpiryStatusService::STATUS_ACTIVE => 'Aktif',
        BatchExpiryStatusService::STATUS_NO_EXPIRY => 'Tanpa Expired',
        'inactive' => 'Nonaktif',
    ];
    $styles = [
        BatchExpiryStatusService::STATUS_EXPIRED => 'bg-rose-50 text-rose-700',
        BatchExpiryStatusService::STATUS_NEAR_EXPIRY => 'bg-amber-50 text-amber-700',
        BatchExpiryStatusService::STATUS_ACTIVE => 'bg-emerald-50 text-emerald-700',
        BatchExpiryStatusService::STATUS_NO_EXPIRY => 'bg-gray-100 text-gray-600',
        'inactive' => 'bg-gray-100 text-gray-600',
    ];
@endphp

<span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $styles[$expiryStatus] ?? $styles[BatchExpiryStatusService::STATUS_ACTIVE] }}">
    {{ $labels[$expiryStatus] ?? $labels[BatchExpiryStatusService::STATUS_ACTIVE] }}
</span>
