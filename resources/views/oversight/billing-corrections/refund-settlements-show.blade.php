{{-- FEATURE-RME-REFUND-SETTLEMENT-1 (RFS-6) — one refund payout, read-only. No form
     on this page posts anywhere, and the proof file is not downloadable here. --}}
<x-settings-shell title="Pembayaran Refund">
    <x-ui.page-header :title="'Pembayaran Refund '.$settlement->settlement_number" subtitle="Tampilan baca-saja.">
        <x-slot:breadcrumb>Billing Correction · Pembayaran Refund</x-slot:breadcrumb>
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('oversight.billing-corrections.refund-settlements.index')">&larr; Daftar</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('oversight.partials.readonly-notice')

    <div class="space-y-4">
        @include('rme.refund-settlements.partials.detail')
    </div>
</x-settings-shell>
