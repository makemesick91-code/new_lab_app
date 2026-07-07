@php
    use App\Modules\Inventory\Models\PurchaseOrderItem;
@endphp

<span @class([
    'inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium',
    'bg-gray-100 text-gray-600' => $status === PurchaseOrderItem::RECEIVING_STATUS_PENDING,
    'bg-warning-50 text-warning-700' => $status === PurchaseOrderItem::RECEIVING_STATUS_PARTIAL,
    'bg-success-50 text-success-700' => $status === PurchaseOrderItem::RECEIVING_STATUS_COMPLETE,
])>
    {{ $label ?? $status }}
</span>
