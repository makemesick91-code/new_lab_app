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
        BatchExpiryStatusService::STATUS_EXPIRED => 'bg-danger-50 text-danger-700',
        BatchExpiryStatusService::STATUS_NEAR_EXPIRY => 'bg-warning-50 text-warning-700',
        BatchExpiryStatusService::STATUS_ACTIVE => 'bg-success-50 text-success-700',
        BatchExpiryStatusService::STATUS_NO_EXPIRY => 'bg-gray-100 text-gray-600',
        'inactive' => 'bg-gray-100 text-gray-600',
    ];
@endphp

<span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $styles[$expiryStatus] ?? $styles[BatchExpiryStatusService::STATUS_ACTIVE] }}">
    {{ $labels[$expiryStatus] ?? $labels[BatchExpiryStatusService::STATUS_ACTIVE] }}
</span>
