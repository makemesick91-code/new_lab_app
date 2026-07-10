<x-settings-shell title="Buat Permintaan Lab">
    <x-ui.page-header title="Buat Permintaan Lab" subtitle="Foto SPK dan foto model wajib sebelum permintaan pickup.">
        <x-slot:breadcrumb>Lab / Permintaan Cabang / Buat</x-slot:breadcrumb>
        <x-slot:actions>
            <x-ui.button :href="route('lab-workflow-requests.index')" variant="secondary">Kembali</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($errors->any())
        <x-ui.alert variant="danger" class="mb-4">
            <ul class="list-disc pl-4">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    @if ($branch === null)
        {{-- Klinik = Cabang RME: without an active RME-enabled branch context the
             request cannot be created (server-side enforced — this is only the UX). --}}
        <x-ui.alert variant="warning">
            Akun Anda belum terhubung ke Cabang RME aktif, sehingga permintaan lab belum dapat dibuat.
            Hubungi admin untuk menghubungkan akun Anda ke Cabang RME.
        </x-ui.alert>
    @else
        <form method="POST" action="{{ route('lab-workflow-requests.store') }}" enctype="multipart/form-data"
            x-data="{ items: {{ Illuminate\Support\Js::from(old('items', [['lab_service_id' => '', 'tooth_number' => '', 'quantity' => 1, 'unit_price' => 0]])) }} }">
            @csrf

            <x-ui.card title="Data Order">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        {{-- Klinik = Cabang RME aktif, terkunci ke cabang user. branch_id
                             tidak pernah dipercaya dari request (server-side override). --}}
                        <x-ui.input name="branch_display" label="Klinik (Cabang RME)" readonly
                            :value="$branch->name.' ('.$branch->code.')'"
                            help="Cabang RME aktif Anda. Tidak dapat diubah dari formulir ini." />
                    </div>
                    <div>
                        <label for="doctor-select" class="mb-1.5 block text-sm font-medium text-navy">Dokter <span class="text-danger">*</span></label>
                        <x-inventory.searchable-product-select
                            id="doctor-select"
                            name="doctor_id"
                            :products="$doctors"
                            placeholder="Cari nama dokter…"
                            empty-label="Pilih dokter"
                            not-found-label="Dokter tidak ditemukan untuk cabang ini."
                            clear-label="Hapus pilihan dokter"
                            required
                        />
                        @error('doctor_id')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="patient-select" class="mb-1.5 block text-sm font-medium text-navy">Pasien <span class="text-danger">*</span></label>
                        <x-inventory.searchable-product-select
                            id="patient-select"
                            name="patient_id"
                            :products="$patients"
                            placeholder="Cari nomor RM atau nama pasien…"
                            empty-label="Pilih pasien"
                            not-found-label="Pasien tidak ditemukan untuk cabang ini."
                            clear-label="Hapus pilihan pasien"
                            required
                        />
                        @error('patient_id')<p class="mt-1 text-xs text-danger-700">{{ $message }}</p>@enderror
                    </div>
                    <x-ui.input name="medical_record_number" label="No. Rekam Medis (opsional)" :value="old('medical_record_number')" />
                    <x-ui.input type="date" name="order_date" label="Tanggal Order" :value="old('order_date', now()->toDateString())" />
                    <x-ui.input type="date" name="due_date" label="Tenggat" :value="old('due_date')" required />
                    <x-ui.select name="priority" label="Prioritas" required>
                        @foreach (['NORMAL' => 'Normal', 'URGENT' => 'Mendesak', 'SUPER_URGENT' => 'Sangat Mendesak'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('priority', 'NORMAL') === $value)>{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
                <div class="mt-4">
                    <x-ui.textarea name="notes" label="Catatan Pickup / Order (opsional)" rows="3">{{ old('notes') }}</x-ui.textarea>
                </div>
            </x-ui.card>

            <x-ui.card title="Foto Bukti (Wajib)" class="mt-4">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink">Foto SPK <span class="text-danger-700">*</span></label>
                        <input type="file" name="spk_photo" accept="image/jpeg,image/png,image/webp" capture="environment" required
                            class="block w-full rounded-lg border border-hairline text-sm text-ink-soft file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-brand-700" />
                        <p class="mt-1 text-xs text-ink-muted">JPG/PNG/WebP, maks 10 MB.</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-ink">Foto Model <span class="text-danger-700">*</span></label>
                        <input type="file" name="model_photo" accept="image/jpeg,image/png,image/webp" capture="environment" required
                            class="block w-full rounded-lg border border-hairline text-sm text-ink-soft file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-brand-700" />
                        <p class="mt-1 text-xs text-ink-muted">JPG/PNG/WebP, maks 10 MB.</p>
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card title="Item Pekerjaan" class="mt-4">
                <template x-for="(item, index) in items" :key="index">
                    <div class="mb-3 grid gap-3 rounded-lg border border-hairline p-3 md:grid-cols-6">
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-sm font-medium text-ink">Layanan Lab <span class="text-danger-700">*</span></label>
                            <x-inventory.searchable-product-select
                                :products="$labServices"
                                alpine-name="'items[' + index + '][lab_service_id]'"
                                model="item.lab_service_id"
                                placeholder="Cari kode atau nama layanan…"
                                empty-label="Pilih layanan"
                                not-found-label="Layanan lab tidak ditemukan."
                                clear-label="Hapus pilihan layanan"
                                required
                            />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-ink">Gigi</label>
                            <input type="text" :name="`items[${index}][tooth_number]`" x-model="item.tooth_number" class="block w-full rounded-lg border border-hairline px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-ink">Jumlah</label>
                            <input type="number" :name="`items[${index}][quantity]`" x-model="item.quantity" min="1" required class="block w-full rounded-lg border border-hairline px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-ink">Harga Satuan</label>
                            <input type="number" :name="`items[${index}][unit_price]`" x-model="item.unit_price" min="0" step="0.01" required class="block w-full rounded-lg border border-hairline px-3 py-2 text-sm" />
                        </div>
                        <div class="flex items-end">
                            <button type="button" x-show="items.length > 1" @click="items.splice(index, 1)"
                                class="rounded-lg border border-danger-100 px-3 py-2 text-sm text-danger-700">Hapus</button>
                        </div>
                    </div>
                </template>
                <x-ui.button type="button" variant="secondary" size="sm" @click="items.push({ lab_service_id: '', tooth_number: '', quantity: 1, unit_price: 0 })">+ Tambah Item</x-ui.button>
            </x-ui.card>

            <div class="mt-4 flex justify-end gap-2">
                <x-ui.button :href="route('lab-workflow-requests.index')" variant="secondary">Batal</x-ui.button>
                <x-ui.button type="submit">Simpan Permintaan</x-ui.button>
            </div>
        </form>
    @endif
</x-settings-shell>
