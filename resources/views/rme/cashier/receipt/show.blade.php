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
        <x-ui.page-header
            title="Kwitansi Pembayaran RME"
            subtitle="Bukti pembayaran tagihan kunjungan pasien."
            class="print:hidden">
            <x-slot:breadcrumb>Rekam Medis Elektronik</x-slot:breadcrumb>
            <x-slot:actions>
                <x-ui.button variant="secondary" :href="route('rme.cashier.show', [$visit, $invoice])">&larr; Kembali ke Tagihan</x-ui.button>
                <x-ui.button variant="primary" onclick="window.print()">Cetak Kwitansi</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        {{-- Receipt Body --}}
        <div class="max-w-2xl mx-auto space-y-4 print:max-w-full print:space-y-3" id="receipt-body">

            {{-- Clinic / Receipt Header --}}
            <x-ui.card>
                <div class="text-center border-b border-hairline pb-4 mb-5">
                    <x-brand.daengtisia-logo class="mx-auto mb-3 h-14 w-auto" />
                    <h2 class="text-xl font-bold text-navy">{{ $invoice->branch?->name ?? config('app.name') }}</h2>
                    <p class="mt-1 text-sm font-semibold uppercase tracking-wide text-brand-700">Kwitansi Pembayaran RME</p>
                </div>

                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-ink-soft">No. Kwitansi</dt>
                        <dd class="mt-1 font-mono font-semibold text-navy">{{ $payment?->payment_number ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-ink-soft">Tanggal Bayar</dt>
                        <dd class="mt-1 font-medium text-navy">{{ $payment?->paid_at?->format('d/m/Y H:i') ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-ink-soft">No. Invoice</dt>
                        <dd class="mt-1 font-mono text-navy">{{ $invoice->invoice_number }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-ink-soft">Kasir</dt>
                        <dd class="mt-1 text-navy">{{ $payment?->cashier?->name ?? $invoice->cashier?->name ?? '-' }}</dd>
                    </div>
                </dl>
            </x-ui.card>

            {{-- Patient & Visit --}}
            <x-ui.card title="Data Pasien">
                <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-ink-soft">Nama Pasien</dt>
                        <dd class="mt-1 font-medium text-navy">{{ $visit->patient?->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-ink-soft">No. Rekam Medis</dt>
                        <dd class="mt-1 font-mono text-navy">{{ $visit->patient?->medical_record_number ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-ink-soft">No. Kunjungan</dt>
                        <dd class="mt-1 font-mono text-navy">{{ $visit->visit_number }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-ink-soft">Tanggal Kunjungan</dt>
                        <dd class="mt-1 text-navy">{{ $visit->visit_date?->format('d/m/Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-ink-soft">Dokter</dt>
                        <dd class="mt-1 text-navy">{{ $visit->doctor?->name }}</dd>
                    </div>
                </dl>
            </x-ui.card>

            {{-- Treatment Items --}}
            <x-ui.card title="Rincian Tindakan">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b-2 border-hairline">
                                <th class="pb-2 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Tindakan</th>
                                <th class="pb-2 text-right text-xs font-semibold uppercase tracking-wide text-ink-soft">Qty</th>
                                <th class="pb-2 text-right text-xs font-semibold uppercase tracking-wide text-ink-soft">Harga Satuan</th>
                                <th class="pb-2 text-right text-xs font-semibold uppercase tracking-wide text-ink-soft">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-hairline">
                            @foreach ($invoice->items as $item)
                                <tr>
                                    <td class="py-2 text-navy">{{ $item->description }}</td>
                                    <td class="py-2 text-right text-ink">{{ $item->qty }}</td>
                                    <td class="py-2 text-right text-ink">Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
                                    <td class="py-2 text-right font-medium text-navy">Rp {{ number_format($item->subtotal, 0, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            @if ($invoice->discount_total > 0)
                                <tr>
                                    <td colspan="3" class="pt-3 text-right text-sm text-ink-soft">Diskon</td>
                                    <td class="pt-3 text-right text-danger-700">- Rp {{ number_format($invoice->discount_total, 0, ',', '.') }}</td>
                                </tr>
                            @endif
                            <tr class="border-t-2 border-hairline">
                                <td colspan="3" class="py-3 text-right font-semibold text-navy">Total</td>
                                <td class="py-3 text-right font-bold text-navy">Rp {{ number_format($invoice->grand_total, 0, ',', '.') }}</td>
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
                    @if ($hasPaymentAllocation ?? false)
                        <div class="rounded-lg border border-warning-100 bg-warning-50 px-4 py-3 space-y-2">
                            <p class="text-sm font-semibold text-warning-700">Alokasi Pembayaran</p>
                            <div class="flex justify-between">
                                <span class="text-ink-soft">Dibayarkan ke tagihan sebelumnya</span>
                                <span class="font-semibold text-warning-700">Rp {{ number_format($allocatedToParent, 0, ',', '.') }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-ink-soft">Dibayarkan ke tagihan kontrol</span>
                                <span class="font-semibold text-brand-700">Rp {{ number_format($allocatedToControl, 0, ',', '.') }}</span>
                            </div>
                        </div>
                    @endif
                    <div class="flex justify-between">
                        <span class="text-ink-soft">Metode Pembayaran</span>
                        <span class="font-medium text-navy">{{ $payment?->paymentMethod?->name ?? 'Tunai' }}</span>
                    </div>
                    @if ($payment?->reference_number)
                        <div class="flex justify-between">
                            <span class="text-ink-soft">No. Referensi</span>
                            <span class="font-mono text-navy">{{ $payment->reference_number }}</span>
                        </div>
                    @endif
                    <div class="flex items-center justify-between rounded-lg border border-success-100 bg-success-50 px-4 py-3">
                        <span class="text-base font-semibold text-success-700">Jumlah Dibayar</span>
                        <span class="text-xl font-bold text-success-700">Rp {{ number_format(($allocatedToParent ?? 0) + ($allocatedToControl ?? 0) > 0 ? (($allocatedToParent ?? 0) + ($allocatedToControl ?? 0)) : ($payment?->amount ?? $invoice->grand_total), 0, ',', '.') }}</span>
                    </div>
                </div>
            </x-ui.card>

            {{-- Stamp / Footer --}}
            <div class="py-4 text-center text-xs text-ink-muted">
                <p>Dicetak pada {{ now()->format('d/m/Y H:i') }}</p>
                <p class="mt-3 inline-block rounded border border-hairline px-6 py-1 text-base font-bold tracking-widest text-ink-soft">LUNAS</p>
            </div>

        </div>
    </div>

</x-settings-shell>
