<x-settings-shell title="Penyesuaian Masuk">
    @include('inventory.stock._operation-form', [
        'action' => route('inventory.products.adjust-in.store', $product),
        'button' => 'Buat Penyesuaian Masuk',
        'operationType' => 'adjust_in',
    ])
</x-settings-shell>
