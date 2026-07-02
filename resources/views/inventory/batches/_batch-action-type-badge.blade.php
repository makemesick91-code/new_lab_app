@php
    use App\Modules\Inventory\Enums\InventoryBatchActionType;

    $badgeClasses = match ($actionType) {
        InventoryBatchActionType::USE_SOON => 'bg-amber-100 text-amber-800',
        InventoryBatchActionType::QUARANTINE => 'bg-orange-100 text-orange-800',
        InventoryBatchActionType::RETURN_SUPPLIER => 'bg-sky-100 text-sky-800',
        InventoryBatchActionType::DISPOSAL_PLANNED => 'bg-rose-100 text-rose-800',
        InventoryBatchActionType::RELEASED => 'bg-emerald-100 text-emerald-800',
        InventoryBatchActionType::NOTE => 'bg-gray-100 text-gray-700',
        default => 'bg-gray-100 text-gray-700',
    };
@endphp

<span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $badgeClasses }}">
    {{ InventoryBatchActionType::label($actionType) }}
</span>
