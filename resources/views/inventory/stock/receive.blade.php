<x-settings-shell title="Terima Stok">
    @include('inventory.stock._operation-form', [
        'action' => route('inventory.products.receive-stock.store', $product),
        'button' => 'Terima Stok',
        'operationType' => 'receive',
        'includeCost' => true,
        'includeSupplier' => true,
        'includeBatch' => true,
        'batches' => $batches ?? collect(),
        'batchHelp' => 'Pilih "Buat Batch Baru" untuk mengisi nomor batch/lot dan tanggal kedaluwarsa. Batch akan tampil di halaman Batch & Lot setelah stok diterima.',
    ])
</x-settings-shell>
