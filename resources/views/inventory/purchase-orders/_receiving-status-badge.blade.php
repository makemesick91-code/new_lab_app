@php
    use App\Modules\Inventory\Models\PurchaseOrderItem;
@endphp

<span @class([
    'inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium',
    'bg-gray-100 text-gray-700' => $status === PurchaseOrderItem::RECEIVING_STATUS_PENDING,
    'bg-orange-100 text-orange-800' => $status === PurchaseOrderItem::RECEIVING_STATUS_PARTIAL,
    'bg-emerald-100 text-emerald-800' => $status === PurchaseOrderItem::RECEIVING_STATUS_COMPLETE,
])>
    {{ $label ?? $status }}
</span>
