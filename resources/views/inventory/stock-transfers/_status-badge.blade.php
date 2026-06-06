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
    'bg-amber-50 text-amber-700' => $status === StockTransfer::STATUS_SUBMITTED,
    'bg-sky-50 text-sky-700' => $status === StockTransfer::STATUS_IN_TRANSIT,
    'bg-emerald-50 text-emerald-700' => in_array($status, [StockTransfer::STATUS_RECEIVED, StockTransfer::STATUS_COMPLETED], true),
    'bg-rose-50 text-rose-700' => $status === StockTransfer::STATUS_CANCELLED,
])>
    {{ $statusLabels[$status] ?? $status }}
</span>
