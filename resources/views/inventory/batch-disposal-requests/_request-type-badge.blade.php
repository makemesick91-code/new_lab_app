@php
    $classes = match ($requestType) {
        'disposal', 'expired' => 'bg-rose-100 text-rose-800',
        'return_supplier' => 'bg-orange-100 text-orange-800',
        'quarantine_adjustment' => 'bg-amber-100 text-amber-800',
        'damaged' => 'bg-red-100 text-red-800',
        default => 'bg-gray-100 text-gray-800',
    };
@endphp
<span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold {{ $classes }}">
    {{ \App\Modules\Inventory\Enums\InventoryBatchDisposalRequestType::label($requestType) }}
</span>
