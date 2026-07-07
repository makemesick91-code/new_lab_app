<x-settings-shell title="Buat Tagihan RME">
    @php
        $oldItems = old('items', [['treatment_id' => '', 'description' => '', 'qty' => 1, 'unit_price' => '', 'discount' => '']]);
        $mrStatusTone = [
            'FINAL'           => 'success',
            'CASHIER_PENDING' => 'warning',
            'DRAFT'           => 'warning',
        ];
    @endphp

    <div class="space-y-6">

        {{-- Page Header --}}
        <x-ui.page-header
            title="Buat Tagihan RME"
            subtitle="Input tindakan final dan total biaya berdasarkan hasil pemeriksaan dokter.">
            <x-slot:breadcrumb>Rekam Medis Elektronik</x-slot:breadcrumb>
            <x-slot:actions>
                <x-ui.button variant="secondary" :href="route('rme.cashier.index')">&larr; Kembali ke Daftar</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        {{-- Visit & clinical summary for cashier billing --}}
        @include('rme.cashier.partials.clinical-summary', ['visit' => $visit])

        {{-- Billing Form --}}
        <form method="POST" action="{{ route('rme.cashier.store', $visit) }}" id="billing-form">
            @csrf

            <x-ui.card title="Item Tagihan" class="space-y-6">

                <x-ui.alert variant="info" class="text-xs">
                    Tindakan dapat dipilih dari master <span class="font-medium">Treatment</span> atau diketik manual.
                    Kosongkan dropdown Treatment dan isi kolom <span class="font-medium">Tindakan / Deskripsi</span>
                    untuk tindakan manual. Setiap baris boleh memakai <span class="font-medium">treatment master</span> atau
                    <span class="font-medium">nama tindakan manual</span>.
                </x-ui.alert>

                <div id="items-container" class="space-y-3">
                    @foreach ($oldItems as $idx => $item)
                        <div class="item-row grid grid-cols-12 gap-2 items-start border border-hairline rounded-md p-3">
                            <div class="col-span-3">
                                <label class="block text-xs text-ink-soft mb-1">Treatment <span class="text-ink-muted">(opsional)</span></label>
                                <select name="items[{{ $idx }}][treatment_id]"
                                    class="treatment-select w-full rounded-md border-hairline shadow-sm text-sm focus:border-brand-500 focus:ring-brand-500">
                                    <option value="">— Manual / Pilih Treatment —</option>
                                    @foreach ($treatments as $treatment)
                                        <option value="{{ $treatment->id }}"
                                            data-name="{{ $treatment->name }}"
                                            {{ ($item['treatment_id'] ?? '') == $treatment->id ? 'selected' : '' }}>
                                            {{ $treatment->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-span-3">
                                <label class="block text-xs text-ink-soft mb-1">Tindakan / Deskripsi <span class="text-danger">*</span></label>
                                <input type="text" name="items[{{ $idx }}][description]"
                                    value="{{ $item['description'] ?? '' }}"
                                    placeholder="Nama tindakan (manual atau dari treatment)"
                                    class="description-input w-full rounded-md border-hairline shadow-sm text-sm focus:border-brand-500 focus:ring-brand-500"
                                    required>
                            </div>
                            <div class="col-span-1">
                                <label class="block text-xs text-ink-soft mb-1">Qty <span class="text-danger">*</span></label>
                                <input type="number" name="items[{{ $idx }}][qty]"
                                    value="{{ $item['qty'] ?? 1 }}"
                                    min="1" class="qty-input w-full rounded-md border-hairline shadow-sm text-sm focus:border-brand-500 focus:ring-brand-500"
                                    required>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-xs text-ink-soft mb-1">Harga Satuan <span class="text-danger">*</span></label>
                                <input type="number" name="items[{{ $idx }}][unit_price]"
                                    value="{{ $item['unit_price'] ?? '' }}"
                                    min="0" step="1" class="price-input w-full rounded-md border-hairline shadow-sm text-sm focus:border-brand-500 focus:ring-brand-500"
                                    required>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-xs text-ink-soft mb-1">Diskon</label>
                                <input type="number" name="items[{{ $idx }}][discount]"
                                    value="{{ $item['discount'] ?? 0 }}"
                                    min="0" step="1" class="discount-input w-full rounded-md border-hairline shadow-sm text-sm focus:border-brand-500 focus:ring-brand-500">
                            </div>
                            <div class="col-span-1 flex items-end pb-0.5">
                                <button type="button" onclick="removeItem(this)"
                                    class="inline-flex items-center justify-center w-full rounded-lg px-2 py-2 text-xs font-semibold transition-colors bg-danger-700 text-white hover:bg-danger-700 focus:outline-none focus:ring-2 focus:ring-danger focus:ring-offset-2">
                                    Hapus
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>

                <x-ui.button type="button" onclick="addItem()" variant="secondary">+ Tambah Item</x-ui.button>

                {{-- Totals --}}
                <div class="border-t border-hairline pt-4 space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-ink-soft">Subtotal</span>
                        <span id="display-subtotal" class="font-medium text-navy">Rp 0</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-ink-soft">Total Diskon</span>
                        <span id="display-discount" class="font-medium text-danger-700">Rp 0</span>
                    </div>
                    <div class="flex justify-between text-base font-semibold border-t border-hairline pt-2">
                        <span class="text-navy">Grand Total</span>
                        <span id="display-grand-total" class="text-brand-700">Rp 0</span>
                    </div>
                </div>

                {{-- Notes --}}
                <div>
                    <label class="block text-sm font-medium text-ink mb-1">Catatan</label>
                    <textarea name="notes" rows="3"
                        class="w-full rounded-md border-hairline shadow-sm text-sm focus:border-brand-500 focus:ring-brand-500"
                        placeholder="Catatan opsional...">{{ old('notes') }}</textarea>
                </div>

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit" variant="primary">Simpan Tagihan</x-ui.button>
                    <x-ui.button variant="neutral" :href="route('rme.cashier.index')">Batal</x-ui.button>
                </div>

            </x-ui.card>
        </form>
    </div>

    <script>
        let itemIndex = {{ count($oldItems) }};

        function formatRupiah(value) {
            return 'Rp ' + Math.max(0, value).toLocaleString('id-ID');
        }

        function recalculate() {
            let subtotal = 0;
            let discountTotal = 0;
            document.querySelectorAll('.item-row').forEach(function(row) {
                const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
                const price = parseFloat(row.querySelector('.price-input').value) || 0;
                const discount = parseFloat(row.querySelector('.discount-input').value) || 0;
                subtotal += qty * price;
                discountTotal += discount;
            });
            document.getElementById('display-subtotal').textContent = formatRupiah(subtotal);
            document.getElementById('display-discount').textContent = formatRupiah(discountTotal);
            document.getElementById('display-grand-total').textContent = formatRupiah(subtotal - discountTotal);
        }

        function addItem() {
            const container = document.getElementById('items-container');
            const row = document.createElement('div');
            row.className = 'item-row grid grid-cols-12 gap-2 items-start border border-hairline rounded-md p-3';
            row.innerHTML = `
                <div class="col-span-3">
                    <label class="block text-xs text-ink-soft mb-1">Treatment <span class="text-ink-muted">(opsional)</span></label>
                    <select name="items[${itemIndex}][treatment_id]" class="treatment-select w-full rounded-md border-hairline shadow-sm text-sm focus:border-brand-500 focus:ring-brand-500" onchange="fillDescription(this)">
                        <option value="">— Manual / Pilih Treatment —</option>
                        @foreach ($treatments as $treatment)
                        <option value="{{ $treatment->id }}" data-name="{{ $treatment->name }}">{{ $treatment->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-span-3">
                    <label class="block text-xs text-ink-soft mb-1">Tindakan / Deskripsi <span class="text-danger">*</span></label>
                    <input type="text" name="items[${itemIndex}][description]" placeholder="Nama tindakan (manual atau dari treatment)" class="description-input w-full rounded-md border-hairline shadow-sm text-sm focus:border-brand-500 focus:ring-brand-500" required>
                </div>
                <div class="col-span-1">
                    <label class="block text-xs text-ink-soft mb-1">Qty <span class="text-danger">*</span></label>
                    <input type="number" name="items[${itemIndex}][qty]" value="1" min="1" class="qty-input w-full rounded-md border-hairline shadow-sm text-sm focus:border-brand-500 focus:ring-brand-500" required oninput="recalculate()">
                </div>
                <div class="col-span-2">
                    <label class="block text-xs text-ink-soft mb-1">Harga Satuan <span class="text-danger">*</span></label>
                    <input type="number" name="items[${itemIndex}][unit_price]" min="0" step="1" class="price-input w-full rounded-md border-hairline shadow-sm text-sm focus:border-brand-500 focus:ring-brand-500" required oninput="recalculate()">
                </div>
                <div class="col-span-2">
                    <label class="block text-xs text-ink-soft mb-1">Diskon</label>
                    <input type="number" name="items[${itemIndex}][discount]" value="0" min="0" step="1" class="discount-input w-full rounded-md border-hairline shadow-sm text-sm focus:border-brand-500 focus:ring-brand-500" oninput="recalculate()">
                </div>
                <div class="col-span-1 flex items-end pb-0.5">
                    <button type="button" onclick="removeItem(this)" class="inline-flex items-center justify-center w-full rounded-lg px-2 py-2 text-xs font-semibold transition-colors bg-danger-700 text-white hover:bg-danger-700 focus:outline-none focus:ring-2 focus:ring-danger focus:ring-offset-2">Hapus</button>
                </div>
            `;
            container.appendChild(row);
            itemIndex++;
        }

        function removeItem(btn) {
            const rows = document.querySelectorAll('.item-row');
            if (rows.length <= 1) return;
            btn.closest('.item-row').remove();
            recalculate();
        }

        function fillDescription(select) {
            const option = select.options[select.selectedIndex];
            const row = select.closest('.item-row');
            const descInput = row.querySelector('.description-input');
            if (option.value && descInput.value === '') {
                descInput.value = option.getAttribute('data-name');
            }
            recalculate();
        }

        document.querySelectorAll('.treatment-select').forEach(function(el) {
            el.addEventListener('change', function() { fillDescription(this); });
        });
        document.querySelectorAll('.qty-input, .price-input, .discount-input').forEach(function(el) {
            el.addEventListener('input', recalculate);
        });

        recalculate();
    </script>
</x-settings-shell>
