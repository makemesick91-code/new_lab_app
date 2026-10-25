@php
    use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionApprovalReference;
    use App\Modules\RmeInvoice\Support\RmeInvoiceDocument;
    $postPayment = (bool) ($panel['requires_owner_approval_reference'] ?? false);
@endphp
<x-settings-shell title="Ajukan Koreksi Tagihan">
    <div class="space-y-6">
        <x-ui.page-header title="Ajukan Koreksi Tagihan"
            :subtitle="$postPayment
                ? 'Koreksi tagihan yang SUDAH menerima pembayaran. Wajib referensi persetujuan Owner untuk kasus ini; persetujuan akun lain langsung menerapkannya.'
                : 'Koreksi tagihan yang BELUM dibayar. Pengajuan harus disetujui akun lain lalu diterapkan sebelum tagihan berubah.'">
            <x-slot:breadcrumb>Rekam Medis Elektronik · Kasir · {{ $invoice->invoice_number }}</x-slot:breadcrumb>
            <x-slot:actions>
                <x-ui.button variant="secondary" :href="route('rme.cashier.show', [$visit, $invoice])">&larr; Kembali ke Tagihan</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        @if (! $panel['eligible'])
            <x-ui.alert variant="danger" data-correction-ineligible>
                {{ $panel['ineligible_reason'] ?? 'Tagihan ini tidak dapat dikoreksi saat ini.' }}
            </x-ui.alert>
        @endif

        @if ($errors->any())
            <x-ui.alert variant="danger">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        <x-ui.card title="Tagihan Asli (tidak berubah sampai koreksi diterapkan)">
            <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-4">
                <div><dt class="text-ink-soft">No. Invoice</dt><dd class="font-mono text-navy">{{ $invoice->invoice_number }}</dd></div>
                <div><dt class="text-ink-soft">Pasien</dt><dd class="text-navy">{{ $visit->patient?->name }}</dd></div>
                <div><dt class="text-ink-soft">Cabang</dt><dd class="text-navy">{{ $invoice->branch?->name }}</dd></div>
                <div><dt class="text-ink-soft">Total Asli</dt><dd class="font-semibold text-navy">{{ RmeInvoiceDocument::rupiahDecimal($invoice->grand_total) }}</dd></div>
                @if ($postPayment)
                    <div data-correction-paid-total><dt class="text-ink-soft">Sudah Dibayar</dt><dd class="font-semibold text-navy">{{ RmeInvoiceDocument::rupiah($paidCents) }}</dd></div>
                    <div><dt class="text-ink-soft">Sisa Tagihan</dt><dd class="font-semibold text-navy">{{ RmeInvoiceDocument::rupiah($outstandingCents) }}</dd></div>
                @endif
            </dl>
            <x-ui.table class="mt-4">
                <thead class="bg-navy-50">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium text-ink-soft">Deskripsi</th>
                        <th class="px-4 py-2 text-left font-medium text-ink-soft">Tindakan</th>
                        <th class="px-4 py-2 text-right font-medium text-ink-soft">Qty</th>
                        <th class="px-4 py-2 text-right font-medium text-ink-soft">Harga</th>
                        <th class="px-4 py-2 text-right font-medium text-ink-soft">Diskon</th>
                        <th class="px-4 py-2 text-right font-medium text-ink-soft">Subtotal</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($invoice->items as $item)
                        <tr>
                            <td class="px-4 py-2 text-navy">{{ $item->description }}</td>
                            <td class="px-4 py-2 text-xs text-ink-soft">{{ $item->treatment?->name ?? '-' }}</td>
                            <td class="px-4 py-2 text-right">{{ $item->qty }}</td>
                            <td class="px-4 py-2 text-right">{{ RmeInvoiceDocument::rupiahDecimal($item->unit_price) }}</td>
                            <td class="px-4 py-2 text-right">{{ RmeInvoiceDocument::rupiahDecimal($item->discount) }}</td>
                            <td class="px-4 py-2 text-right font-medium text-navy">{{ RmeInvoiceDocument::rupiahDecimal($item->subtotal) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        </x-ui.card>

        @if ($panel['eligible'])
            <form method="POST" action="{{ route('rme.invoice-corrections.store', [$visit, $invoice]) }}"
                  x-data="{
                      lines: @js(old('lines', $initialLines)),
                      blank() { return { original_item_id: null, treatment_id: null, doctor_id: null, description: '', qty: 1, unit_price: '0', discount: '0' } },
                      cents(v) { return Math.round((Number(v) || 0) * 100) },
                      net(l) { return (Math.trunc(Number(l.qty)) || 0) * this.cents(l.unit_price) - this.cents(l.discount) },
                      total() { return this.lines.reduce((sum, l) => sum + this.net(l), 0) },
                      {{-- Rule 195 §25: integer cents, printed as stored — cents kept, never rounded (mirrors RmeInvoiceDocument::rupiah). --}}
                      rp(c) { const a = Math.abs(c), w = Math.trunc(a / 100), f = a % 100; return (c < 0 ? '- ' : '') + 'Rp ' + w.toLocaleString('id-ID') + (f ? ',' + String(f).padStart(2, '0') : '') }
                  }"
                  data-correction-form>
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

                <x-ui.card title="Usulan Item Setelah Koreksi">
                    <p class="mb-3 text-sm text-ink-soft">
                        Baris yang tidak diubah tetap seperti aslinya. Hapus baris untuk menghapus item, tambah baris untuk item baru.
                        Angka total di bawah hanya pratinjau — server menghitung ulang semua subtotal dan total.
                        Koreksi tidak dapat membuat item atau total bernilai Rp0 (kebijakan Rp0 belum diputuskan).
                    </p>

                    <template x-for="(line, index) in lines" :key="index">
                        <div class="mb-3 grid grid-cols-1 gap-2 rounded-lg border border-hairline p-3 md:grid-cols-12" data-correction-line>
                            <input type="hidden" :name="`lines[${index}][original_item_id]`" :value="line.original_item_id ?? ''">
                            <input type="hidden" :name="`lines[${index}][doctor_id]`" :value="line.doctor_id ?? ''">
                            <div class="md:col-span-4">
                                <label class="text-xs text-ink-soft">Deskripsi</label>
                                <input type="text" class="w-full rounded-lg border-hairline text-sm" maxlength="255" required
                                       :name="`lines[${index}][description]`" x-model="line.description">
                                <p class="mt-1 text-xs text-ink-muted" x-text="line.original_item_id ? 'Item asal #' + line.original_item_id : 'Item baru'"></p>
                            </div>
                            <div class="md:col-span-3">
                                <label class="text-xs text-ink-soft">Tindakan</label>
                                <select class="w-full rounded-lg border-hairline text-sm" :name="`lines[${index}][treatment_id]`" x-model="line.treatment_id">
                                    <option value="">— Tanpa tindakan —</option>
                                    @foreach ($treatments as $treatment)
                                        <option value="{{ $treatment->id }}">{{ $treatment->name }}{{ $treatment->is_active ? '' : ' (nonaktif)' }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="md:col-span-1">
                                <label class="text-xs text-ink-soft">Qty</label>
                                <input type="number" min="1" step="1" class="w-full rounded-lg border-hairline text-sm" required
                                       :name="`lines[${index}][qty]`" x-model="line.qty">
                            </div>
                            <div class="md:col-span-2">
                                <label class="text-xs text-ink-soft">Harga Satuan</label>
                                <input type="text" inputmode="decimal" class="w-full rounded-lg border-hairline text-sm" required
                                       :name="`lines[${index}][unit_price]`" x-model="line.unit_price">
                            </div>
                            <div class="md:col-span-1">
                                <label class="text-xs text-ink-soft">Diskon</label>
                                <input type="text" inputmode="decimal" class="w-full rounded-lg border-hairline text-sm"
                                       :name="`lines[${index}][discount]`" x-model="line.discount">
                            </div>
                            <div class="flex items-end justify-between md:col-span-1 md:flex-col md:items-end">
                                <span class="text-xs tabular-nums text-navy" x-text="rp(net(line))"></span>
                                <button type="button" class="text-xs text-danger-700 hover:underline" x-on:click="lines.splice(index, 1)">Hapus</button>
                            </div>
                        </div>
                    </template>

                    <div class="flex items-center justify-between">
                        <x-ui.button variant="secondary" size="sm" x-on:click="lines.push(blank())">+ Tambah Item</x-ui.button>
                        <div class="text-sm">Pratinjau total usulan: <span class="font-semibold text-navy" x-text="rp(total())"></span></div>
                    </div>
                </x-ui.card>

                @if ($postPayment)
                    {{-- PP-3 / PP-4 — what the server will do; the result is decided by
                         the ledger on approval, never by this form. --}}
                    <x-ui.alert variant="warning" class="mt-6" data-postpayment-outcome-notice>
                        Tagihan ini sudah menerima pembayaran. Setelah disetujui: bila total baru <strong>lebih besar</strong> dari yang sudah dibayar, selisihnya menjadi sisa tagihan biasa;
                        bila <strong>lebih kecil</strong>, selisihnya dicatat sebagai <strong>kewajiban kelebihan bayar (belum dikembalikan)</strong> — tidak ada uang yang dikembalikan oleh sistem dan
                        uang tidak boleh dikembalikan di luar sistem. Pembayaran yang sudah tercatat tidak diubah dan kwitansi yang sudah terbit tidak berubah.
                    </x-ui.alert>
                @endif

                <x-ui.card title="Persetujuan Owner" class="mt-6">
                    <x-ui.input name="owner_approval_reference"
                        :label="$postPayment ? 'Referensi persetujuan Owner (wajib)' : 'Referensi persetujuan Owner (opsional)'"
                        :value="old('owner_approval_reference')"
                        maxlength="{{ RmeInvoiceCorrectionApprovalReference::MAX }}"
                        placeholder="mis. OWNER-COR-SPN4-20261025-01"
                        :required="$postPayment" />
                    <p class="mt-2 text-xs text-ink-muted">Hanya nomor referensi formulir persetujuan Owner untuk kasus ini (6–120 karakter: huruf, angka, . _ : / -). Formulirnya sendiri disimpan di kanal klinik, bukan di sistem. Sistem mencatat referensi, tetapi tidak dapat membuktikan tanda tangan Owner.</p>
                </x-ui.card>

                <x-ui.card title="Alasan Koreksi" class="mt-6">
                    <x-ui.textarea name="reason" label="Alasan (wajib, minimal 10 karakter)" :value="old('reason')" rows="3" required />
                    <p class="mt-2 text-xs text-ink-muted">Jangan menuliskan NIK/KTP pasien. Alasan tidak dicatat di log audit (hanya panjangnya).</p>
                    <div class="mt-4 flex justify-end">
                        <x-ui.button type="submit" variant="primary">Kirim Pengajuan Koreksi</x-ui.button>
                    </div>
                </x-ui.card>
            </form>
        @endif
    </div>
</x-settings-shell>
