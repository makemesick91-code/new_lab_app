<x-settings-shell title="Bayar Tagihan RME">
    <div class="space-y-6">

        {{-- Invoice Summary --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 font-mono">{{ $invoice->invoice_number }}</h3>
                    <p class="text-sm text-gray-500 mt-1">
                        {{ $visit->patient?->name }} &mdash; No. Kunjungan: {{ $visit->visit_number }}
                    </p>
                </div>
                <span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-semibold bg-amber-50 text-amber-700">
                    UNPAID
                </span>
            </div>
        </div>

        {{-- Items Summary --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h4 class="text-sm font-semibold text-gray-700 mb-3">Ringkasan Tagihan</h4>
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
                            <td class="px-4 py-3 text-right text-base font-bold text-indigo-700">Rp {{ number_format($invoice->grand_total, 0, ',', '.') }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        {{-- Payment Form --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h4 class="text-sm font-semibold text-gray-700 mb-4">Form Pembayaran</h4>

            <form method="POST" action="{{ route('rme.cashier.payment.store', [$visit, $invoice]) }}" class="space-y-5">
                @csrf

                {{-- Payment Method --}}
                <div>
                    <label for="payment_method_id" class="block text-sm font-medium text-gray-700 mb-1">
                        Metode Pembayaran
                    </label>
                    <select name="payment_method_id" id="payment_method_id"
                        class="block w-full max-w-xs rounded-md border-gray-300 shadow-sm text-sm focus:ring-indigo-500 focus:border-indigo-500 @error('payment_method_id') border-red-500 @enderror">
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
                        value="{{ old('amount', $invoice->grand_total) }}"
                        step="0.01" min="0.01"
                        class="block w-full max-w-xs rounded-md border-gray-300 shadow-sm text-sm focus:ring-indigo-500 focus:border-indigo-500 @error('amount') border-red-500 @enderror">
                    <p class="mt-1 text-xs text-gray-500">Harus sama dengan grand total (pembayaran penuh).</p>
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
                        class="block w-full max-w-xs rounded-md border-gray-300 shadow-sm text-sm focus:ring-indigo-500 focus:border-indigo-500 @error('paid_at') border-red-500 @enderror">
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
                        class="block w-full max-w-xs rounded-md border-gray-300 shadow-sm text-sm focus:ring-indigo-500 focus:border-indigo-500 @error('reference_number') border-red-500 @enderror">
                    @error('reference_number')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Notes --}}
                <div>
                    <label for="notes" class="block text-sm font-medium text-gray-700 mb-1">
                        Catatan <span class="text-gray-400 text-xs">(opsional)</span>
                    </label>
                    <textarea name="notes" id="notes" rows="2"
                        class="block w-full max-w-xs rounded-md border-gray-300 shadow-sm text-sm focus:ring-indigo-500 focus:border-indigo-500 @error('notes') border-red-500 @enderror"
                        >{{ old('notes') }}</textarea>
                    @error('notes')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Actions --}}
                <div class="flex items-center gap-3 pt-2">
                    <a href="{{ route('rme.cashier.show', [$visit, $invoice]) }}"
                        class="inline-flex items-center px-4 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-md hover:bg-gray-200">
                        Batal
                    </a>
                    <button type="submit"
                        class="inline-flex items-center px-6 py-2 bg-green-600 text-white text-sm font-semibold rounded-md hover:bg-green-700">
                        Konfirmasi Pembayaran
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-settings-shell>
