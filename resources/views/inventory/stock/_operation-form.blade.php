@php
    $includeCost = $includeCost ?? false;
    $includeSupplier = $includeSupplier ?? false;
    $operationType = $operationType ?? 'opening';
    $locations = collect($locations ?? []);
    $suppliers = collect($suppliers ?? []);
    $hasLocations = $locations->isNotEmpty();

    $operationCopy = [
        'opening' => [
            'eyebrow' => 'Stok Awal',
            'title' => 'Buat Entri Ledger Awal',
            'description' => 'Catat jumlah awal produk ini pada satu Lokasi Persediaan.',
            'bannerTone' => 'info',
            'bannerTitle' => 'Stok Awal membuat pergerakan ledger awal.',
            'bannerText' => 'Gunakan ini hanya untuk setup atau inisialisasi stok yang disetujui. Ini menambah jumlah ke lokasi yang dipilih.',
            'locationHelp' => 'Pilih lokasi fisik stok awal berada.',
            'quantityHelp' => 'Isi jumlah awal. Harus lebih dari 0.',
            'costHelp' => 'Isi biaya per unit stok awal jika diketahui. Gunakan 0 hanya jika biaya tidak dicatat.',
            'notesHelp' => 'Tambahkan referensi setup atau catatan persetujuan jika ada.',
            'buttonTone' => 'teal',
        ],
        'receive' => [
            'eyebrow' => 'Terima Stok',
            'title' => 'Terima Stok ke Lokasi',
            'description' => 'Catat stok masuk dari pemasok (opsional) ke Lokasi Persediaan tertentu.',
            'bannerTone' => 'success',
            'bannerTitle' => 'Terima Stok menambah jumlah ledger.',
            'bannerText' => 'Pemasok dan biaya per unit membantu audit dan finance, meskipun pemasok bersifat opsional.',
            'locationHelp' => 'Stok hanya ditambahkan ke lokasi yang dipilih.',
            'quantityHelp' => 'Isi jumlah diterima. Harus lebih dari 0.',
            'costHelp' => 'Isi biaya per unit pemasok jika diketahui. Gunakan 0 hanya jika biaya tidak tersedia.',
            'notesHelp' => 'Tambahkan surat jalan, nomor invoice, atau konteks penerimaan.',
            'buttonTone' => 'emerald',
        ],
        'adjust_in' => [
            'eyebrow' => 'Penyesuaian Masuk',
            'title' => 'Tambah Stok sebagai Koreksi',
            'description' => 'Catat koreksi stok positif tanpa konteks pembelian pemasok.',
            'bannerTone' => 'warning',
            'bannerTitle' => 'Penyesuaian Masuk untuk koreksi stok.',
            'bannerText' => 'Gunakan Terima Stok untuk penerimaan dari pemasok. Catatan penyesuaian perlu menjelaskan alasan koreksi.',
            'locationHelp' => 'Pilih lokasi tempat stok hasil koreksi bertambah.',
            'quantityHelp' => 'Isi jumlah koreksi. Harus lebih dari 0.',
            'costHelp' => null,
            'notesHelp' => 'Jelaskan alasan koreksi ini.',
            'buttonTone' => 'teal',
        ],
        'adjust_out' => [
            'eyebrow' => 'Penyesuaian Keluar',
            'title' => 'Kurangi Stok sebagai Koreksi',
            'description' => 'Catat koreksi stok negatif pada satu Lokasi Persediaan.',
            'bannerTone' => 'danger',
            'bannerTitle' => 'Penyesuaian Keluar mengurangi stok dan perlu kehati-hatian.',
            'bannerText' => 'Sistem menolak aksi ini jika stok lokasi tidak mencukupi. Pastikan lokasi sebelum mengirim.',
            'locationHelp' => 'Stok hanya dikurangi dari lokasi yang dipilih.',
            'quantityHelp' => 'Isi jumlah pengurangan. Harus lebih dari 0 dan tidak boleh melebihi stok lokasi.',
            'costHelp' => null,
            'notesHelp' => 'Jelaskan alasan pengurangan stok.',
            'buttonTone' => 'amber',
        ],
    ][$operationType] ?? [
        'eyebrow' => 'Pergerakan Persediaan',
        'title' => 'Catat Pergerakan Persediaan',
        'description' => 'Catat pergerakan stok ke ledger.',
        'bannerTone' => 'info',
        'bannerTitle' => 'Stok berbasis ledger.',
        'bannerText' => 'Setiap pergerakan mengubah stok terhitung melalui ledger.',
        'locationHelp' => 'Pilih lokasi fisik untuk pergerakan stok ini.',
        'quantityHelp' => 'Jumlah harus lebih dari 0.',
        'costHelp' => null,
        'notesHelp' => 'Tambahkan catatan pergerakan jika ada.',
        'buttonTone' => 'teal',
    ];

    $bannerClasses = [
        'info' => 'border-sky-200 bg-sky-50 text-sky-800',
        'success' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-800',
        'danger' => 'border-rose-200 bg-rose-50 text-rose-800',
    ][$operationCopy['bannerTone']];

    $buttonClasses = [
        'teal' => 'bg-teal-700 hover:bg-teal-600 focus:ring-teal-500',
        'emerald' => 'bg-emerald-700 hover:bg-emerald-600 focus:ring-emerald-500',
        'amber' => 'bg-amber-600 hover:bg-amber-500 focus:ring-amber-500',
    ][$operationCopy['buttonTone']];
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">{{ $operationCopy['eyebrow'] }}</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">{{ $operationCopy['title'] }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ $operationCopy['description'] }}</p>
        </div>
        <a href="{{ route('inventory.products.show', $product) }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
            Batal
        </a>
    </div>

    <div class="grid gap-6 lg:grid-cols-[20rem_minmax(0,1fr)]">
        <aside class="space-y-4">
            <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Panel Ringkasan Produk</p>
                <h3 class="mt-2 text-lg font-semibold text-gray-900">{{ $product->name }}</h3>
                <p class="mt-1 text-sm text-gray-500">{{ $product->code }} - {{ $product->category?->name ?? 'Tanpa kategori' }}</p>

                <dl class="mt-5 space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-gray-500">Satuan</dt>
                        <dd class="font-semibold text-gray-900">{{ $product->unit?->symbol ?? '-' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-gray-500">Stok Minimum</dt>
                        <dd class="font-semibold tabular-nums text-gray-900">{{ format_quantity_id($product->minimum_stock) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-gray-500">Biaya Rata-rata</dt>
                        <dd class="font-semibold tabular-nums text-gray-900">{{ format_currency_id($product->average_cost) }}</dd>
                    </div>
                </dl>
            </section>

            <section class="rounded-lg border border-teal-100 bg-teal-50 p-5 text-sm text-teal-800">
                <p class="font-semibold text-teal-900">Stok berbasis ledger</p>
                <p class="mt-1">Form ini membuat satu pergerakan persediaan. Stok saat ini dihitung dari seluruh pergerakan ledger, bukan disimpan sebagai nilai stok mutable pada produk.</p>
            </section>
        </aside>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-200 p-5">
                <div class="rounded-lg border p-4 text-sm {{ $bannerClasses }}">
                    <p class="font-semibold">{{ $operationCopy['bannerTitle'] }}</p>
                    <p class="mt-1">{{ $operationCopy['bannerText'] }}</p>
                </div>
            </div>

            @if (! $hasLocations)
                <div class="p-6">
                    <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 px-4 py-10 text-center">
                        <p class="text-sm font-semibold text-gray-900">Tidak ada Lokasi Persediaan aktif.</p>
                        <p class="mt-1 text-sm text-gray-500">Buat atau aktifkan Lokasi Persediaan sebelum mencatat pergerakan stok.</p>
                        <a href="{{ route('inventory.locations.index') }}" class="mt-4 inline-flex rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                            Buka Lokasi
                        </a>
                    </div>
                </div>
            @else
                <form method="POST" action="{{ $action }}" class="space-y-6 p-5">
                    @csrf

                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="md:col-span-2">
                            <label for="inventory_location_id" class="text-sm font-semibold text-gray-800">Lokasi Persediaan <span class="text-rose-600">*</span></label>
                            <select id="inventory_location_id" name="inventory_location_id" class="mt-2 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" required>
                                <option value="">Pilih lokasi stok fisik</option>
                                @foreach ($locations as $location)
                                    <option value="{{ $location->id }}" @selected(old('inventory_location_id') == $location->id)>
                                        {{ $location->name }}{{ $location->code ? ' ('.$location->code.')' : '' }} - {{ str_replace('_', ' ', $location->type) }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-gray-500">{{ $operationCopy['locationHelp'] }}</p>
                            @error('inventory_location_id')
                                <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>
                            @enderror

                            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                @foreach ($locations->take(4) as $location)
                                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                                        <p class="text-sm font-semibold text-gray-900">{{ $location->name }}</p>
                                        <p class="mt-0.5 text-xs text-gray-500">{{ $location->code ?: 'Tanpa kode' }} - {{ str_replace('_', ' ', $location->type) }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div>
                            <label for="quantity" class="text-sm font-semibold text-gray-800">Jumlah <span class="text-rose-600">*</span></label>
                            <input id="quantity" type="number" step="0.01" min="0.01" name="quantity" value="{{ old('quantity') }}" class="mt-2 block w-full rounded-lg border-gray-300 text-sm tabular-nums focus:border-teal-500 focus:ring-teal-500" required>
                            <p class="mt-1 text-xs text-gray-500">{{ $operationCopy['quantityHelp'] }}</p>
                            @error('quantity')
                                <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>
                            @enderror
                        </div>

                        @if ($includeCost)
                            <div>
                                <label for="unit_cost" class="text-sm font-semibold text-gray-800">Biaya per Unit</label>
                                <input id="unit_cost" type="number" step="0.01" min="0" name="unit_cost" value="{{ old('unit_cost', 0) }}" class="mt-2 block w-full rounded-lg border-gray-300 text-sm tabular-nums focus:border-teal-500 focus:ring-teal-500">
                                <p class="mt-1 text-xs text-gray-500">{{ $operationCopy['costHelp'] }}</p>
                                @error('unit_cost')
                                    <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>
                                @enderror
                            </div>
                        @endif

                        @if ($includeSupplier)
                            <div class="md:col-span-2">
                                <label for="supplier_id" class="text-sm font-semibold text-gray-800">Pemasok</label>
                                <select id="supplier_id" name="supplier_id" class="mt-2 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                                    <option value="">Tanpa pemasok</option>
                                    @foreach ($suppliers as $supplier)
                                        <option value="{{ $supplier->id }}" @selected(old('supplier_id') == $supplier->id)>{{ $supplier->name }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-gray-500">Opsional, namun berguna untuk audit penerimaan stok dan evaluasi pemasok di masa depan.</p>
                                @error('supplier_id')
                                    <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>
                                @enderror
                            </div>
                        @endif

                        <div class="md:col-span-2">
                            <label for="notes" class="text-sm font-semibold text-gray-800">Catatan / Alasan</label>
                            <textarea id="notes" name="notes" rows="4" class="mt-2 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">{{ old('notes') }}</textarea>
                            <p class="mt-1 text-xs text-gray-500">{{ $operationCopy['notesHelp'] }}</p>
                            @error('notes')
                                <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="flex flex-col-reverse gap-3 border-t border-gray-200 pt-5 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-xs text-gray-500">Tinjau produk, lokasi, jumlah, dan biaya sebelum mengirim. Ini akan membuat pergerakan ledger stok yang dapat diaudit.</p>
                        <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                            <a href="{{ route('inventory.products.show', $product) }}" class="inline-flex justify-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                                Batal
                            </a>
                            <button class="inline-flex justify-center rounded-lg px-4 py-2 text-sm font-semibold text-white focus:outline-none focus:ring-2 focus:ring-offset-2 {{ $buttonClasses }}">
                                {{ $button }}
                            </button>
                        </div>
                    </div>
                </form>
            @endif
        </section>
    </div>
</div>
