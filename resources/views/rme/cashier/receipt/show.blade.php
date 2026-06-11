<x-settings-shell title="Kwitansi Pembayaran RME">

    {{-- Print isolation: show only receipt-body when printing --}}
    <style>
        @media print {
            body * { visibility: hidden; }
            #receipt-body, #receipt-body * { visibility: visible; }
            #receipt-body {
                position: absolute;
                inset: 0;
                width: 100%;
                padding: 24px;
                background: #fff;
            }
        }
    </style>

    <div class="space-y-6">

        {{-- Page Header (screen only) --}}
        <div class="flex items-start justify-between gap-4 print:hidden">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Rekam Medis Elektronik</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">Kwitansi Pembayaran RME</h2>
                <p class="mt-1 text-sm text-gray-500">Bukti pembayaran tagihan kunjungan pasien.</p>
            </div>
            <div class="flex flex-shrink-0 items-center gap-3">
                <x-ui.button variant="neutral" :href="route('rme.cashier.show', [$visit, $invoice])">&larr; Kembali ke Tagihan</x-ui.button>
                <x-ui.button variant="primary" onclick="window.print()">Cetak Kwitansi</x-ui.button>
            </div>
        </div>

        {{-- Receipt Body --}}
        <div class="max-w-2xl mx-auto space-y-4 print:max-w-full print:space-y-3" id="receipt-body">

            {{-- Clinic / Receipt Header --}}
            <x-ui.card>
                <div class="text-center border-b border-gray-200 pb-4 mb-5">
                    <h2 class="text-xl font-bold text-gray-900">{{ $invoice->branch?->name ?? config('app.name') }}</h2>
                    <p class="mt-1 text-sm font-semibold uppercase tracking-wide text-teal-700">Kwitansi Pembayaran RME</p>
                </div>

                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">No. Kwitansi</dt>
                        <dd class="mt-1 font-mono font-semibold text-gray-900">{{ $payment?->payment_number ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Tanggal Bayar</dt>
                        <dd class="mt-1 font-medium text-gray-900">{{ $payment?->paid_at?->format('d/m/Y H:i') ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">No. Invoice</dt>
                        <dd class="mt-1 font-mono text-gray-900">{{ $invoice->invoice_number }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Kasir</dt>
                        <dd class="mt-1 text-gray-900">{{ $payment?->cashier?->name ?? $invoice->cashier?->name ?? '-' }}</dd>
                    </div>
                </dl>
            </x-ui.card>

            {{-- Patient & Visit --}}
            <x-ui.card title="Data Pasien">
                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Nama Pasien</dt>
                        <dd class="mt-1 font-medium text-gray-900">{{ $visit->patient?->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">No. Rekam Medis</dt>
                        <dd class="mt-1 font-mono text-gray-900">{{ $visit->patient?->medical_record_number ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">No. Kunjungan</dt>
                        <dd class="mt-1 font-mono text-gray-900">{{ $visit->visit_number }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Tanggal Kunjungan</dt>
                        <dd class="mt-1 text-gray-900">{{ $visit->visit_date?->format('d/m/Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Dokter</dt>
                        <dd class="mt-1 text-gray-900">{{ $visit->doctor?->name }}</dd>
                    </div>
                </dl>
            </x-ui.card>

            {{-- Treatment Items --}}
            <x-ui.card title="Rincian Tindakan">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b-2 border-gray-200">
                                <th class="pb-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Tindakan</th>
                                <th class="pb-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Qty</th>
                                <th class="pb-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Harga Satuan</th>
                                <th class="pb-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($invoice->items as $item)
                                <tr>
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
                                    <td colspan="3" class="pt-3 text-right text-sm text-gray-600">Diskon</td>
                                    <td class="pt-3 text-right text-red-600">- Rp {{ number_format($invoice->discount_total, 0, ',', '.') }}</td>
                                </tr>
                            @endif
                            <tr class="border-t-2 border-gray-300">
                                <td colspan="3" class="py-3 text-right font-semibold text-gray-900">Total</td>
                                <td class="py-3 text-right font-bold text-gray-900">Rp {{ number_format($invoice->grand_total, 0, ',', '.') }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </x-ui.card>

            {{-- Lab workflow (read-only visibility) --}}
            <x-rme.lab-workflow-panel
                :invoice="$invoice"
                :candidates="$labCaseCandidates"
                compact
                class="print-lab-workflow"
            />

            {{-- Payment Summary --}}
            <x-ui.card>
                <div class="space-y-3 text-sm">
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
                    <div class="flex items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3">
                        <span class="text-base font-semibold text-emerald-800">Jumlah Dibayar</span>
                        <span class="text-xl font-bold text-emerald-700">Rp {{ number_format($payment?->amount ?? $invoice->grand_total, 0, ',', '.') }}</span>
                    </div>
                </div>
            </x-ui.card>

            {{-- Stamp / Footer --}}
            <div class="py-4 text-center text-xs text-gray-400">
                <p>Dicetak pada {{ now()->format('d/m/Y H:i') }}</p>
                <p class="mt-3 inline-block rounded border border-gray-300 px-6 py-1 text-base font-bold tracking-widest text-gray-500">LUNAS</p>
            </div>

        </div>
    </div>

</x-settings-shell>
