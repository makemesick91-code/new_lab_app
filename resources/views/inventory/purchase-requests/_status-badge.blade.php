@php
    use App\Modules\Inventory\Models\PurchaseRequest;

    $statusLabels = [
        PurchaseRequest::STATUS_DRAFT => 'Draft',
        PurchaseRequest::STATUS_SUBMITTED => 'Diajukan',
        PurchaseRequest::STATUS_APPROVED => 'Disetujui',
        PurchaseRequest::STATUS_REJECTED => 'Ditolak',
        PurchaseRequest::STATUS_CANCELLED => 'Dibatalkan',
    ];
@endphp

<span @class([
    'inline-flex rounded-full px-3 py-1 text-xs font-medium',
    'bg-blue-100 text-blue-800' => $status === PurchaseRequest::STATUS_DRAFT,
    'bg-yellow-100 text-yellow-800' => $status === PurchaseRequest::STATUS_SUBMITTED,
    'bg-green-100 text-green-800' => $status === PurchaseRequest::STATUS_APPROVED,
    'bg-red-100 text-red-800' => in_array($status, [PurchaseRequest::STATUS_REJECTED, PurchaseRequest::STATUS_CANCELLED], true),
])>
    {{ $statusLabels[$status] ?? $status }}
</span>
