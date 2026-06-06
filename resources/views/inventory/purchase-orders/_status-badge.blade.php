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
    'bg-gray-100 text-gray-600' => $status === PurchaseOrder::STATUS_DRAFT,
    'bg-amber-50 text-amber-700' => $status === PurchaseOrder::STATUS_SUBMITTED,
    'bg-emerald-50 text-emerald-700' => $status === PurchaseOrder::STATUS_APPROVED,
    'bg-sky-50 text-sky-700' => $status === PurchaseOrder::STATUS_SENT,
    'bg-amber-50 text-amber-700' => $status === PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
    'bg-emerald-50 text-emerald-700' => $status === PurchaseOrder::STATUS_FULLY_RECEIVED,
    'bg-rose-50 text-rose-700' => $status === PurchaseOrder::STATUS_CANCELLED,
])>
    {{ $statusLabels[$status] ?? $status }}
</span>
