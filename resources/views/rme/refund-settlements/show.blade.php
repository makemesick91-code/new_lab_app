@php
    use App\Modules\RmeInvoice\Support\RmeRefundSettlementMethod;
    use App\Modules\RmeInvoice\Support\RmeRefundSettlementStatus;
@endphp
{{-- FEATURE-RME-REFUND-SETTLEMENT-1 (rule 216) — one refund payout. The buttons come
     from RmeRefundSettlementService::actionsFor(); the service refuses every action
     again on submit, so a hidden button is never the boundary. --}}
<x-settings-shell title="Pembayaran Refund">
    <div class="space-y-6">
        <x-ui.page-header :title="'Pembayaran Refund '.$settlement->settlement_number" subtitle="Pencatatan bahwa uang refund yang DISETUJUI benar-benar dibayarkan. Persetujuan bukan pembayaran.">
            <x-slot:breadcrumb>Rekam Medis Elektronik · Kasir</x-slot:breadcrumb>
            <x-slot:actions>
                <x-ui.button variant="secondary" :href="route('rme.refund-settlements.index')">&larr; Daftar Pembayaran</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif
        @if ($errors->any())
            <x-ui.alert variant="danger" data-settlement-errors>
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        @include('rme.refund-settlements.partials.detail', [
            'evidenceUrl' => $actions['view_evidence'] ? route('rme.refund-settlements.evidence', $settlement->id) : null,
        ])

        @if ($actions['confirm'])
            <x-ui.card data-settlement-confirm>
                <h2 class="text-sm font-semibold text-ink">Konfirmasi: uang TELAH dibayarkan</h2>
                <p class="mt-1 text-xs text-ink-soft">Waktu pencatatan diambil dari server (WITA) dan tidak dapat diisi mundur. Jumlah tidak dapat diubah: refund selalu seluruh kewajiban.</p>
                <form method="POST" action="{{ route('rme.refund-settlements.confirm', $settlement->id) }}" enctype="multipart/form-data" class="mt-3 space-y-3">
                    @csrf
                    @if ($settlement->method === RmeRefundSettlementMethod::BANK_TRANSFER)
                        <x-ui.input name="disbursement_reference" label="Referensi transfer dari bank (6–60 karakter)" :value="old('disbursement_reference')" required maxlength="60" />
                        <x-ui.input name="destination_bank_name" label="Nama bank tujuan" :value="old('destination_bank_name')" required maxlength="60" />
                        <x-ui.input name="destination_account_last4" label="4 digit terakhir rekening tujuan (jangan isi nomor lengkap)" :value="old('destination_account_last4')" required maxlength="4" inputmode="numeric" />
                    @else
                        <x-ui.input name="disbursement_reference" label="Nomor tanda terima serah terima tunai (6–60 karakter)" :value="old('disbursement_reference')" required maxlength="60" />
                        <x-ui.select name="recipient_relation" label="Penerima uang" required>
                            <option value="">Pilih penerima</option>
                            @foreach (RmeRefundSettlementMethod::RECIPIENT_LABELS as $code => $label)
                                <option value="{{ $code }}" @selected(old('recipient_relation') === $code)>{{ $label }}</option>
                            @endforeach
                        </x-ui.select>
                    @endif
                    <div>
                        <label class="block text-sm font-medium text-ink" for="settlement-evidence">
                            {{ $settlement->method === RmeRefundSettlementMethod::CASH ? 'Tanda terima bertanda tangan penerima (PDF/JPG/PNG)' : 'Bukti transfer dari bank (PDF/JPG/PNG)' }}
                        </label>
                        <input id="settlement-evidence" type="file" name="evidence" accept="application/pdf,image/jpeg,image/png" required class="mt-1 block w-full text-sm">
                    </div>
                    <label class="flex items-start gap-2 text-sm">
                        <input type="checkbox" name="attest_disbursed" value="1" class="mt-1" required>
                        <span>Saya menyatakan uang sebesar jumlah di atas TELAH diterima penerima, dan bukti yang dilampirkan adalah bukti asli pembayaran ini.</span>
                    </label>
                    <x-ui.button type="submit">Catat Telah Dibayarkan</x-ui.button>
                </form>
            </x-ui.card>
        @endif

        @if ($actions['report_unknown'])
            <x-ui.card data-settlement-report-unknown>
                <form method="POST" action="{{ route('rme.refund-settlements.outcome-unknown', $settlement->id) }}" class="space-y-2">
                    @csrf
                    <x-ui.textarea name="outcome_note" label="Hasil pembayaran tidak pasti — jelaskan (10–1000 karakter)" rows="2" required />
                    <x-ui.button type="submit" variant="secondary">Laporkan Hasil Tidak Pasti</x-ui.button>
                </form>
            </x-ui.card>
        @endif

        @if ($actions['abort'])
            <x-ui.card data-settlement-abort>
                <form method="POST" action="{{ route('rme.refund-settlements.abort', $settlement->id) }}" enctype="multipart/form-data" class="space-y-2">
                    @csrf
                    <x-ui.textarea name="abort_reason" label="Alasan pembatalan pembayaran (10–1000 karakter)" rows="2" required />
                    @if ($settlement->status === RmeRefundSettlementStatus::OUTCOME_UNKNOWN)
                        <div>
                            <label class="block text-sm font-medium text-ink" for="settlement-abort-evidence">Bukti bahwa uang tidak keluar (mis. mutasi rekening) — wajib</label>
                            <input id="settlement-abort-evidence" type="file" name="evidence" accept="application/pdf,image/jpeg,image/png" required class="mt-1 block w-full text-sm">
                        </div>
                    @endif
                    <label class="flex items-start gap-2 text-sm">
                        <input type="checkbox" name="attest_not_disbursed" value="1" class="mt-1" required>
                        <span>Saya menyatakan uang refund ini TIDAK dibayarkan kepada penerima.</span>
                    </label>
                    <x-ui.button type="submit" variant="danger">Batalkan Pembayaran</x-ui.button>
                </form>
            </x-ui.card>
        @endif
    </div>
</x-settings-shell>
