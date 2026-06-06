<x-settings-shell title="Penyesuaian Keluar">
    @include('inventory.stock._operation-form', [
        'action' => route('inventory.products.adjust-out.store', $product),
        'button' => 'Buat Penyesuaian Keluar',
        'operationType' => 'adjust_out',
        'includeBatch' => true,
        'batchAllowCreate' => false,
        'batches' => $batches ?? collect(),
    ])
</x-settings-shell>
