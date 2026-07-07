@php
    use App\Modules\Inventory\Models\GoodsReceipt;

    $statusLabels = [
        GoodsReceipt::STATUS_DRAFT => 'Draft',
        GoodsReceipt::STATUS_SUBMITTED => 'Diajukan',
        GoodsReceipt::STATUS_POSTED => 'Diposting',
        GoodsReceipt::STATUS_CANCELLED => 'Dibatalkan',
        GoodsReceipt::STATUS_VOID => 'Divid',
    ];
@endphp

@php
    $tone = match ($status) {
        GoodsReceipt::STATUS_SUBMITTED => 'info',
        GoodsReceipt::STATUS_POSTED => 'success',
        GoodsReceipt::STATUS_CANCELLED => 'danger',
        GoodsReceipt::STATUS_VOID => 'danger',
        default => 'neutral',
    };
@endphp

<x-ui.badge :tone="$tone">{{ $statusLabels[$status] ?? $status }}</x-ui.badge>
