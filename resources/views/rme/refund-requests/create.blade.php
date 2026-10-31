@php
    use App\Modules\ClinicOversight\Support\OversightFormat;
    use App\Modules\RmeInvoice\Support\RmeRefundRequestIntent;
@endphp
{{-- FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (rule 215) — file a refund
     request. The amount is the whole obligation (RF-4), shown read-only and
     re-derived by the server; no bank account, NIK or identity is asked. --}}
<x-settings-shell title="Ajukan Refund">
    <div class="space-y-6">
        <x-ui.page-header title="Ajukan Refund" subtitle="Permintaan pengembalian kelebihan bayar. Pengajuan tidak membayarkan uang; pembayaran adalah langkah terpisah setelah disetujui.">
            <x-slot:breadcrumb>Rekam Medis Elektronik · Kasir</x-slot:breadcrumb>
        </x-ui.page-header>

        @if ($errors->any())
            <x-ui.alert variant="danger" data-refund-errors>
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        <x-ui.card>
            <dl class="grid grid-cols-1 gap-3 text-sm md:grid-cols-3">
                <div><dt class="text-ink-soft">Invoice</dt><dd class="font-mono">{{ $invoice->invoice_number }}</dd></div>
                <div><dt class="text-ink-soft">Kewajiban</dt><dd class="font-mono">{{ $obligation->obligation_number }}</dd></div>
                <div><dt class="text-ink-soft">Jumlah refund (seluruh kewajiban)</dt><dd class="font-semibold tabular-nums" data-refund-amount>{{ OversightFormat::rupiah($obligation->amount) }}</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.card>
            <form method="POST" action="{{ route('rme.refund-requests.store', [$visit->id, $invoice->id]) }}" class="space-y-4" data-refund-form>
                @csrf
                <input type="hidden" name="{{ RmeRefundRequestIntent::FIELD }}" value="{{ $intent }}">
                <input type="hidden" name="obligation_id" value="{{ $obligation->id }}">
                <input type="hidden" name="obligation_amount" value="{{ $declaredAmount }}">
                <input type="hidden" name="invoice_version" value="{{ (int) ($invoice->correction_version ?? 0) }}">

                <x-ui.input name="owner_approval_reference" label="Referensi persetujuan Owner (wajib, khusus kasus ini)" :value="old('owner_approval_reference')" required maxlength="120" />
                <x-ui.input name="patient_request_reference" label="Referensi permintaan pasien (wajib; nomor formulir, bukan NIK)" :value="old('patient_request_reference')" required maxlength="120" />
                <x-ui.textarea name="reason_note" label="Alasan (10–1000 karakter)" rows="3" required>{{ old('reason_note') }}</x-ui.textarea>

                <x-ui.button type="submit">Ajukan Permintaan Refund</x-ui.button>
            </form>
        </x-ui.card>
    </div>
</x-settings-shell>
