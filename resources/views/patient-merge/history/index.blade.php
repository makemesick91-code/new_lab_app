{{-- FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — Riwayat Merge. --}}
<x-settings-shell title="Duplikasi Pasien">
    <div class="space-y-6">
        <x-ui.page-header title="Riwayat Merge" subtitle="Penggabungan yang selesai, ditolak, dibatalkan, atau dalam review reversal.">
            <x-slot:breadcrumb>Duplikasi Pasien / Riwayat Merge</x-slot:breadcrumb>
        </x-ui.page-header>
        @include('patient-merge.partials.nav')
        @include('patient-merge.partials.case-table', ['action' => route('patient-merge.history.index'), 'emptyTitle' => 'Belum ada riwayat merge', 'showRisk' => false])
    </div>
</x-settings-shell>
