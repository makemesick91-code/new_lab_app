<x-settings-shell title="Bayar Tagihan RME">
    @php
        $invoiceStatusLabels = [
            'DRAFT'  => 'Draft',
            'UNPAID'  => 'Belum Dibayar',
            'PARTIAL' => 'Cicilan / Sebagian',
            'PAID'    => 'Lunas',
            'NO_CHARGE' => 'Tanpa Biaya', // rule 217
            'VOID'    => 'Dibatalkan',
        ];
        $invoiceStatusTone = [
            'DRAFT'  => 'info',
            'UNPAID'  => 'warning',
            'PARTIAL' => 'warning',
            'PAID'    => 'success',
            'NO_CHARGE' => 'info',
            'VOID'    => 'danger',
        ];

        $paidAmount = $invoice->paidAmount();
        $remainingAmount = $invoice->remainingAmount();
        $carryOverInvoices = $payableSummary['carry_over_invoices'] ?? collect();
        $carryOverRemaining = $payableSummary['carry_over_remaining'] ?? 0;
        $totalPayable = $payableSummary['total_payable'] ?? $remainingAmount;
        $hasCarryOver = ($payableSummary['has_carry_over'] ?? false) && $carryOverInvoices->isNotEmpty();
        $maxAmount = $hasCarryOver ? $totalPayable : $remainingAmount;
        // Rule 191: after a version refusal the page shows a DIFFERENT total, so the
        // amount and receivable selection typed against the old total are not
        // restored. The cashier re-reads the bill and enters them again.
        $versionRefused = isset($errors) && $errors->has('invoice_versions');
        // Rule 205 (F3): the same holds after a stale-balance refusal — the page now
        // shows the current balances, so nothing typed against the old ones returns.
        $balanceRefused = isset($errors) && ($errors->has('invoice_balances') || $errors->has('payment_intent'));
        $viewRefused = $versionRefused || $balanceRefused;
        $defaultAmount = $viewRefused ? $maxAmount : old('amount', $maxAmount);
        $restoredReceivableIds = $viewRefused ? [] : (old('selected_receivable_ids', []) ?: []);
        // The ordinary button is disabled by the server while a hold applies; the
        // double-submit guard below may only ADD to that, never lift it.
        $ordinaryLocked = ! empty($paymentBlock) || ! empty($disputeHold) || ! empty($controlOnly['eligible']);

        // Sprint 62.2 — patient-level outstanding receivables (ordinary new visit).
        $outstandingReceivables = $outstandingReceivables ?? collect();
        $showSelectableReceivables = ! $hasCarryOver && $outstandingReceivables->isNotEmpty();
    @endphp

    <div class="space-y-6">

        {{-- Page Header --}}
        <x-ui.page-header
            title="Pembayaran Tagihan RME"
            subtitle="Input pembayaran penuh atau cicilan untuk tagihan RME yang sudah dibuat kasir.">
            <x-slot:breadcrumb>Rekam Medis Elektronik</x-slot:breadcrumb>
            <x-slot:actions>
                <x-ui.button variant="secondary" :href="route('rme.cashier.show', [$visit, $invoice])">&larr; Kembali</x-ui.button>
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

        {{-- Financial summary: total / paid / remaining / status (UIX-12 standard). --}}
        <x-rme.invoice-summary :invoice="$invoice" />

        {{-- Patient & Visit Context --}}
        <x-ui.card title="Informasi Kunjungan">
            <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-ink-soft">No. Kunjungan</dt>
                    <dd class="font-mono font-medium text-navy">{{ $visit->visit_number }}</dd>
                </div>
                <div>
                    <dt class="text-ink-soft">Tanggal Kunjungan</dt>
                    <dd class="text-navy">{{ $visit->visit_date?->format('d/m/Y') }}</dd>
                </div>
                <div>
                    <dt class="text-ink-soft">Pasien</dt>
                    <dd class="font-medium text-navy">{{ $visit->patient?->name }}</dd>
                </div>
                <div>
                    <dt class="text-ink-soft">Nomor WA</dt>
                    <dd class="text-navy">{{ $visit->patient?->whatsapp_number ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-ink-soft">No. Rekam Medis</dt>
                    <dd class="font-mono text-navy">{{ $visit->patient?->medical_record_number ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-ink-soft">Dokter</dt>
                    <dd class="text-navy">{{ $visit->doctor?->name ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-ink-soft">Grand Total</dt>
                    <dd class="text-base font-bold text-brand-700">Rp {{ number_format($invoice->grand_total, 0, ',', '.') }}</dd>
                </div>
                <div>
                    <dt class="text-ink-soft">Sudah Dibayar</dt>
                    <dd class="text-base font-semibold text-success-700">Rp {{ number_format($paidAmount, 0, ',', '.') }}</dd>
                </div>
                <div>
                    <dt class="text-ink-soft">Sisa Tagihan</dt>
                    <dd class="text-base font-bold text-warning-700">Rp {{ number_format($remainingAmount, 0, ',', '.') }}</dd>
                </div>
            </dl>
        </x-ui.card>

        @if ($hasCarryOver)
            <x-ui.card title="Piutang Kunjungan Sebelumnya">
                <x-ui.table>
                    <thead class="bg-navy-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">No. Kunjungan</th>
                            <th class="px-4 py-3 text-left font-medium text-ink-soft">No. Invoice</th>
                            <th class="px-4 py-3 text-right font-medium text-ink-soft">Sisa Piutang</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($carryOverInvoices as $parentInvoice)
                            <tr>
                                <td class="px-4 py-3 font-mono text-navy">{{ $parentInvoice->clinicVisit?->visit_number ?? '-' }}</td>
                                <td class="px-4 py-3 font-mono text-navy">{{ $parentInvoice->invoice_number }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums text-warning-700">Rp {{ number_format($parentInvoice->remainingAmount(), 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </x-ui.card>

            <x-ui.card title="Total Harus Dibayar">
                <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-ink-soft">Sisa piutang sebelumnya</dt>
                        <dd class="font-semibold text-warning-700">Rp {{ number_format($carryOverRemaining, 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-soft">Sisa tagihan kontrol</dt>
                        <dd class="font-semibold text-brand-700">Rp {{ number_format($remainingAmount, 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-soft">Total Harus Dibayar</dt>
                        <dd class="text-lg font-bold text-navy">Rp {{ number_format($totalPayable, 0, ',', '.') }}</dd>
                    </div>
                </dl>
            </x-ui.card>
        @endif

        {{-- Items Summary --}}
        <x-ui.card title="Ringkasan Tagihan">
            <x-ui.table>
                <thead class="bg-navy-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-ink-soft">Deskripsi</th>
                        <th class="px-4 py-3 text-right font-medium text-ink-soft">Qty</th>
                        <th class="px-4 py-3 text-right font-medium text-ink-soft">Harga Satuan</th>
                        <th class="px-4 py-3 text-right font-medium text-ink-soft">Subtotal</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($invoice->items as $item)
                        <tr>
                            <td class="px-4 py-3 text-navy">{{ $item->description }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-ink">{{ $item->qty }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-ink">Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
                            <td class="px-4 py-3 text-right font-medium tabular-nums text-navy">Rp {{ number_format($item->subtotal, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-navy-50">
                    @if ($invoice->discount_total > 0)
                        <tr>
                            <td colspan="3" class="px-4 py-2 text-right text-sm text-danger-700">Total Diskon</td>
                            <td class="px-4 py-2 text-right font-medium tabular-nums text-danger-700">- Rp {{ number_format($invoice->discount_total, 0, ',', '.') }}</td>
                        </tr>
                    @endif
                    <tr class="border-t-2 border-hairline">
                        <td colspan="3" class="px-4 py-3 text-right text-base font-semibold text-navy">Grand Total</td>
                        <td class="px-4 py-3 text-right text-base font-bold tabular-nums text-brand-700">Rp {{ number_format($invoice->grand_total, 0, ',', '.') }}</td>
                    </tr>
                    <tr>
                        <td colspan="3" class="px-4 py-2 text-right text-sm text-success-700">Sudah Dibayar</td>
                        <td class="px-4 py-2 text-right font-medium tabular-nums text-success-700">Rp {{ number_format($paidAmount, 0, ',', '.') }}</td>
                    </tr>
                    <tr>
                        <td colspan="3" class="px-4 py-2 text-right text-sm text-warning-700">Sisa Tagihan</td>
                        <td class="px-4 py-2 text-right font-bold tabular-nums text-warning-700">Rp {{ number_format($remainingAmount, 0, ',', '.') }}</td>
                    </tr>
                </tfoot>
            </x-ui.table>
        </x-ui.card>

        {{-- Payment Form --}}
        <x-ui.card title="Form Pembayaran">
            <p class="mb-5 rounded-md border border-brand-100 bg-brand-50 px-3 py-2 text-xs text-brand-700">
                @if ($hasCarryOver)
                    Pembayaran kontrol dialokasikan ke piutang kunjungan sebelumnya terlebih dahulu, lalu sisa masuk ke tagihan kontrol hari ini.
                    Jumlah bayar tidak boleh melebihi total yang harus dibayar.
                @else
                    Pembayaran dapat dicatat sebagai cicilan atau pelunasan. Jumlah bayar tidak boleh melebihi sisa tagihan.
                @endif
            </p>

            <form method="POST" action="{{ route('rme.cashier.payment.store', [$visit, $invoice]) }}" class="space-y-5"
                data-payment-form
                x-on:submit="if (submitting) { $event.preventDefault(); return; } submitting = true"
                x-data="{
                    submitting: false,
                    baseAmount: {{ (float) $maxAmount }},
                    selected: {},
                    get selectedTotal() { return Object.values(this.selected).reduce((sum, value) => sum + value, 0); },
                    get totalPayable() { return this.baseAmount + this.selectedTotal; },
                    toggle(id, amount, checked) {
                        if (checked) { this.selected[id] = amount; } else { delete this.selected[id]; }
                        if (this.$refs.amount) { this.$refs.amount.value = this.totalPayable; }
                    },
                }">
                @csrf

                {{--
                    ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-1 (rule 191): the
                    correction version of EVERY invoice this page displays. The payment
                    service refuses to settle an invoice whose version is not the one
                    listed here. Read from the current rows on every render and never
                    from old(), so a page re-rendered after a refusal carries the
                    version it now shows.
                --}}
                <input type="hidden" name="invoice_versions[{{ $invoice->id }}]" value="{{ (int) $invoice->correction_version }}">
                @foreach ($carryOverInvoices as $versionedInvoice)
                    <input type="hidden" name="invoice_versions[{{ $versionedInvoice->id }}]" value="{{ (int) $versionedInvoice->correction_version }}">
                @endforeach
                @foreach ($outstandingReceivables as $versionedInvoice)
                    <input type="hidden" name="invoice_versions[{{ $versionedInvoice->id }}]" value="{{ (int) $versionedInvoice->correction_version }}">
                @endforeach

                {{--
                    FIX-RME-PAYMENT-SUBMISSION-IDEMPOTENCY-1 (rule 205). payment_intent is
                    the server-issued identity of THIS form: submitting it twice (double
                    click, retry after a lost response, "back" and resubmit) records the
                    payment once and answers the repeat with the original receipt.
                    payment_plan and invoice_balances say what this page displayed; the
                    payment service settles exactly these balances under the row locks,
                    or nothing. All read from the current rows on every render.
                --}}
                <input type="hidden" name="payment_intent" value="{{ $paymentIntent }}">
                <input type="hidden" name="payment_plan" value="{{ $paymentPlan }}">
                <input type="hidden" name="invoice_balances[{{ $invoice->id }}]" value="{{ \App\Modules\RmeInvoice\Support\RmePaymentDeclaredView::render($remainingAmount) }}">
                @foreach ($carryOverInvoices as $balancedInvoice)
                    <input type="hidden" name="invoice_balances[{{ $balancedInvoice->id }}]" value="{{ \App\Modules\RmeInvoice\Support\RmePaymentDeclaredView::render($balancedInvoice->remainingAmount()) }}">
                @endforeach
                @foreach ($outstandingReceivables as $balancedInvoice)
                    <input type="hidden" name="invoice_balances[{{ $balancedInvoice->id }}]" value="{{ \App\Modules\RmeInvoice\Support\RmePaymentDeclaredView::render($balancedInvoice->remainingAmount()) }}">
                @endforeach

                @error('invoice_versions')
                    <div class="rounded-md border border-danger bg-danger-50 p-3 text-sm text-danger-700" role="alert" data-payment-invoice-version-error>
                        {{ $message }}
                    </div>
                @enderror
                @error('invoice_balances')
                    <div class="rounded-md border border-danger bg-danger-50 p-3 text-sm text-danger-700" role="alert" data-payment-stale-balance-error>
                        {{ $message }}
                    </div>
                @enderror
                @error('payment_intent')
                    <div class="rounded-md border border-danger bg-danger-50 p-3 text-sm text-danger-700" role="alert" data-payment-intent-error>
                        {{ $message }}
                    </div>
                @enderror

                {{--
                    FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — owner decision D10. An invoice
                    with an active (submitted / approved, not yet applied) correction
                    accepts no payment. The payment service refuses it under the invoice
                    row lock; this banner only explains why the button is disabled.
                --}}
                @if (! empty($paymentBlock))
                    <x-ui.alert variant="warning" title="Pembayaran ditahan" data-payment-blocked-by-correction>
                        Tagihan sedang dalam proses koreksi. Pembayaran dapat dilanjutkan setelah proses koreksi selesai.
                    </x-ui.alert>
                @endif
                {{--
                    IMPLEMENTATION-RME-BILLING-DISPUTE-ESCALATION-OPS-OQ-1 — owner
                    OPS-OQ-1 (rule 203). An invoice under an active billing dispute is
                    not collected; the payment service refuses it under the invoice row
                    lock. This banner only explains why the button is disabled.
                --}}
                @if (! empty($disputeHold))
                    <x-ui.alert variant="danger" title="Pembayaran ditahan — tagihan dalam peninjauan" data-payment-held-by-dispute>
                        Tagihan ini sedang dalam peninjauan karena terdapat ketidaksesuaian nominal
                        ({{ $disputeHold->dispute_number }}). Pembayaran sementara tidak dapat diproses.
                        Silakan hubungi penanggung jawab cabang. Jangan menagih nominal yang diketahui salah,
                        dan jangan menahan pasien.
                    </x-ui.alert>
                @endif
                @error('payment_blocked')
                    <div class="rounded-md border border-warning bg-warning-50 p-3 text-sm text-warning-700" role="alert" data-payment-blocked-error>
                        {{ $message }}
                    </div>
                @enderror

                @if ($showSelectableReceivables)
                    {{-- Sprint 62.2 — patient outstanding receivables (opt-in collection) --}}
                    <div class="rounded-md border border-warning-100 bg-warning-50 p-4 space-y-3">
                        <div>
                            <p class="text-sm font-medium text-warning-700">Piutang Sebelumnya</p>
                            <p class="text-xs text-warning-700">Pasien ini memiliki tagihan kunjungan sebelumnya yang belum lunas. Centang untuk ikut ditagih bersama pembayaran hari ini. Tagihan lama tetap menjadi invoice terpisah (tidak digabung ke total visit ini).</p>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-warning-100 text-sm">
                                <thead>
                                    <tr class="text-left text-xs uppercase tracking-wide text-warning-700">
                                        <th class="px-3 py-2">Pilih</th>
                                        <th class="px-3 py-2">No. Kunjungan</th>
                                        <th class="px-3 py-2">Tanggal</th>
                                        <th class="px-3 py-2">Cabang</th>
                                        <th class="px-3 py-2">No. Invoice</th>
                                        <th class="px-3 py-2 text-right">Total</th>
                                        <th class="px-3 py-2 text-right">Sudah Dibayar</th>
                                        <th class="px-3 py-2 text-right">Sisa Piutang</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-warning-100">
                                    @foreach ($outstandingReceivables as $receivable)
                                        <tr>
                                            <td class="px-3 py-2">
                                                <input type="checkbox" name="selected_receivable_ids[]" value="{{ $receivable->id }}"
                                                    @checked(in_array($receivable->id, $restoredReceivableIds))
                                                    x-on:change="toggle({{ $receivable->id }}, {{ (float) $receivable->remainingAmount() }}, $event.target.checked)"
                                                    class="rounded border-hairline text-brand-600 focus:ring-brand-500">
                                            </td>
                                            <td class="px-3 py-2 font-mono text-navy">{{ $receivable->clinicVisit?->visit_number ?? '-' }}</td>
                                            <td class="px-3 py-2 text-ink">{{ $receivable->clinicVisit?->visit_date?->format('d/m/Y') ?? '-' }}</td>
                                            <td class="px-3 py-2 text-ink">{{ $receivable->branch?->name ?? '-' }}</td>
                                            <td class="px-3 py-2 font-mono text-navy">{{ $receivable->invoice_number }}</td>
                                            <td class="px-3 py-2 text-right text-ink">Rp {{ number_format($receivable->grand_total, 0, ',', '.') }}</td>
                                            <td class="px-3 py-2 text-right text-success-700">Rp {{ number_format($receivable->paidAmount(), 0, ',', '.') }}</td>
                                            <td class="px-3 py-2 text-right font-semibold text-warning-700">Rp {{ number_format($receivable->remainingAmount(), 0, ',', '.') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <dl class="grid grid-cols-1 gap-3 border-t border-warning-100 pt-3 text-sm sm:grid-cols-3">
                            <div>
                                <dt class="text-warning-700">Tagihan Visit Saat Ini</dt>
                                <dd class="font-semibold text-brand-700">Rp {{ number_format($remainingAmount, 0, ',', '.') }}</dd>
                            </div>
                            <div>
                                <dt class="text-warning-700">Total Dipilih dari Piutang Sebelumnya</dt>
                                <dd class="font-semibold text-warning-700">Rp <span x-text="selectedTotal.toLocaleString('id-ID')">0</span></dd>
                            </div>
                            <div>
                                <dt class="text-warning-700">Total Harus Dibayar</dt>
                                <dd class="text-lg font-bold text-navy">Rp <span x-text="totalPayable.toLocaleString('id-ID')">{{ number_format($remainingAmount, 0, ',', '.') }}</span></dd>
                            </div>
                        </dl>
                    </div>
                @endif

                {{-- Payment Method --}}
                <div>
                    <label for="payment_method_id" class="block text-sm font-medium text-ink mb-1">
                        Metode Pembayaran
                    </label>
                    <select name="payment_method_id" id="payment_method_id"
                        class="block w-full max-w-xs rounded-md border-hairline shadow-sm text-sm focus:ring-brand-500 focus:border-brand-500 @error('payment_method_id') border-danger @enderror">
                        <option value="">— Pilih Metode —</option>
                        @foreach ($paymentMethods as $method)
                            <option value="{{ $method->id }}" {{ old('payment_method_id') == $method->id ? 'selected' : '' }}>
                                {{ $method->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('payment_method_id')
                        <p class="mt-1 text-xs text-danger-700">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Amount --}}
                <div>
                    <label for="amount" class="block text-sm font-medium text-ink mb-1">
                        Jumlah Dibayar <span class="text-danger">*</span>
                    </label>
                    <input type="number" name="amount" id="amount" x-ref="amount"
                        value="{{ $defaultAmount }}"
                        step="0.01" min="0.01" max="{{ $maxAmount }}"
                        @if ($showSelectableReceivables) x-bind:max="totalPayable" @endif
                        class="block w-full max-w-xs rounded-md border-hairline shadow-sm text-sm focus:ring-brand-500 focus:border-brand-500 @error('amount') border-danger @enderror">
                    <p class="mt-1 text-xs text-ink-soft">
                        @if ($hasCarryOver)
                            Maksimal total yang harus dibayar: Rp {{ number_format($maxAmount, 0, ',', '.') }}.
                        @elseif ($showSelectableReceivables)
                            Maksimal total yang harus dibayar (visit ini + piutang terpilih): Rp <span x-text="totalPayable.toLocaleString('id-ID')">{{ number_format($remainingAmount, 0, ',', '.') }}</span>.
                        @else
                            Maksimal sisa tagihan: Rp {{ number_format($maxAmount, 0, ',', '.') }}.
                        @endif
                    </p>
                    @error('amount')
                        <p class="mt-1 text-xs text-danger-700">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Paid At --}}
                <div>
                    <label for="paid_at" class="block text-sm font-medium text-ink mb-1">
                        Tanggal &amp; Waktu Bayar <span class="text-danger">*</span>
                    </label>
                    <input type="datetime-local" name="paid_at" id="paid_at"
                        value="{{ old('paid_at', now()->format('Y-m-d\TH:i')) }}"
                        class="block w-full max-w-xs rounded-md border-hairline shadow-sm text-sm focus:ring-brand-500 focus:border-brand-500 @error('paid_at') border-danger @enderror">
                    @error('paid_at')
                        <p class="mt-1 text-xs text-danger-700">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Reference Number --}}
                <div>
                    <label for="reference_number" class="block text-sm font-medium text-ink mb-1">
                        No. Referensi <span class="text-ink-muted text-xs">(opsional, untuk QRIS/transfer)</span>
                    </label>
                    <input type="text" name="reference_number" id="reference_number"
                        value="{{ old('reference_number') }}"
                        placeholder="Nomor transaksi / approval"
                        class="block w-full max-w-xs rounded-md border-hairline shadow-sm text-sm focus:ring-brand-500 focus:border-brand-500 @error('reference_number') border-danger @enderror">
                    @error('reference_number')
                        <p class="mt-1 text-xs text-danger-700">{{ $message }}</p>
                    @enderror
                </div>

                {{-- FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-03 — the
                     consent block that stood here is REMOVED.

                     It used to show the cashier whether a PERSETUJUAN TINDAKAN
                     MEDIS had been signed, plus two acknowledgement checkboxes,
                     because consent was the payment gate. Consent is now taken at
                     the START of the doctor's examination and gates RME AUTHORING,
                     not payment. The cashier therefore has nothing to verify,
                     acknowledge or wait for here, and consent must never be shown
                     on this page as a reason a payment cannot proceed. --}}


                {{-- Notes --}}
                <div>
                    <label for="notes" class="block text-sm font-medium text-ink mb-1">
                        Catatan <span class="text-ink-muted text-xs">(opsional)</span>
                    </label>
                    <textarea name="notes" id="notes" rows="2"
                        class="block w-full max-w-xs rounded-md border-hairline shadow-sm text-sm focus:ring-brand-500 focus:border-brand-500 @error('notes') border-danger @enderror"
                        >{{ old('notes') }}</textarea>
                    @error('notes')
                        <p class="mt-1 text-xs text-danger-700">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Actions --}}
                <div class="flex items-center gap-3 pt-2">
                    <x-ui.button type="submit" variant="primary" :disabled="$ordinaryLocked"
                        data-payment-submit
                        x-bind:disabled="submitting || {{ $ordinaryLocked ? 'true' : 'false' }}"
                        x-bind:aria-busy="submitting ? 'true' : 'false'">
                        <span x-show="! submitting">Konfirmasi Pembayaran</span>
                        <span x-show="submitting" x-cloak>Memproses pembayaran…</span>
                    </x-ui.button>
                    <x-ui.button variant="neutral" :href="route('rme.cashier.show', [$visit, $invoice])">Batal</x-ui.button>
                </div>
                <p class="text-xs text-ink-soft" data-payment-retry-guidance>
                    Tekan Konfirmasi satu kali. Jika koneksi terputus setelah menekan tombol, jangan membuka formulir pembayaran baru:
                    kirim ulang formulir ini (muat ulang halaman ini) — sistem mengenali formulir yang sama dan tidak akan mencatat pembayaran dua kali.
                    Periksa riwayat pembayaran tagihan sebelum mencatat pembayaran lain.
                </p>
            </form>

            {{--
                HARDENING-RME-BILLING-DISPUTE-BRANCH-REVIEWER-CONTROL-PAYMENT-1 — owner
                OPS-OQ-1b (rule 204). While EVERY outstanding origin invoice of this
                Kontrol visit is under an active billing dispute, the Kontrol invoice
                may be settled on its own. This is a SEPARATE, explicitly confirmed
                action: it carries no origin id and no allocation, only this
                invoice's version. The payment service re-derives the chain, re-checks
                every hold and the eligibility rule under the row locks, and allocates
                to this invoice only — the origin keeps its full outstanding balance
                and its dispute. The ordinary button above stays disabled because the
                origin cannot be collected while it is in review.
            --}}
            @if (! empty($controlOnly['eligible']) && empty($paymentBlock) && empty($disputeHold))
                <div class="mt-6 space-y-4 rounded-md border border-danger-100 bg-danger-50 p-4" data-control-only-payment>
                    <div>
                        <p class="text-sm font-semibold text-danger-700">Tagihan kunjungan asal sedang dalam peninjauan dan tidak dapat ditagihkan.</p>
                        <p class="mt-1 text-xs text-danger-700">
                            Tagihan kontrol hari ini dapat dibayar tersendiri. Pembayaran ini dialokasikan hanya ke tagihan kontrol.
                            Tagihan kunjungan asal tidak berubah, sisa tagihannya tetap tercatat sebagai piutang, dan laporan
                            ketidaksesuaiannya tetap aktif sampai diselesaikan penanggung jawab.
                        </p>
                    </div>
                    <dl class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                        @foreach ($controlOnly['invoices'] as $index => $heldOrigin)
                            <div data-control-only-held-origin="{{ $heldOrigin->id }}">
                                <dt class="text-xs text-ink-soft">
                                    Tagihan asal {{ $heldOrigin->invoice_number }}
                                    @if (! empty($controlOnly['disputes'][$index]))
                                        ({{ $controlOnly['disputes'][$index]->dispute_number }})
                                    @endif
                                </dt>
                                <dd class="font-semibold text-danger-700">
                                    Dialokasikan dari pembayaran ini: Rp0 · Sisa tetap Rp {{ number_format($heldOrigin->remainingAmount(), 0, ',', '.') }}
                                </dd>
                            </div>
                        @endforeach
                        <div>
                            <dt class="text-xs text-ink-soft">Tagihan kontrol {{ $invoice->invoice_number }}</dt>
                            <dd class="font-semibold text-brand-700">Maksimal dibayar: Rp {{ number_format($remainingAmount, 0, ',', '.') }}</dd>
                        </div>
                    </dl>

                    <form method="POST" action="{{ route('rme.cashier.payment.store', [$visit, $invoice]) }}" class="space-y-4"
                        data-control-only-payment-form
                        x-data="{ submitting: false }"
                        x-on:submit="if (submitting) { $event.preventDefault(); return; } submitting = true">
                        @csrf
                        <input type="hidden" name="payment_scope" value="{{ \App\Modules\RmeInvoice\Services\RmePaymentService::PAYMENT_SCOPE_CONTROL_ONLY }}">
                        <input type="hidden" name="invoice_versions[{{ $invoice->id }}]" value="{{ (int) $invoice->correction_version }}">
                        {{-- Rule 205: its own intent (a different payment from the ordinary form), and only this invoice's balance. --}}
                        <input type="hidden" name="payment_intent" value="{{ $controlOnlyIntent }}">
                        <input type="hidden" name="payment_plan" value="{{ \App\Modules\RmeInvoice\Support\RmePaymentDeclaredView::PLAN_CONTROL_ONLY }}">
                        <input type="hidden" name="invoice_balances[{{ $invoice->id }}]" value="{{ \App\Modules\RmeInvoice\Support\RmePaymentDeclaredView::render($remainingAmount) }}">

                        @error('payment_scope')
                            <div class="rounded-md border border-danger bg-white p-3 text-sm text-danger-700" role="alert">{{ $message }}</div>
                        @enderror

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label for="co_payment_method_id" class="mb-1 block text-sm font-medium text-ink">Metode Pembayaran</label>
                                <select name="payment_method_id" id="co_payment_method_id"
                                    class="block w-full rounded-md border-hairline text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                                    <option value="">— Pilih Metode —</option>
                                    @foreach ($paymentMethods as $method)
                                        <option value="{{ $method->id }}">{{ $method->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="co_amount" class="mb-1 block text-sm font-medium text-ink">Jumlah Dibayar <span class="text-danger">*</span></label>
                                <input type="number" name="amount" id="co_amount" value="{{ $remainingAmount }}"
                                    step="0.01" min="0.01" max="{{ $remainingAmount }}"
                                    class="block w-full rounded-md border-hairline text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                            </div>
                            <div>
                                <label for="co_paid_at" class="mb-1 block text-sm font-medium text-ink">Tanggal &amp; Waktu Bayar <span class="text-danger">*</span></label>
                                <input type="datetime-local" name="paid_at" id="co_paid_at" value="{{ now()->format('Y-m-d\TH:i') }}"
                                    class="block w-full rounded-md border-hairline text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                            </div>
                            <div>
                                <label for="co_reference_number" class="mb-1 block text-sm font-medium text-ink">No. Referensi <span class="text-xs text-ink-muted">(opsional)</span></label>
                                <input type="text" name="reference_number" id="co_reference_number"
                                    class="block w-full rounded-md border-hairline text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                            </div>
                        </div>

                        <label class="flex items-start gap-2 text-sm text-ink">
                            <input type="checkbox" name="confirm_control_only" value="1" class="mt-1 rounded border-hairline">
                            <span>Saya memahami pembayaran ini hanya untuk tagihan kontrol. Tagihan kunjungan asal tetap ditahan dan tidak berkurang.</span>
                        </label>
                        @error('confirm_control_only')
                            <p class="text-xs text-danger-700">{{ $message }}</p>
                        @enderror

                        <x-ui.button type="submit" variant="danger"
                            data-control-only-payment-submit
                            x-bind:disabled="submitting"
                            x-bind:aria-busy="submitting ? 'true' : 'false'">
                            <span x-show="! submitting">Bayar Tagihan Kontrol Saja</span>
                            <span x-show="submitting" x-cloak>Memproses pembayaran…</span>
                        </x-ui.button>
                    </form>
                </div>
            @endif
        </x-ui.card>

    </div>
</x-settings-shell>
