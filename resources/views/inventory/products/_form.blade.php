@php($product = $product ?? null)

@if ($categories->isEmpty() || $units->isEmpty())
    <x-ui.alert variant="warning" class="mb-4">
        @if ($categories->isEmpty())
            <p class="font-medium">Tambahkan Kategori Produk terlebih dahulu.</p>
        @endif
        @if ($units->isEmpty())
            <p @class(['font-medium' => $categories->isEmpty()])>Tambahkan Satuan Produk terlebih dahulu.</p>
        @endif
    </x-ui.alert>
@endif

<div class="grid gap-4 sm:grid-cols-2">
    <x-ui.input label="Kode" name="code" :value="old('code', $product?->code)" required />
    <x-ui.input label="Nama" name="name" :value="old('name', $product?->name)" required />

    <x-ui.select label="Kategori" name="product_category_id" required>
        <option value="">Pilih kategori</option>
        @foreach ($categories as $category)
            <option value="{{ $category->id }}" @selected(old('product_category_id', $product?->product_category_id) == $category->id)>{{ $category->name }}</option>
        @endforeach
    </x-ui.select>

    <x-ui.select label="Satuan" name="product_unit_id" required>
        <option value="">Pilih satuan</option>
        @foreach ($units as $unit)
            <option value="{{ $unit->id }}" @selected(old('product_unit_id', $product?->product_unit_id) == $unit->id)>{{ $unit->name }} ({{ $unit->symbol }})</option>
        @endforeach
    </x-ui.select>

    <x-ui.input label="Biaya Rata-rata" name="average_cost" type="number" step="0.01" min="0" :value="old('average_cost', $product?->average_cost ?? 0)" />

    <div class="flex items-center pt-6">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $product?->is_active ?? true)) class="rounded border-hairline text-brand-600 focus:ring-brand-500">
        <label for="is_active" class="ml-2 text-sm text-navy">Aktif</label>
    </div>

    <div class="sm:col-span-2 rounded-lg border border-hairline bg-navy-50 p-4">
        <h3 class="text-sm font-semibold text-navy">Pengaturan Peringatan &amp; Pesanan Ulang</h3>
        <p class="mt-1 text-xs text-ink-soft">Stok saat ini tetap dihitung dari ledger pergerakan. Nilai di bawah hanya mengatur kapan peringatan muncul.</p>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <x-ui.input label="Stok Minimum" name="minimum_stock" type="number" step="0.0001" min="0" :value="old('minimum_stock', $product?->minimum_stock ?? 0)" help="Digunakan sebagai titik pesan ulang jika titik pesan ulang kosong atau nol." />
            <x-ui.input label="Titik Pesan Ulang" name="reorder_point" type="number" step="0.0001" min="0" :value="old('reorder_point', $product?->reorder_point ?? 0)" help="Stok turun ke level ini → tindakan pesan ulang disarankan." />
            <x-ui.input label="Jumlah Pesan Ulang" name="reorder_quantity" type="number" step="0.0001" min="0" :value="old('reorder_quantity', $product?->reorder_quantity ?? 0)" help="Saran jumlah saat titik pesan ulang tercapai (informasi saja)." />
            <div class="flex items-center pt-6">
                <input type="hidden" name="alert_enabled" value="0">
                <input type="checkbox" id="alert_enabled" name="alert_enabled" value="1" @checked(old('alert_enabled', $product?->alert_enabled ?? true)) class="rounded border-hairline text-brand-600 focus:ring-brand-500">
                <label for="alert_enabled" class="ml-2 text-sm text-navy">Aktifkan peringatan</label>
            </div>
        </div>
    </div>

    <div class="sm:col-span-2">
        <x-ui.textarea label="Deskripsi" name="description" :rows="3" :value="old('description', $product?->description)" />
    </div>
</div>
