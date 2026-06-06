@php
    use App\Modules\Inventory\Models\StockTransfer;

    $statusLabels = [
        StockTransfer::STATUS_DRAFT => 'Draft',
        StockTransfer::STATUS_SUBMITTED => 'Diajukan',
        StockTransfer::STATUS_COMPLETED => 'Selesai',
        StockTransfer::STATUS_CANCELLED => 'Dibatalkan',
    ];
@endphp

<span @class([
    'inline-flex rounded-full px-3 py-1 text-xs font-medium',
    'bg-blue-100 text-blue-800' => $status === StockTransfer::STATUS_DRAFT,
    'bg-yellow-100 text-yellow-800' => $status === StockTransfer::STATUS_SUBMITTED,
    'bg-green-100 text-green-800' => $status === StockTransfer::STATUS_COMPLETED,
    'bg-red-100 text-red-800' => $status === StockTransfer::STATUS_CANCELLED,
])>
    {{ $statusLabels[$status] ?? $status }}
</span>
