{{--
    FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — correction panel on the
    cashier invoice page. Renders NOTHING while the `rme.invoice_correction` flag is
    off and the invoice was never revised (RmeInvoiceCorrectionService::panelFor()
    runs no query in that case), so the page is unchanged before activation.
    Buttons are a courtesy; the policy and the service refuse independently.

    ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-2 (rule 195): with the flag ON the
    panel appears only for an invoice whose branch is on the pilot allowlist
    (`pilot_admitted`); outside the pilot the page is unchanged unless the invoice
    already has correction history.
--}}
@php
    use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
@endphp
@if ($panel['pilot_admitted'] || $panel['history']->isNotEmpty())
    <x-ui.card title="Koreksi Tagihan" data-invoice-correction-panel>
        @if ($panel['active'])
            <x-ui.alert variant="warning">
                Ada pengajuan koreksi aktif:
                <a class="font-semibold underline" href="{{ route('rme.invoice-corrections.show', $panel['active']->id) }}">{{ $panel['active']->correction_number }}</a>
                — {{ RmeInvoiceCorrectionStatus::label($panel['active']->status) }}. Tagihan belum berubah dan pembayaran ditahan sampai pengajuan diputuskan; persetujuan langsung menerapkan koreksi.
            </x-ui.alert>
        @endif

        @if ($panel['can_request'] && $panel['eligible'])
            <div class="mt-3">
                <x-ui.button variant="secondary" :href="route('rme.invoice-corrections.create', [$visit, $invoice])">Ajukan Koreksi Tagihan</x-ui.button>
            </div>
        @elseif ($panel['pilot_admitted'] && $panel['ineligible_reason'] && ! $panel['active'])
            <p class="text-sm text-ink-soft">{{ $panel['ineligible_reason'] }}</p>
        @endif

        @if ($panel['history']->isNotEmpty())
            <ul class="mt-3 space-y-1 text-sm">
                @foreach ($panel['history'] as $correction)
                    <li>
                        <a class="text-brand-700 hover:underline" href="{{ route('rme.invoice-corrections.show', $correction->id) }}">{{ $correction->correction_number }}</a>
                        <x-ui.badge :tone="RmeInvoiceCorrectionStatus::tone($correction->status)">{{ RmeInvoiceCorrectionStatus::label($correction->status) }}</x-ui.badge>
                    </li>
                @endforeach
            </ul>
        @endif

        {{-- FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 (PP-9) — the cashier of the
             invoice's branch sees the obligation status; nobody can act on it here. --}}
        @foreach ($panel['obligations'] as $obligation)
            <x-ui.alert variant="warning" class="mt-3" data-invoice-obligation>
                Kewajiban kelebihan bayar <span class="font-mono">{{ $obligation->obligation_number }}</span>:
                {{ \App\Modules\RmeInvoice\Support\RmeInvoiceDocument::rupiahDecimal($obligation->amount) }} — {{ $obligation->label() }}.
                Jangan mengembalikan uang di luar sistem; pengembalian (refund) belum tersedia.
            </x-ui.alert>
        @endforeach

        {{-- PP-5 — receipts issued before the latest version are reprinted from their snapshots. --}}
        @if (($panel['receipts'] ?? collect())->isNotEmpty())
            <p class="mt-3 text-xs text-ink-soft" data-invoice-receipt-snapshots>Kwitansi terbit:
                @foreach ($panel['receipts'] as $receipt)
                    <a class="text-brand-700 hover:underline" href="{{ route('rme.cashier.receipt-snapshots.show', [$visit, $invoice, $receipt->id]) }}">{{ $receipt->receipt_number }} (v{{ (int) $receipt->invoice_version }}{{ $receipt->isReconstructed() ? ', rekonstruksi' : '' }})</a>@if (! $loop->last), @endif
                @endforeach
            </p>
        @endif

        @if ($panel['versions']->isNotEmpty())
            <p class="mt-3 text-xs text-ink-soft">Versi dokumen:
                @foreach ($panel['versions'] as $version)
                    <a class="text-brand-700 hover:underline" href="{{ route('rme.invoice-corrections.document-versions.show', $version->id) }}">{{ $version->label() }}</a>@if (! $loop->last), @endif
                @endforeach
            </p>
        @endif
    </x-ui.card>
@endif
