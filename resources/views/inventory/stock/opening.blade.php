<x-settings-shell title="Opening Stock">
    @include('inventory.stock._operation-form', [
        'action' => route('inventory.products.opening-stock.store', $product),
        'button' => 'Create Opening Stock',
        'operationType' => 'opening',
        'includeCost' => true,
    ])
</x-settings-shell>
