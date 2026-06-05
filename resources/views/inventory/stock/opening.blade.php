<x-settings-shell title="Stok Awal">
    @include('inventory.stock._operation-form', [
        'action' => route('inventory.products.opening-stock.store', $product),
        'button' => 'Buat Stok Awal',
        'operationType' => 'opening',
        'includeCost' => true,
    ])
</x-settings-shell>
