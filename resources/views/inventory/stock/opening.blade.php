<x-settings-shell title="Stok Awal">
    @include('inventory.stock._operation-form', [
        'action' => route('inventory.products.opening-stock.store', $product),
        'button' => 'Buat Stok Awal',
        'operationType' => 'opening',
        'includeCost' => true,
        'includeBatch' => true,
        'batches' => $batches ?? collect(),
        'batchHelp' => 'Untuk stok awal barang yang memiliki batch/expired, isi nomor batch/lot dan tanggal kedaluwarsa di sini. Batch akan otomatis tampil di halaman Batch & Lot setelah stok awal disimpan.',
    ])
</x-settings-shell>
