{{-- FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — in-module navigation.
     Each link is shown only to a user whose permission its route requires;
     the routes enforce the same rule server-side. --}}
@php
    $tabs = [
        ['route' => 'patient-merge.dashboard', 'match' => 'patient-merge.dashboard', 'label' => 'Dashboard', 'can' => 'view_patient_duplicate_resolution'],
        ['route' => 'patient-merge.candidates.index', 'match' => 'patient-merge.candidates.*', 'label' => 'Deteksi Duplikat', 'can' => 'view_patient_duplicate_resolution'],
        ['route' => 'patient-merge.manual.create', 'match' => 'patient-merge.manual.*', 'label' => 'Pilih Pasien Manual', 'can' => 'request_patient_merge'],
        ['route' => 'patient-merge.cases.index', 'match' => 'patient-merge.cases.*', 'label' => 'Pengajuan Merge', 'can' => 'view_patient_duplicate_resolution'],
        ['route' => 'patient-merge.review.index', 'match' => 'patient-merge.review.*', 'label' => 'Review & Approval', 'can' => 'approve_patient_merge'],
        ['route' => 'patient-merge.history.index', 'match' => 'patient-merge.history.*', 'label' => 'Riwayat Merge', 'can' => 'view_patient_duplicate_resolution'],
        ['route' => 'patient-merge.aliases.index', 'match' => 'patient-merge.aliases.*', 'label' => 'RM Alias', 'can' => 'view_patient_duplicate_resolution'],
    ];
@endphp
<nav class="flex flex-wrap gap-2" aria-label="Navigasi Duplikasi Pasien">
    @foreach ($tabs as $tab)
        @can($tab['can'])
            <a href="{{ route($tab['route']) }}"
               @if (request()->routeIs($tab['match'])) aria-current="page" @endif
               class="rounded-lg px-3 py-1.5 text-sm font-medium {{ request()->routeIs($tab['match']) ? 'bg-brand-50 text-brand-700 ring-1 ring-brand-100' : 'text-ink-soft hover:bg-navy-50 hover:text-navy' }}">{{ $tab['label'] }}</a>
        @endcan
    @endforeach
</nav>
