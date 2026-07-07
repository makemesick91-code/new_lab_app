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

@php
    $tone = match (true) {
        $status === PurchaseRequest::STATUS_SUBMITTED => 'info',
        $status === PurchaseRequest::STATUS_APPROVED => 'success',
        in_array($status, [PurchaseRequest::STATUS_REJECTED, PurchaseRequest::STATUS_CANCELLED], true) => 'danger',
        default => 'neutral',
    };
@endphp

<x-ui.badge :tone="$tone">{{ $statusLabels[$status] ?? $status }}</x-ui.badge>
