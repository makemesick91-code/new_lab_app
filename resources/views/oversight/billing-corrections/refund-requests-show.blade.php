{{-- FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (RF-8/RF-8a) — one refund request,
     read-only. No form on this page posts anywhere. --}}
<x-settings-shell title="Permintaan Refund">
    <x-ui.page-header :title="'Permintaan Refund '.$refund->request_number" subtitle="Tampilan baca-saja.">
        <x-slot:breadcrumb>Billing Correction · Permintaan Refund</x-slot:breadcrumb>
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('oversight.billing-corrections.refund-requests.index')">&larr; Daftar</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @include('oversight.partials.readonly-notice')

    <div class="space-y-4">
        @include('rme.refund-requests.partials.detail')
    </div>
</x-settings-shell>
