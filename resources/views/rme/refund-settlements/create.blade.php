@php
    use App\Modules\ClinicOversight\Support\OversightFormat;
    use App\Modules\RmeInvoice\Support\RmeRefundSettlementIntent;
    use App\Modules\RmeInvoice\Support\RmeRefundSettlementMethod;
@endphp
{{-- FEATURE-RME-REFUND-SETTLEMENT-1 (rule 216) — claim the payout of an APPROVED
     refund. This step records that payment is UNDER WAY; it does not record money
     leaving the clinic. The amount is the whole approved request, read-only. --}}
<x-settings-shell title="Mulai Pembayaran Refund">
    <div class="space-y-6">
        <x-ui.page-header title="Mulai Pembayaran Refund" subtitle="Langkah 1 dari 2: tandai bahwa Anda sedang membayarkan refund ini. Langkah 2 adalah konfirmasi dengan bukti setelah uang benar-benar keluar.">
            <x-slot:breadcrumb>Rekam Medis Elektronik · Kasir</x-slot:breadcrumb>
        </x-ui.page-header>

        @if ($errors->any())
            <x-ui.alert variant="danger" data-settlement-errors>
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        <x-ui.alert variant="info">
            <strong>DISETUJUI ≠ DIBAYARKAN.</strong> Persetujuan refund bukan bukti pembayaran. Sistem tidak terhubung ke bank dan tidak memindahkan uang.
        </x-ui.alert>

        <x-ui.card>
            <dl class="grid grid-cols-1 gap-3 text-sm md:grid-cols-3">
                <div><dt class="text-ink-soft">Permintaan refund</dt><dd class="font-mono">{{ $refund->request_number }}</dd></div>
                <div><dt class="text-ink-soft">Invoice</dt><dd class="font-mono">{{ $refund->invoice?->invoice_number }}</dd></div>
                <div><dt class="text-ink-soft">Jumlah (seluruh kewajiban)</dt><dd class="font-semibold tabular-nums" data-settlement-amount>{{ OversightFormat::rupiah($refund->amount) }}</dd></div>
                <div><dt class="text-ink-soft">Disetujui oleh</dt><dd>{{ $refund->decider?->name ?? '—' }}</dd></div>
                <div><dt class="text-ink-soft">Cabang tagihan</dt><dd>{{ $refund->branch?->name }}</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.card>
            <form method="POST" action="{{ route('rme.refund-settlements.store', $refund->id) }}" class="space-y-4" data-settlement-start>
                @csrf
                <input type="hidden" name="{{ RmeRefundSettlementIntent::FIELD }}" value="{{ $intent }}">
                <input type="hidden" name="amount" value="{{ $declaredAmount }}">
                <x-ui.select name="method" label="Metode pembayaran" required>
                    <option value="">Pilih metode</option>
                    @foreach (RmeRefundSettlementMethod::ALL as $method)
                        <option value="{{ $method }}" @selected(old('method') === $method)>{{ RmeRefundSettlementMethod::label($method) }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.button type="submit">Mulai Pembayaran</x-ui.button>
            </form>
        </x-ui.card>
    </div>
</x-settings-shell>
