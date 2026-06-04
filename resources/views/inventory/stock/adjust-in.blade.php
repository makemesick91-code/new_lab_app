<x-settings-shell title="Adjustment In">
    @include('inventory.stock._operation-form', [
        'action' => route('inventory.products.adjust-in.store', $product),
        'button' => 'Create Adjustment In',
    ])
</x-settings-shell>
