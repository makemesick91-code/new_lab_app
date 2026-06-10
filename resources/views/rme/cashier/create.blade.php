<x-settings-shell title="Buat Tagihan RME">
    <div class="space-y-6">

        {{-- Visit & Patient Summary --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h3 class="text-base font-semibold text-gray-900 mb-4">Informasi Kunjungan</h3>
            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <div>
                    <dt class="text-gray-500">No. Kunjungan</dt>
                    <dd class="font-mono font-medium text-gray-900">{{ $visit->visit_number }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Tanggal</dt>
                    <dd class="text-gray-900">{{ $visit->visit_date?->format('d/m/Y') }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Pasien</dt>
                    <dd class="text-gray-900 font-medium">{{ $visit->patient?->name }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Dokter</dt>
                    <dd class="text-gray-900">{{ $visit->doctor?->name }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Layanan Awal (Admin)</dt>
                    <dd class="text-gray-600">{{ $visit->initialTreatment?->name ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Catatan Layanan</dt>
                    <dd class="text-gray-600">{{ $visit->initial_service_note ?? '-' }}</dd>
                </div>
                @if ($visit->medicalRecord)
                    <div>
                        <dt class="text-gray-500">Status RME</dt>
                        <dd>
                            <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium bg-green-50 text-green-700">
                                {{ strtoupper($visit->medicalRecord->status) }}
                            </span>
                        </dd>
                    </div>
                    @if ($visit->medicalRecord->handwriting)
                        <div>
                            <dt class="text-gray-500">Catatan Klinis Dokter</dt>
                            <dd class="text-gray-600 text-xs italic">{{ Str::limit($visit->medicalRecord->handwriting->content ?? '', 120) }}</dd>
                        </div>
                    @endif
                @endif
            </dl>
        </div>

        {{-- Billing Form --}}
        <form method="POST" action="{{ route('rme.cashier.store', $visit) }}" id="billing-form">
            @csrf

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-6">
                <h3 class="text-base font-semibold text-gray-900">Item Tagihan</h3>

                <div id="items-container" class="space-y-3">
                    {{-- Item rows injected by JS or rendered from old() --}}
                    @php
                        $oldItems = old('items', [['treatment_id' => '', 'description' => '', 'qty' => 1, 'unit_price' => '', 'discount' => '']]);
                    @endphp
                    @foreach ($oldItems as $idx => $item)
                        <div class="item-row grid grid-cols-12 gap-2 items-start border border-gray-200 rounded-md p-3">
                            <div class="col-span-3">
                                <label class="block text-xs text-gray-500 mb-1">Treatment</label>
                                <select name="items[{{ $idx }}][treatment_id]"
                                    class="treatment-select w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">— Pilih Treatment —</option>
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
                                <label class="block text-xs text-gray-500 mb-1">Deskripsi <span class="text-red-500">*</span></label>
                                <input type="text" name="items[{{ $idx }}][description]"
                                    value="{{ $item['description'] ?? '' }}"
                                    class="description-input w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    required>
                            </div>
                            <div class="col-span-1">
                                <label class="block text-xs text-gray-500 mb-1">Qty <span class="text-red-500">*</span></label>
                                <input type="number" name="items[{{ $idx }}][qty]"
                                    value="{{ $item['qty'] ?? 1 }}"
                                    min="1" class="qty-input w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    required>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-xs text-gray-500 mb-1">Harga Satuan <span class="text-red-500">*</span></label>
                                <input type="number" name="items[{{ $idx }}][unit_price]"
                                    value="{{ $item['unit_price'] ?? '' }}"
                                    min="0" step="1" class="price-input w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    required>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-xs text-gray-500 mb-1">Diskon</label>
                                <input type="number" name="items[{{ $idx }}][discount]"
                                    value="{{ $item['discount'] ?? 0 }}"
                                    min="0" step="1" class="discount-input w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                            <div class="col-span-1 flex items-end">
                                <button type="button" onclick="removeItem(this)"
                                    class="w-full py-2 text-xs text-red-600 hover:text-red-500 border border-red-200 rounded-md">
                                    Hapus
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>

                <button type="button" onclick="addItem()"
                    class="inline-flex items-center px-3 py-2 text-sm text-indigo-600 border border-indigo-300 rounded-md hover:bg-indigo-50">
                    + Tambah Item
                </button>

                {{-- Totals --}}
                <div class="border-t border-gray-200 pt-4 space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-600">Subtotal</span>
                        <span id="display-subtotal" class="font-medium text-gray-900">Rp 0</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Total Diskon</span>
                        <span id="display-discount" class="font-medium text-red-600">Rp 0</span>
                    </div>
                    <div class="flex justify-between text-base font-semibold border-t border-gray-200 pt-2">
                        <span class="text-gray-900">Grand Total</span>
                        <span id="display-grand-total" class="text-indigo-700">Rp 0</span>
                    </div>
                </div>

                {{-- Notes --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Catatan</label>
                    <textarea name="notes" rows="3"
                        class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"
                        placeholder="Catatan opsional...">{{ old('notes') }}</textarea>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                        class="inline-flex items-center px-6 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-500">
                        Simpan Tagihan
                    </button>
                    <a href="{{ route('rme.cashier.index') }}"
                        class="text-sm text-gray-600 hover:text-gray-500">Batal</a>
                </div>
            </div>
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
            row.className = 'item-row grid grid-cols-12 gap-2 items-start border border-gray-200 rounded-md p-3';
            row.innerHTML = `
                <div class="col-span-3">
                    <label class="block text-xs text-gray-500 mb-1">Treatment</label>
                    <select name="items[${itemIndex}][treatment_id]" class="treatment-select w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500" onchange="fillDescription(this)">
                        <option value="">— Pilih Treatment —</option>
                        @foreach ($treatments as $treatment)
                        <option value="{{ $treatment->id }}" data-name="{{ $treatment->name }}">{{ $treatment->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-span-3">
                    <label class="block text-xs text-gray-500 mb-1">Deskripsi <span class="text-red-500">*</span></label>
                    <input type="text" name="items[${itemIndex}][description]" class="description-input w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500" required>
                </div>
                <div class="col-span-1">
                    <label class="block text-xs text-gray-500 mb-1">Qty <span class="text-red-500">*</span></label>
                    <input type="number" name="items[${itemIndex}][qty]" value="1" min="1" class="qty-input w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500" required oninput="recalculate()">
                </div>
                <div class="col-span-2">
                    <label class="block text-xs text-gray-500 mb-1">Harga Satuan <span class="text-red-500">*</span></label>
                    <input type="number" name="items[${itemIndex}][unit_price]" min="0" step="1" class="price-input w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500" required oninput="recalculate()">
                </div>
                <div class="col-span-2">
                    <label class="block text-xs text-gray-500 mb-1">Diskon</label>
                    <input type="number" name="items[${itemIndex}][discount]" value="0" min="0" step="1" class="discount-input w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500" oninput="recalculate()">
                </div>
                <div class="col-span-1 flex items-end">
                    <button type="button" onclick="removeItem(this)" class="w-full py-2 text-xs text-red-600 hover:text-red-500 border border-red-200 rounded-md">Hapus</button>
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
