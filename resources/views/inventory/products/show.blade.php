@php
    $currentStock = (float) $currentStock;
    $minimumStock = (float) $product->minimum_stock;
    $averageCost = (float) $product->average_cost;
    $inventoryValue = $currentStock * $averageCost;
    $isOut = $currentStock <= 0;
    $isLow = ! $isOut && $currentStock <= $minimumStock;
@endphp

<x-settings-shell title="Detail Produk">
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">Kartu Ringkasan Produk</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">{{ $product->name }}</h2>
                <p class="mt-1 text-sm text-gray-500">Kode {{ $product->code }} · stok ditampilkan sebagai total cabang aktif dari pergerakan ledger.</p>
            </div>
            <x-ui.button variant="secondary" :href="route('inventory.products.index')">Kembali ke Produk</x-ui.button>
        </div>

        @if (! $product->is_active)
            <div role="status" class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
                <p class="font-semibold text-gray-900">Produk ini nonaktif.</p>
                <p class="mt-1">Operasi stok dinonaktifkan. Anda tetap bisa melihat kartu stok dan riwayat pergerakan ledger.</p>
            </div>
        @elseif ($isOut)
            <div role="alert" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
                <p class="font-semibold">Produk ini kehabisan stok di seluruh cabang aktif.</p>
                <p class="mt-1">Terima stok ke Lokasi Persediaan yang sesuai sebelum material ini digunakan untuk operasional.</p>
            </div>
        @elseif ($isLow)
            <div role="status" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                <p class="font-semibold">Produk ini berada di bawah stok minimum.</p>
                <p class="mt-1">Tinjau saldo per lokasi di kartu stok sebelum menerima atau menyesuaikan stok.</p>
            </div>
        @endif

        <x-ui.card>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        @include('inventory._status-badge', ['active' => $product->is_active])
                        @include('inventory._low-stock-badge', ['current' => $currentStock, 'minimum' => $minimumStock])
                    </div>
                    <h3 class="mt-3 text-lg font-semibold text-gray-900">{{ $product->name }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ $product->description ?: 'Belum ada deskripsi produk.' }}</p>
                </div>
                <div class="rounded-lg bg-brand-50 px-4 py-3 text-right">
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">Stok Saat Ini - Total Cabang</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-brand-800">{{ format_quantity_id($currentStock) }}</p>
                    <p class="mt-1 text-xs text-brand-700">{{ $product->unit?->symbol ?? 'satuan' }}</p>
                </div>
            </div>

            <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Kategori</dt>
                    <dd class="mt-1 font-semibold text-gray-900">{{ $product->category?->name ?? '-' }}</dd>
                </div>
                <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Satuan</dt>
                    <dd class="mt-1 font-semibold text-gray-900">{{ $product->unit?->symbol ?? '-' }}</dd>
                </div>
                <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Stok Minimum</dt>
                    <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ format_quantity_id($minimumStock) }}</dd>
                </div>
                <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Biaya Rata-rata</dt>
                    <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ format_currency_id($averageCost) }}</dd>
                </div>
            </dl>
        </x-ui.card>

        <x-ui.card>
            <h3 class="text-base font-semibold text-gray-900">Pengaturan Pesanan Ulang</h3>
            <p class="mt-1 text-sm text-gray-500">Konfigurasi peringatan stok untuk produk ini. Stok tetap dihitung dari ledger.</p>
            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Titik Pesan Ulang</dt>
                    <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ format_quantity_id((float) ($product->reorder_point ?? 0)) }}</dd>
                </div>
                <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Jumlah Pesan Ulang</dt>
                    <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ format_quantity_id((float) ($product->reorder_quantity ?? 0)) }}</dd>
                </div>
                <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Peringatan Aktif</dt>
                    <dd class="mt-1 font-semibold text-gray-900">{{ $product->alert_enabled ? 'Ya' : 'Tidak' }}</dd>
                </div>
                <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Stok Minimum (fallback)</dt>
                    <dd class="mt-1 font-semibold tabular-nums text-gray-900">{{ format_quantity_id($minimumStock) }}</dd>
                </div>
            </dl>
        </x-ui.card>

        <div class="grid gap-6 lg:grid-cols-3">
            <x-ui.card class="lg:col-span-2">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">Kejelasan Stok Cabang / Lokasi</h3>
                        <p class="mt-1 text-sm text-gray-500">Halaman ini menampilkan total stok cabang. Gunakan kartu stok untuk melihat riwayat pergerakan dan filter berdasarkan Lokasi Persediaan.</p>
                    </div>
                    <x-ui.button variant="primary" :href="route('inventory.products.stock-card', $product)" class="!px-3 !py-1.5 !text-xs">Buka Kartu Stok</x-ui.button>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg border border-gray-200 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Lingkup Ledger</p>
                        <p class="mt-2 text-sm font-medium text-gray-900">Cabang aktif</p>
                        <p class="mt-1 text-xs text-gray-500">Menggabungkan seluruh lokasi persediaan untuk produk ini.</p>
                    </div>
                    <div class="rounded-lg border border-gray-200 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Nilai Persediaan</p>
                        <p class="mt-2 text-lg font-semibold tabular-nums text-gray-900">{{ format_currency_id($inventoryValue) }}</p>
                        <p class="mt-1 text-xs text-gray-500">Stok saat ini × biaya rata-rata.</p>
                    </div>
                    <div class="rounded-lg border border-gray-200 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Detail Lokasi</p>
                        <p class="mt-2 text-sm font-medium text-gray-900">Filter di Kartu Stok</p>
                        <p class="mt-1 text-xs text-gray-500">Saldo per lokasi tetap berbasis ledger.</p>
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card>
                <h3 class="text-base font-semibold text-gray-900">Aksi Stok</h3>
                <p class="mt-1 text-sm text-gray-500">Setiap operasi stok wajib memilih Lokasi Persediaan.</p>
                <div class="mt-4 grid gap-2">
                    <x-ui.button variant="secondary" :href="route('inventory.products.stock-card', $product)" class="w-full">Kartu Stok</x-ui.button>
                    @if ($product->is_active)
                        <x-ui.button variant="success" :href="route('inventory.products.receive-stock.create', $product)" class="w-full">Terima Stok</x-ui.button>
                        <x-ui.button variant="secondary" :href="route('inventory.products.opening-stock.create', $product)" class="w-full">Stok Awal</x-ui.button>
                        <x-ui.button variant="secondary" :href="route('inventory.products.adjust-in.create', $product)" class="w-full">Penyesuaian Masuk</x-ui.button>
                        <x-ui.button variant="warning" :href="route('inventory.products.adjust-out.create', $product)" class="w-full">Penyesuaian Keluar</x-ui.button>
                    @else
                        <div class="rounded-lg border border-hairline bg-navy-50 p-3 text-sm text-ink-soft">
                            Operasi stok dinonaktifkan untuk produk nonaktif.
                        </div>
                    @endif
                    <x-ui.button variant="neutral" :href="route('inventory.products.edit', $product)" class="w-full">Ubah Produk</x-ui.button>
                </div>
            </x-ui.card>
        </div>
    </div>
</x-settings-shell>
