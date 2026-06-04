<x-settings-shell title="Receive Stock">
    @include('inventory.stock._operation-form', [
        'action' => route('inventory.products.receive-stock.store', $product),
        'button' => 'Receive Stock',
        'includeCost' => true,
        'includeSupplier' => true,
    ])
</x-settings-shell>
