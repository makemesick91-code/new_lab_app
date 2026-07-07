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
    'bg-gray-100 text-gray-600' => $status === StockTransfer::STATUS_DRAFT,
    'bg-warning-50 text-warning-700' => $status === StockTransfer::STATUS_SUBMITTED,
    'bg-info-50 text-info-700' => $status === StockTransfer::STATUS_IN_TRANSIT,
    'bg-success-50 text-success-700' => in_array($status, [StockTransfer::STATUS_RECEIVED, StockTransfer::STATUS_COMPLETED], true),
    'bg-danger-50 text-danger-700' => $status === StockTransfer::STATUS_CANCELLED,
])>
    {{ $statusLabels[$status] ?? $status }}
</span>
