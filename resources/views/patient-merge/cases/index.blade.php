{{-- FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — Pengajuan Merge (draft + menunggu review). --}}
<x-settings-shell title="Duplikasi Pasien">
    <div class="space-y-6">
        <x-ui.page-header title="Pengajuan Merge" subtitle="Pengajuan yang masih terbuka: draf rekonsiliasi dan yang menunggu review.">
            <x-slot:breadcrumb>Duplikasi Pasien / Pengajuan Merge</x-slot:breadcrumb>
            @can('request_patient_merge')
                <x-slot:actions><x-ui.button :href="route('patient-merge.manual.create')">Pengajuan Baru</x-ui.button></x-slot:actions>
            @endcan
        </x-ui.page-header>
        @include('patient-merge.partials.nav')
        @include('patient-merge.partials.case-table', ['action' => route('patient-merge.cases.index'), 'emptyTitle' => 'Tidak ada pengajuan terbuka'])
    </div>
</x-settings-shell>
