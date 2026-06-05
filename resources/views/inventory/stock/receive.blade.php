<x-settings-shell title="Terima Stok">
    @include('inventory.stock._operation-form', [
        'action' => route('inventory.products.receive-stock.store', $product),
        'button' => 'Terima Stok',
        'operationType' => 'receive',
        'includeCost' => true,
        'includeSupplier' => true,
    ])
</x-settings-shell>
