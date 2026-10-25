@php
    use App\Modules\ClinicOversight\Support\OversightFormat;
@endphp
{{-- FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 (PP-5 / PP-9) — the obligation
     document, read-only. Ids, numbers, amounts and statuses only. --}}
<x-settings-shell title="Kewajiban Kelebihan Bayar">
    <x-ui.page-header :title="'Kewajiban '.$obligation->obligation_number" subtitle="Dokumen kewajiban kelebihan bayar (read-only).">
        <x-slot:breadcrumb>Billing Correction · Kewajiban Kelebihan Bayar</x-slot:breadcrumb>
        <x-slot:actions>
            <x-ui.button variant="secondary" size="sm" :href="route('oversight.billing-corrections.obligations.index')">&larr; Daftar Kewajiban</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('oversight.partials.readonly-notice')

    <x-ui.card>
        <div class="mb-4 inline-block rounded border-2 border-red-700 px-3 py-1 text-sm font-bold tracking-wider text-red-700" data-obligation-stamp>
            KELEBIHAN BAYAR — BELUM DIKEMBALIKAN
        </div>
        <dl class="grid grid-cols-1 gap-3 text-sm md:grid-cols-2">
            <div><dt class="text-ink-soft">No. Kewajiban</dt><dd class="font-mono">{{ $obligation->obligation_number }}</dd></div>
            <div><dt class="text-ink-soft">Status</dt><dd>{{ $obligation->label() }}</dd></div>
            <div><dt class="text-ink-soft">Jumlah</dt><dd class="tabular-nums" data-obligation-amount>{{ OversightFormat::rupiah($obligation->amount) }}</dd></div>
            <div><dt class="text-ink-soft">Cabang</dt><dd>{{ $obligation->branch?->name }}</dd></div>
            <div><dt class="text-ink-soft">Invoice</dt><dd class="font-mono">{{ $obligation->invoice?->invoice_number }} (versi ke-{{ (int) $obligation->invoice_version }})</dd></div>
            <div><dt class="text-ink-soft">Koreksi Asal</dt><dd class="font-mono">
                @if ($obligation->sourceCorrection)
                    <a class="text-brand-700 hover:underline" href="{{ route('oversight.billing-corrections.show', $obligation->source_correction_id) }}">{{ $obligation->sourceCorrection->correction_number }}</a>
                @endif
            </dd></div>
            <div><dt class="text-ink-soft">Dicatat</dt><dd>{{ OversightFormat::clinicalTime($obligation->created_at, 'd/m/Y H:i') }} WITA oleh {{ $obligation->creator?->name ?? '—' }}</dd></div>
        </dl>
        <p class="mt-4 text-xs text-ink-soft">Kewajiban ini dicatat otomatis dalam transaksi yang sama dengan penerapan koreksi. Tidak ada uang yang dikembalikan, dan tidak ada pengembalian (refund), pembalikan pembayaran, atau saldo pasien pada rilis ini.</p>
    </x-ui.card>

    <x-ui.card title="Riwayat">
        <ul class="space-y-2 text-sm">
            @foreach ($obligation->events as $event)
                <li>{{ OversightFormat::clinicalTime($event->occurred_at, 'd/m/Y H:i') }} WITA — {{ $event->from_status ?? '—' }} &rarr; {{ $event->to_status }} ({{ $event->actor?->name ?? '—' }})</li>
            @endforeach
        </ul>
    </x-ui.card>
</x-settings-shell>
