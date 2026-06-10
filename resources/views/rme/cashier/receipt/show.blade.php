<x-settings-shell title="Kwitansi Pembayaran RME">

    {{-- Print button (hidden on print) --}}
    <div class="flex items-center gap-3 mb-6 print:hidden">
        <a href="{{ route('rme.cashier.show', [$visit, $invoice]) }}"
            class="inline-flex items-center px-4 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-md hover:bg-gray-200">
            &larr; Kembali ke Tagihan
        </a>
        <button onclick="window.print()"
            class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700">
            Cetak Kwitansi
        </button>
    </div>

    {{-- Receipt Body --}}
    <div class="bg-white shadow-sm sm:rounded-lg p-8 max-w-2xl mx-auto print:shadow-none print:p-0" id="receipt-body">

        {{-- Clinic Header --}}
        <div class="text-center border-b border-gray-200 pb-4 mb-6">
            <h2 class="text-xl font-bold text-gray-900">{{ $invoice->branch?->name ?? config('app.name') }}</h2>
            <p class="text-sm text-gray-500 mt-1">Kwitansi Pembayaran</p>
        </div>

        {{-- Receipt Meta --}}
        <div class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm mb-6">
            <div>
                <span class="text-gray-500">No. Kwitansi</span>
                <p class="font-mono font-semibold text-gray-900">{{ $payment?->payment_number ?? '-' }}</p>
            </div>
            <div>
                <span class="text-gray-500">Tanggal Bayar</span>
                <p class="font-medium text-gray-900">{{ $payment?->paid_at?->format('d/m/Y H:i') ?? '-' }}</p>
            </div>
            <div>
                <span class="text-gray-500">No. Invoice</span>
                <p class="font-mono text-gray-900">{{ $invoice->invoice_number }}</p>
            </div>
            <div>
                <span class="text-gray-500">Kasir</span>
                <p class="text-gray-900">{{ $payment?->cashier?->name ?? $invoice->cashier?->name ?? '-' }}</p>
            </div>
        </div>

        {{-- Patient & Visit --}}
        <div class="border border-gray-200 rounded-lg p-4 mb-6 text-sm">
            <p class="text-xs text-gray-500 font-medium uppercase tracking-wide mb-2">Data Pasien</p>
            <div class="grid grid-cols-2 gap-x-6 gap-y-1">
                <div>
                    <span class="text-gray-500">Pasien</span>
                    <p class="font-medium text-gray-900">{{ $visit->patient?->name }}</p>
                </div>
                <div>
                    <span class="text-gray-500">No. Kunjungan</span>
                    <p class="font-mono text-gray-900">{{ $visit->visit_number }}</p>
                </div>
                <div>
                    <span class="text-gray-500">Tanggal Kunjungan</span>
                    <p class="text-gray-900">{{ $visit->visit_date?->format('d/m/Y') }}</p>
                </div>
                <div>
                    <span class="text-gray-500">Dokter</span>
                    <p class="text-gray-900">{{ $visit->doctor?->name }}</p>
                </div>
            </div>
        </div>

        {{-- Treatment Items --}}
        <table class="w-full text-sm mb-4">
            <thead>
                <tr class="border-b border-gray-200">
                    <th class="py-2 text-left font-medium text-gray-600">Tindakan</th>
                    <th class="py-2 text-right font-medium text-gray-600">Qty</th>
                    <th class="py-2 text-right font-medium text-gray-600">Harga</th>
                    <th class="py-2 text-right font-medium text-gray-600">Jumlah</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->items as $item)
                    <tr class="border-b border-gray-100">
                        <td class="py-2 text-gray-900">{{ $item->description }}</td>
                        <td class="py-2 text-right text-gray-700">{{ $item->qty }}</td>
                        <td class="py-2 text-right text-gray-700">Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
                        <td class="py-2 text-right font-medium text-gray-900">Rp {{ number_format($item->subtotal, 0, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                @if ($invoice->discount_total > 0)
                    <tr>
                        <td colspan="3" class="py-1 text-right text-sm text-gray-600">Diskon</td>
                        <td class="py-1 text-right text-red-600">- Rp {{ number_format($invoice->discount_total, 0, ',', '.') }}</td>
                    </tr>
                @endif
                <tr class="border-t-2 border-gray-300">
                    <td colspan="3" class="py-2 text-right font-semibold text-gray-900">Total</td>
                    <td class="py-2 text-right font-bold text-gray-900">Rp {{ number_format($invoice->grand_total, 0, ',', '.') }}</td>
                </tr>
            </tfoot>
        </table>

        {{-- Payment Summary --}}
        <div class="border-t border-gray-200 pt-4 text-sm space-y-1">
            <div class="flex justify-between">
                <span class="text-gray-600">Metode Pembayaran</span>
                <span class="font-medium text-gray-900">{{ $payment?->paymentMethod?->name ?? 'Tunai' }}</span>
            </div>
            @if ($payment?->reference_number)
                <div class="flex justify-between">
                    <span class="text-gray-600">No. Referensi</span>
                    <span class="font-mono text-gray-900">{{ $payment->reference_number }}</span>
                </div>
            @endif
            <div class="flex justify-between text-base font-bold border-t border-gray-200 pt-2 mt-2">
                <span class="text-gray-900">Jumlah Dibayar</span>
                <span class="text-green-700">Rp {{ number_format($payment?->amount ?? $invoice->grand_total, 0, ',', '.') }}</span>
            </div>
        </div>

        {{-- Stamp area --}}
        <div class="mt-8 text-center text-xs text-gray-400">
            <p>Dicetak pada {{ now()->format('d/m/Y H:i') }}</p>
            <p class="mt-4 font-medium text-gray-500">LUNAS</p>
        </div>
    </div>
</x-settings-shell>
