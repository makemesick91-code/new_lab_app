@php
    use App\Modules\Inventory\Models\PurchaseOrder;

    $statusLabels = [
        PurchaseOrder::STATUS_DRAFT => 'Draft',
        PurchaseOrder::STATUS_SUBMITTED => 'Diajukan',
        PurchaseOrder::STATUS_APPROVED => 'Disetujui',
        PurchaseOrder::STATUS_SENT => 'Dikirim',
        PurchaseOrder::STATUS_PARTIALLY_RECEIVED => 'Diterima Sebagian',
        PurchaseOrder::STATUS_FULLY_RECEIVED => 'Diterima Lengkap',
        PurchaseOrder::STATUS_CANCELLED => 'Dibatalkan',
    ];
@endphp

<span @class([
    'inline-flex rounded-full px-3 py-1 text-xs font-medium',
    'bg-blue-100 text-blue-800' => $status === PurchaseOrder::STATUS_DRAFT,
    'bg-yellow-100 text-yellow-800' => $status === PurchaseOrder::STATUS_SUBMITTED,
    'bg-green-100 text-green-800' => $status === PurchaseOrder::STATUS_APPROVED,
    'bg-teal-100 text-teal-800' => $status === PurchaseOrder::STATUS_SENT,
    'bg-orange-100 text-orange-800' => $status === PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
    'bg-emerald-100 text-emerald-800' => $status === PurchaseOrder::STATUS_FULLY_RECEIVED,
    'bg-red-100 text-red-800' => $status === PurchaseOrder::STATUS_CANCELLED,
])>
    {{ $statusLabels[$status] ?? $status }}
</span>
