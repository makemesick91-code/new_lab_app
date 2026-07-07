<x-settings-shell title="Buat Stok Opname">
    <div class="space-y-6">
        <x-ui.page-header title="Buat Sesi Stok Opname" subtitle="Mulai penghitungan fisik untuk lokasi persediaan tertentu.">
            <x-slot:breadcrumb>Persediaan / Stok Opname</x-slot:breadcrumb>
        </x-ui.page-header>

        <x-ui.card>
            <form method="POST" action="{{ route('inventory.stock-opnames.store') }}">
                @csrf
                <div class="grid gap-6 md:grid-cols-2">
                    <x-ui.select label="Lokasi Persediaan" name="inventory_location_id" id="inventory-location" required>
                        <option value="">Pilih lokasi</option>
                        @foreach ($locations as $location)
                            <option value="{{ $location->id }}" @selected(old('inventory_location_id') == $location->id)>{{ $location->name }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.input label="Tanggal Opname" name="opname_date" id="opname-date" type="date" required :value="old('opname_date', now()->toDateString())" />

                    <div class="md:col-span-2">
                        <label for="product-ids" class="block text-sm font-medium text-navy">Produk yang Dihitung</label>
                        <x-inventory.searchable-product-select
                            id="product-ids"
                            name="product_ids[]"
                            :products="$products"
                            :selected="old('product_ids', [])"
                            class="mt-1"
                            multiple
                            :allow-empty="true"
                        />
                        <p class="mt-1 text-xs text-ink-soft">Biarkan kosong untuk menambahkan produk nanti, atau pilih beberapa produk untuk dihitung.</p>
                        @error('product_ids')
                            <p class="mt-1 text-sm text-danger">{{ $message }}</p>
                        @enderror
                        @error('product_ids.*')
                            <p class="mt-1 text-sm text-danger">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="md:col-span-2">
                        <x-ui.textarea label="Catatan" name="notes" id="notes" :rows="3" :value="old('notes')" />
                    </div>
                </div>

                <div class="mt-6 flex flex-wrap items-center gap-3 border-t border-hairline pt-4">
                    <x-ui.button variant="secondary" :href="route('inventory.stock-opnames.index')">Batal</x-ui.button>
                    <x-ui.button type="submit">Buat Stok Opname</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-settings-shell>
