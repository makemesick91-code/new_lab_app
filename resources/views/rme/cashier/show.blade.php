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
        $carryOverInvoices = $payableSummary['carry_over_invoices'] ?? collect();
        $carryOverRemaining = $payableSummary['carry_over_remaining'] ?? 0;
        $totalPayable = $payableSummary['total_payable'] ?? $remainingAmount;
        $hasCarryOver = ($payableSummary['has_carry_over'] ?? false) && $carryOverInvoices->isNotEmpty();
        $paymentAllocation = session('payment_allocation');
    @endphp

    <div class="space-y-6">

        {{-- Page Header --}}
        <x-ui.page-header
            title="Detail Tagihan RME"
            subtitle="Ringkasan tagihan final dari kunjungan RME yang sudah difinalisasi dokter.">
            <x-slot:breadcrumb>Rekam Medis Elektronik</x-slot:breadcrumb>
            <x-slot:actions>
                <x-ui.button variant="secondary" :href="route('rme.cashier.index')">&larr; Kembali ke Daftar</x-ui.button>
                @if ($invoice->isPayable())
                    <x-ui.button variant="primary" :href="route('rme.cashier.payment.create', [$visit, $invoice])">
                        {{ $invoice->isPartial() ? 'Bayar Cicilan' : 'Bayar Sekarang' }}
                    </x-ui.button>
                @endif
                @if ($invoice->status === 'PAID')
                    <x-ui.button variant="secondary" :href="route('rme.cashier.receipt.show', [$visit, $invoice])">Lihat Kwitansi</x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.page-header>

        {{-- Invoice Header --}}
        <x-ui.card>
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-navy font-mono">{{ $invoice->invoice_number }}</h3>
                    <p class="text-sm text-ink-soft mt-1">Dibuat oleh {{ $invoice->cashier?->name }} &mdash; {{ $invoice->created_at?->format('d/m/Y H:i') }}</p>
                </div>
                <x-ui.badge :tone="$invoiceStatusTone[$invoice->status] ?? 'neutral'">
                    {{ $invoiceStatusLabels[$invoice->status] ?? $invoice->status }}
                </x-ui.badge>
            </div>
        </x-ui.card>

        {{-- Patient & Visit --}}
        @include('rme.cashier.partials.clinical-summary', ['visit' => $visit])

        @if ($hasCarryOver)
            <x-ui.card title="Piutang Kunjungan Sebelumnya">
                <p class="text-sm text-ink-soft mb-4">
                    Pembayaran dari kasir kontrol akan mengurangi piutang kunjungan sebelumnya terlebih dahulu (FIFO).
                </p>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-hairline text-sm">
                        <thead class="bg-navy-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium text-ink-soft">No. Kunjungan</th>
                                <th class="px-4 py-3 text-left font-medium text-ink-soft">Tanggal</th>
                                <th class="px-4 py-3 text-left font-medium text-ink-soft">No. Invoice</th>
                                <th class="px-4 py-3 text-right font-medium text-ink-soft">Total Invoice</th>
                                <th class="px-4 py-3 text-right font-medium text-ink-soft">Sudah Dibayar</th>
                                <th class="px-4 py-3 text-right font-medium text-ink-soft">Sisa Piutang</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-hairline">
                            @foreach ($carryOverInvoices as $parentInvoice)
                                @php
                                    $parentPaid = $parentInvoice->paidAmount();
                                    $parentRemaining = $parentInvoice->remainingAmount();
                                @endphp
                                <tr>
                                    <td class="px-4 py-3 font-mono text-navy">{{ $parentInvoice->clinicVisit?->visit_number ?? '-' }}</td>
                                    <td class="px-4 py-3 text-ink">{{ $parentInvoice->clinicVisit?->visit_date?->format('d/m/Y') ?? '-' }}</td>
                                    <td class="px-4 py-3 font-mono text-navy">{{ $parentInvoice->invoice_number }}</td>
                                    <td class="px-4 py-3 text-right text-ink">Rp {{ number_format($parentInvoice->grand_total, 0, ',', '.') }}</td>
                                    <td class="px-4 py-3 text-right text-success-700">Rp {{ number_format($parentPaid, 0, ',', '.') }}</td>
                                    <td class="px-4 py-3 text-right font-semibold text-warning-700">Rp {{ number_format($parentRemaining, 0, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>

            <x-ui.card title="Tagihan Kontrol Hari Ini">
                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div>
                        <dt class="text-ink-soft">No. Invoice Kontrol</dt>
                        <dd class="font-mono font-medium text-navy">{{ $invoice->invoice_number }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-soft">Total Invoice Kontrol</dt>
                        <dd class="font-semibold text-navy">Rp {{ number_format($invoice->grand_total, 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-soft">Sudah Dibayar</dt>
                        <dd class="font-semibold text-success-700">Rp {{ number_format($paidAmount, 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-soft">Sisa Tagihan Kontrol</dt>
                        <dd class="font-bold text-warning-700">Rp {{ number_format($remainingAmount, 0, ',', '.') }}</dd>
                    </div>
                </dl>
            </x-ui.card>

            <x-ui.card title="Total Harus Dibayar">
                <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                    <div class="rounded-lg border border-warning-100 bg-warning-50/60 px-4 py-3">
                        <dt class="text-ink-soft">Sisa piutang sebelumnya</dt>
                        <dd class="mt-1 text-lg font-bold text-warning-700">Rp {{ number_format($carryOverRemaining, 0, ',', '.') }}</dd>
                    </div>
                    <div class="rounded-lg border border-brand-100 bg-brand-50/60 px-4 py-3">
                        <dt class="text-ink-soft">Sisa tagihan kontrol</dt>
                        <dd class="mt-1 text-lg font-bold text-brand-700">Rp {{ number_format($remainingAmount, 0, ',', '.') }}</dd>
                    </div>
                    <div class="rounded-lg border border-hairline bg-navy-50 px-4 py-3">
                        <dt class="text-ink-soft">Total Harus Dibayar</dt>
                        <dd class="mt-1 text-xl font-bold text-navy">Rp {{ number_format($totalPayable, 0, ',', '.') }}</dd>
                    </div>
                </dl>
            </x-ui.card>
        @elseif ($visit->isFollowUpVisit())
            <x-ui.card>
                <p class="text-sm text-ink-soft">Tidak ada piutang kunjungan sebelumnya.</p>
            </x-ui.card>
        @endif

        @if (is_array($paymentAllocation) && (($paymentAllocation['allocated_to_parent'] ?? 0) > 0 || ($paymentAllocation['allocated_to_control'] ?? 0) > 0))
            <x-ui.card title="Alokasi Pembayaran">
                <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-ink-soft">Dibayarkan ke tagihan sebelumnya</dt>
                        <dd class="font-semibold text-warning-700">Rp {{ number_format($paymentAllocation['allocated_to_parent'] ?? 0, 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-soft">Dibayarkan ke tagihan kontrol</dt>
                        <dd class="font-semibold text-brand-700">Rp {{ number_format($paymentAllocation['allocated_to_control'] ?? 0, 0, ',', '.') }}</dd>
                    </div>
                </dl>
            </x-ui.card>
        @endif

        {{-- Invoice Items --}}
        <x-ui.card padding="">
            <div class="border-b border-hairline px-4 py-3">
                <h3 class="text-base font-semibold text-navy">Item Tagihan</h3>
            </div>
            <x-ui.table>
                <thead class="bg-navy-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">Deskripsi</th>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">Treatment</th>
                        <th class="px-4 py-3 text-right font-medium text-ink-soft">Qty</th>
                        <th class="px-4 py-3 text-right font-medium text-ink-soft">Harga Satuan</th>
                        <th class="px-4 py-3 text-right font-medium text-ink-soft">Diskon</th>
                        <th class="px-4 py-3 text-right font-medium text-ink-soft">Subtotal</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($invoice->items as $item)
                        <tr>
                            <td class="px-4 py-3 text-navy">{{ $item->description }}</td>
                            <td class="px-4 py-3 text-ink-soft text-xs">{{ $item->treatment?->name ?? '-' }}</td>
                            <td class="px-4 py-3 text-right text-ink">{{ $item->qty }}</td>
                            <td class="px-4 py-3 text-right text-ink">Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
                            <td class="px-4 py-3 text-right text-danger-700">{{ $item->discount > 0 ? 'Rp '.number_format($item->discount, 0, ',', '.') : '-' }}</td>
                            <td class="px-4 py-3 text-right font-medium text-navy">Rp {{ number_format($item->subtotal, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-navy-50">
                    <tr>
                        <td colspan="5" class="px-4 py-2 text-right text-sm text-ink-soft">Subtotal</td>
                        <td class="px-4 py-2 text-right font-medium text-navy">Rp {{ number_format($invoice->subtotal, 0, ',', '.') }}</td>
                    </tr>
                    <tr>
                        <td colspan="5" class="px-4 py-2 text-right text-sm text-danger-700">Total Diskon</td>
                        <td class="px-4 py-2 text-right font-medium text-danger-700">Rp {{ number_format($invoice->discount_total, 0, ',', '.') }}</td>
                    </tr>
                    <tr class="border-t-2 border-hairline">
                        <td colspan="5" class="px-4 py-3 text-right text-base font-semibold text-navy">Grand Total</td>
                        <td class="px-4 py-3 text-right text-base font-bold text-brand-700">Rp {{ number_format($invoice->grand_total, 0, ',', '.') }}</td>
                    </tr>
                    <tr>
                        <td colspan="5" class="px-4 py-2 text-right text-sm text-success-700">Sudah Dibayar</td>
                        <td class="px-4 py-2 text-right font-medium text-success-700">Rp {{ number_format($paidAmount, 0, ',', '.') }}</td>
                    </tr>
                    <tr>
                        <td colspan="5" class="px-4 py-2 text-right text-sm text-warning-700">Sisa Tagihan</td>
                        <td class="px-4 py-2 text-right font-bold text-warning-700">Rp {{ number_format($remainingAmount, 0, ',', '.') }}</td>
                    </tr>
                </tfoot>
            </x-ui.table>

            @if ($invoice->notes)
                <div class="px-4 py-3 border-t border-hairline text-sm text-ink-soft">
                    <span class="font-medium">Catatan:</span> {{ $invoice->notes }}
                </div>
            @endif
        </x-ui.card>


        @if ($invoice->payments->isNotEmpty())
            <x-ui.card title="Riwayat Pembayaran">
                <x-ui.table>
                    <thead class="bg-navy-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">No. Kwitansi</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Tanggal</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Metode</th>
                            <th class="px-4 py-3 text-right font-medium text-ink-soft">Jumlah</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">Catatan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($invoice->payments as $payment)
                            <tr>
                                <td class="px-4 py-3 font-mono text-navy">{{ $payment->payment_number }}</td>
                                <td class="px-4 py-3 text-ink">{{ $payment->paid_at?->format('d/m/Y H:i') }}</td>
                                <td class="px-4 py-3 text-ink">{{ $payment->paymentMethod?->name ?? 'Tunai' }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-navy">Rp {{ number_format($payment->amount, 0, ',', '.') }}</td>
                                <td class="px-4 py-3 text-ink-soft">{{ $payment->notes ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </x-ui.card>
        @endif

        <x-rme.lab-workflow-panel :invoice="$invoice" :candidates="$labCaseCandidates" />

    </div>
</x-settings-shell>
