<x-settings-shell title="Bayar Tagihan RME">
    @php
        $invoiceStatusLabels = [
            'DRAFT'  => 'Draft',
            'UNPAID'  => 'Belum Dibayar',
            'PARTIAL' => 'Cicilan / Sebagian',
            'PAID'    => 'Lunas',
            'VOID'    => 'Dibatalkan',
        ];
        $invoiceStatusTone = [
            'DRAFT'  => 'info',
            'UNPAID'  => 'warning',
            'PARTIAL' => 'warning',
            'PAID'    => 'success',
            'VOID'    => 'danger',
        ];

        $paidAmount = $invoice->paidAmount();
        $remainingAmount = $invoice->remainingAmount();
    @endphp

    <div class="space-y-6">

        {{-- Page Header --}}
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Rekam Medis Elektronik</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">Pembayaran Tagihan RME</h2>
                <p class="mt-1 text-sm text-gray-500">Input pembayaran penuh atau cicilan untuk tagihan RME yang sudah dibuat kasir.</p>
            </div>
            <div class="flex flex-shrink-0 items-center gap-3">
                <x-ui.button variant="neutral" :href="route('rme.cashier.show', [$visit, $invoice])">&larr; Kembali</x-ui.button>
            </div>
        </div>

        {{-- Invoice Header --}}
        <x-ui.card>
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 font-mono">{{ $invoice->invoice_number }}</h3>
                    <p class="text-sm text-gray-500 mt-1">Dibuat oleh {{ $invoice->cashier?->name }} &mdash; {{ $invoice->created_at?->format('d/m/Y H:i') }}</p>
                </div>
                <x-ui.badge :tone="$invoiceStatusTone[$invoice->status] ?? 'neutral'">
                    {{ $invoiceStatusLabels[$invoice->status] ?? $invoice->status }}
                </x-ui.badge>
            </div>
        </x-ui.card>

        {{-- Patient & Visit Context --}}
        <x-ui.card title="Informasi Kunjungan">
            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <div>
                    <dt class="text-gray-500">No. Kunjungan</dt>
                    <dd class="font-mono font-medium text-gray-900">{{ $visit->visit_number }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Tanggal Kunjungan</dt>
                    <dd class="text-gray-900">{{ $visit->visit_date?->format('d/m/Y') }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Pasien</dt>
                    <dd class="font-medium text-gray-900">{{ $visit->patient?->name }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Nomor WA</dt>
                    <dd class="text-gray-900">{{ $visit->patient?->whatsapp_number ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">No. Rekam Medis</dt>
                    <dd class="font-mono text-gray-900">{{ $visit->patient?->medical_record_number ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Dokter</dt>
                    <dd class="text-gray-900">{{ $visit->doctor?->name ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Grand Total</dt>
                    <dd class="text-base font-bold text-teal-700">Rp {{ number_format($invoice->grand_total, 0, ',', '.') }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Sudah Dibayar</dt>
                    <dd class="text-base font-semibold text-emerald-700">Rp {{ number_format($paidAmount, 0, ',', '.') }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Sisa Tagihan</dt>
                    <dd class="text-base font-bold text-amber-700">Rp {{ number_format($remainingAmount, 0, ',', '.') }}</dd>
                </div>
            </dl>
        </x-ui.card>

        {{-- Items Summary --}}
        <x-ui.card title="Ringkasan Tagihan">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Deskripsi</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500">Qty</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500">Harga Satuan</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($invoice->items as $item)
                            <tr>
                                <td class="px-4 py-3 text-gray-900">{{ $item->description }}</td>
                                <td class="px-4 py-3 text-right text-gray-700">{{ $item->qty }}</td>
                                <td class="px-4 py-3 text-right text-gray-700">Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
                                <td class="px-4 py-3 text-right font-medium text-gray-900">Rp {{ number_format($item->subtotal, 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-gray-50">
                        @if ($invoice->discount_total > 0)
                            <tr>
                                <td colspan="3" class="px-4 py-2 text-right text-sm text-red-600">Total Diskon</td>
                                <td class="px-4 py-2 text-right font-medium text-red-600">- Rp {{ number_format($invoice->discount_total, 0, ',', '.') }}</td>
                            </tr>
                        @endif
                        <tr class="border-t-2 border-gray-300">
                            <td colspan="3" class="px-4 py-3 text-right text-base font-semibold text-gray-900">Grand Total</td>
                            <td class="px-4 py-3 text-right text-base font-bold text-teal-700">Rp {{ number_format($invoice->grand_total, 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td colspan="3" class="px-4 py-2 text-right text-sm text-emerald-700">Sudah Dibayar</td>
                            <td class="px-4 py-2 text-right font-medium text-emerald-700">Rp {{ number_format($paidAmount, 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td colspan="3" class="px-4 py-2 text-right text-sm text-amber-700">Sisa Tagihan</td>
                            <td class="px-4 py-2 text-right font-bold text-amber-700">Rp {{ number_format($remainingAmount, 0, ',', '.') }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </x-ui.card>

        {{-- Payment Form --}}
        <x-ui.card title="Form Pembayaran">
            <p class="mb-5 rounded-md border border-teal-200 bg-teal-50 px-3 py-2 text-xs text-teal-700">
                Pembayaran dapat dicatat sebagai cicilan atau pelunasan. Jumlah bayar tidak boleh melebihi sisa tagihan.
            </p>

            <form method="POST" action="{{ route('rme.cashier.payment.store', [$visit, $invoice]) }}" class="space-y-5">
                @csrf

                {{-- Payment Method --}}
                <div>
                    <label for="payment_method_id" class="block text-sm font-medium text-gray-700 mb-1">
                        Metode Pembayaran
                    </label>
                    <select name="payment_method_id" id="payment_method_id"
                        class="block w-full max-w-xs rounded-md border-gray-300 shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500 @error('payment_method_id') border-red-500 @enderror">
                        <option value="">— Pilih Metode —</option>
                        @foreach ($paymentMethods as $method)
                            <option value="{{ $method->id }}" {{ old('payment_method_id') == $method->id ? 'selected' : '' }}>
                                {{ $method->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('payment_method_id')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Amount --}}
                <div>
                    <label for="amount" class="block text-sm font-medium text-gray-700 mb-1">
                        Jumlah Dibayar <span class="text-red-500">*</span>
                    </label>
                    <input type="number" name="amount" id="amount"
                        value="{{ old('amount', $remainingAmount) }}"
                        step="0.01" min="0.01" max="{{ $remainingAmount }}"
                        class="block w-full max-w-xs rounded-md border-gray-300 shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500 @error('amount') border-red-500 @enderror">
                    <p class="mt-1 text-xs text-gray-500">Maksimal sisa tagihan: Rp {{ number_format($remainingAmount, 0, ',', '.') }}.</p>
                    @error('amount')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Paid At --}}
                <div>
                    <label for="paid_at" class="block text-sm font-medium text-gray-700 mb-1">
                        Tanggal &amp; Waktu Bayar <span class="text-red-500">*</span>
                    </label>
                    <input type="datetime-local" name="paid_at" id="paid_at"
                        value="{{ old('paid_at', now()->format('Y-m-d\TH:i')) }}"
                        class="block w-full max-w-xs rounded-md border-gray-300 shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500 @error('paid_at') border-red-500 @enderror">
                    @error('paid_at')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Reference Number --}}
                <div>
                    <label for="reference_number" class="block text-sm font-medium text-gray-700 mb-1">
                        No. Referensi <span class="text-gray-400 text-xs">(opsional, untuk QRIS/transfer)</span>
                    </label>
                    <input type="text" name="reference_number" id="reference_number"
                        value="{{ old('reference_number') }}"
                        placeholder="Nomor transaksi / approval"
                        class="block w-full max-w-xs rounded-md border-gray-300 shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500 @error('reference_number') border-red-500 @enderror">
                    @error('reference_number')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Consent verification checklist --}}
                <div class="rounded-md border border-amber-200 bg-amber-50 p-4 space-y-3">
                    <p class="text-sm font-medium text-amber-900">Verifikasi Surat Persetujuan Tindakan (fisik)</p>
                    <p class="text-xs text-amber-800">Konfirmasi bahwa formulir persetujuan tindakan sudah ditandatangani pasien dan dokter sebelum pembayaran diproses.</p>
                    <label class="flex items-start gap-2 text-sm text-gray-800">
                        <input type="checkbox" name="consent_signed_by_patient" value="1"
                            @checked(old('consent_signed_by_patient', $visit->consent_signed_by_patient))
                            class="mt-0.5 rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                        <span>Surat Persetujuan Tindakan sudah ditandatangani pasien</span>
                    </label>
                    @error('consent_signed_by_patient')
                        <p class="text-xs text-red-600">{{ $message }}</p>
                    @enderror
                    <label class="flex items-start gap-2 text-sm text-gray-800">
                        <input type="checkbox" name="consent_signed_by_doctor" value="1"
                            @checked(old('consent_signed_by_doctor', $visit->consent_signed_by_doctor))
                            class="mt-0.5 rounded border-gray-300 text-teal-600 focus:ring-teal-500">
                        <span>Surat Persetujuan Tindakan sudah ditandatangani dokter</span>
                    </label>
                    @error('consent_signed_by_doctor')
                        <p class="text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Notes --}}
                <div>
                    <label for="notes" class="block text-sm font-medium text-gray-700 mb-1">
                        Catatan <span class="text-gray-400 text-xs">(opsional)</span>
                    </label>
                    <textarea name="notes" id="notes" rows="2"
                        class="block w-full max-w-xs rounded-md border-gray-300 shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500 @error('notes') border-red-500 @enderror"
                        >{{ old('notes') }}</textarea>
                    @error('notes')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Actions --}}
                <div class="flex items-center gap-3 pt-2">
                    <x-ui.button type="submit" variant="primary">Konfirmasi Pembayaran</x-ui.button>
                    <x-ui.button variant="neutral" :href="route('rme.cashier.show', [$visit, $invoice])">Batal</x-ui.button>
                </div>
            </form>
        </x-ui.card>

    </div>
</x-settings-shell>
