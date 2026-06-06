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

<span @class([
    'inline-flex rounded-full px-3 py-1 text-xs font-medium',
    'bg-blue-100 text-blue-800' => $status === GoodsReceipt::STATUS_DRAFT,
    'bg-yellow-100 text-yellow-800' => $status === GoodsReceipt::STATUS_SUBMITTED,
    'bg-green-100 text-green-800' => $status === GoodsReceipt::STATUS_POSTED,
    'bg-red-100 text-red-800' => $status === GoodsReceipt::STATUS_CANCELLED,
    'bg-gray-200 text-gray-800' => $status === GoodsReceipt::STATUS_VOID,
])>
    {{ $statusLabels[$status] ?? $status }}
</span>
