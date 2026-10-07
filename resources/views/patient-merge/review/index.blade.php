{{-- FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — Review & Approval.
     The reviewer's queue: pending merges and open reversal reviews. --}}
<x-settings-shell title="Duplikasi Pasien">
    <div class="space-y-6">
        <x-ui.page-header title="Review & Approval" subtitle="Tinjau preview, setujui untuk menjalankan penggabungan, atau tolak. Anda tidak dapat menyetujui pengajuan Anda sendiri.">
            <x-slot:breadcrumb>Duplikasi Pasien / Review &amp; Approval</x-slot:breadcrumb>
        </x-ui.page-header>
        @include('patient-merge.partials.nav')
        @include('patient-merge.partials.case-table', ['action' => route('patient-merge.review.index'), 'emptyTitle' => 'Tidak ada pengajuan yang menunggu review'])
    </div>
</x-settings-shell>
