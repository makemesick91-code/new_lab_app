@php
    use App\Modules\Inventory\Models\PurchaseOrderItem;
@endphp

<span @class([
    'inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium',
    'bg-gray-100 text-gray-600' => $status === PurchaseOrderItem::RECEIVING_STATUS_PENDING,
    'bg-amber-50 text-amber-700' => $status === PurchaseOrderItem::RECEIVING_STATUS_PARTIAL,
    'bg-emerald-50 text-emerald-700' => $status === PurchaseOrderItem::RECEIVING_STATUS_COMPLETE,
])>
    {{ $label ?? $status }}
</span>
