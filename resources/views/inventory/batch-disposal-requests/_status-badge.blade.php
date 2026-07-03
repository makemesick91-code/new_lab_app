@php
    $classes = match ($status) {
        'draft' => 'bg-gray-100 text-gray-800',
        'submitted' => 'bg-amber-100 text-amber-800',
        'approved' => 'bg-teal-100 text-teal-800',
        'rejected' => 'bg-rose-100 text-rose-800',
        'adjustment_recorded' => 'bg-emerald-100 text-emerald-800',
        'cancelled' => 'bg-gray-100 text-gray-600',
        default => 'bg-gray-100 text-gray-800',
    };
@endphp
<span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold {{ $classes }}">
    {{ \App\Modules\Inventory\Enums\InventoryBatchDisposalRequestStatus::label($status) }}
</span>
