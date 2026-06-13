<x-settings-shell title="Detail Tagihan RME">
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
                <h2 class="mt-1 text-xl font-semibold text-gray-900">Detail Tagihan RME</h2>
                <p class="mt-1 text-sm text-gray-500">Ringkasan tagihan final dari kunjungan RME yang sudah difinalisasi dokter.</p>
            </div>
            <div class="flex flex-shrink-0 items-center gap-3">
                <x-ui.button variant="neutral" :href="route('rme.cashier.index')">&larr; Kembali ke Daftar</x-ui.button>
                @if ($invoice->isPayable())
                    <x-ui.button variant="primary" :href="route('rme.cashier.payment.create', [$visit, $invoice])">
                        {{ $invoice->isPartial() ? 'Bayar Cicilan' : 'Bayar Sekarang' }}
                    </x-ui.button>
                @endif
                @if ($invoice->status === 'PAID')
                    <x-ui.button variant="secondary" :href="route('rme.cashier.receipt.show', [$visit, $invoice])">Lihat Kwitansi</x-ui.button>
                @endif
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

        {{-- Patient & Visit --}}
        @include('rme.cashier.partials.clinical-summary', ['visit' => $visit])

        {{-- Invoice Items --}}
        <x-ui.card padding="">
            <div class="border-b border-gray-200 px-4 py-3">
                <h3 class="text-base font-semibold text-gray-900">Item Tagihan</h3>
            </div>
            <x-ui.table>
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Deskripsi</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Treatment</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Qty</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Harga Satuan</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Diskon</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-500">Subtotal</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($invoice->items as $item)
                        <tr>
                            <td class="px-4 py-3 text-gray-900">{{ $item->description }}</td>
                            <td class="px-4 py-3 text-gray-500 text-xs">{{ $item->treatment?->name ?? '-' }}</td>
                            <td class="px-4 py-3 text-right text-gray-700">{{ $item->qty }}</td>
                            <td class="px-4 py-3 text-right text-gray-700">Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
                            <td class="px-4 py-3 text-right text-red-600">{{ $item->discount > 0 ? 'Rp '.number_format($item->discount, 0, ',', '.') : '-' }}</td>
                            <td class="px-4 py-3 text-right font-medium text-gray-900">Rp {{ number_format($item->subtotal, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-gray-50">
                    <tr>
                        <td colspan="5" class="px-4 py-2 text-right text-sm text-gray-600">Subtotal</td>
                        <td class="px-4 py-2 text-right font-medium text-gray-900">Rp {{ number_format($invoice->subtotal, 0, ',', '.') }}</td>
                    </tr>
                    <tr>
                        <td colspan="5" class="px-4 py-2 text-right text-sm text-red-600">Total Diskon</td>
                        <td class="px-4 py-2 text-right font-medium text-red-600">Rp {{ number_format($invoice->discount_total, 0, ',', '.') }}</td>
                    </tr>
                    <tr class="border-t-2 border-gray-300">
                        <td colspan="5" class="px-4 py-3 text-right text-base font-semibold text-gray-900">Grand Total</td>
                        <td class="px-4 py-3 text-right text-base font-bold text-blue-700">Rp {{ number_format($invoice->grand_total, 0, ',', '.') }}</td>
                    </tr>
                    <tr>
                        <td colspan="5" class="px-4 py-2 text-right text-sm text-emerald-700">Sudah Dibayar</td>
                        <td class="px-4 py-2 text-right font-medium text-emerald-700">Rp {{ number_format($paidAmount, 0, ',', '.') }}</td>
                    </tr>
                    <tr>
                        <td colspan="5" class="px-4 py-2 text-right text-sm text-amber-700">Sisa Tagihan</td>
                        <td class="px-4 py-2 text-right font-bold text-amber-700">Rp {{ number_format($remainingAmount, 0, ',', '.') }}</td>
                    </tr>
                </tfoot>
            </x-ui.table>

            @if ($invoice->notes)
                <div class="px-4 py-3 border-t border-gray-100 text-sm text-gray-600">
                    <span class="font-medium">Catatan:</span> {{ $invoice->notes }}
                </div>
            @endif
        </x-ui.card>


        @if ($invoice->payments->isNotEmpty())
            <x-ui.card title="Riwayat Pembayaran">
                <x-ui.table>
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">No. Kwitansi</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Tanggal</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Metode</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500">Jumlah</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Catatan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($invoice->payments as $payment)
                            <tr>
                                <td class="px-4 py-3 font-mono text-gray-900">{{ $payment->payment_number }}</td>
                                <td class="px-4 py-3 text-gray-700">{{ $payment->paid_at?->format('d/m/Y H:i') }}</td>
                                <td class="px-4 py-3 text-gray-700">{{ $payment->paymentMethod?->name ?? 'Tunai' }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-gray-900">Rp {{ number_format($payment->amount, 0, ',', '.') }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $payment->notes ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </x-ui.card>
        @endif

        <x-rme.lab-workflow-panel :invoice="$invoice" :candidates="$labCaseCandidates" />

    </div>
</x-settings-shell>
