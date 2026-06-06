@php
    use App\Modules\Inventory\Models\StockTransfer;

    $statusLabels = [
        StockTransfer::STATUS_DRAFT => 'Draft',
        StockTransfer::STATUS_SUBMITTED => 'Diajukan',
        StockTransfer::STATUS_IN_TRANSIT => 'Dalam Perjalanan',
        StockTransfer::STATUS_RECEIVED => 'Diterima',
        StockTransfer::STATUS_COMPLETED => 'Diterima',
        StockTransfer::STATUS_CANCELLED => 'Dibatalkan',
    ];
@endphp

<span @class([
    'inline-flex rounded-full px-3 py-1 text-xs font-medium',
    'bg-blue-100 text-blue-800' => $status === StockTransfer::STATUS_DRAFT,
    'bg-yellow-100 text-yellow-800' => $status === StockTransfer::STATUS_SUBMITTED,
    'bg-orange-100 text-orange-800' => $status === StockTransfer::STATUS_IN_TRANSIT,
    'bg-green-100 text-green-800' => in_array($status, [StockTransfer::STATUS_RECEIVED, StockTransfer::STATUS_COMPLETED], true),
    'bg-red-100 text-red-800' => $status === StockTransfer::STATUS_CANCELLED,
])>
    {{ $statusLabels[$status] ?? $status }}
</span>
