<x-settings-shell title="Penyesuaian Masuk">
    @include('inventory.stock._operation-form', [
        'action' => route('inventory.products.adjust-in.store', $product),
        'button' => 'Buat Penyesuaian Masuk',
        'operationType' => 'adjust_in',
        'includeBatch' => true,
        'batches' => $batches ?? collect(),
        'batchHelp' => 'Pilih "Buat Batch Baru" untuk mengisi nomor batch/lot dan tanggal kedaluwarsa. Batch akan tampil di halaman Batch & Lot setelah penyesuaian disimpan.',
    ])
</x-settings-shell>
