<x-settings-shell title="Adjustment Out">
    @include('inventory.stock._operation-form', [
        'action' => route('inventory.products.adjust-out.store', $product),
        'button' => 'Create Adjustment Out',
        'operationType' => 'adjust_out',
    ])
</x-settings-shell>
