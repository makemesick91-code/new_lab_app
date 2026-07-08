<x-settings-shell title="Daftar Kunjungan Baru">
    <div class="space-y-6">
        <x-ui.page-header
            title="Daftar Kunjungan Baru"
            subtitle="Registrasi pasien ke antrian cabang RME aktif.">
            <x-slot:breadcrumb>Rekam Medis Elektronik</x-slot:breadcrumb>
            <x-slot:actions>
                <x-ui.button variant="secondary" :href="route('rme.visits.index')">Kembali</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.card>
            <form method="POST" action="{{ route('rme.visits.store') }}" class="space-y-6">
                @csrf
                @include('rme.visits._form', [
                    'visit' => null,
                    'patients' => $patients,
                    'doctors' => $doctors,
                    'rmeBranches' => $rmeBranches,
                    'prefill' => $prefill ?? [],
                    'lockedBranchId' => $lockedBranchId ?? null,
                    'noOnlineDoctors' => $noOnlineDoctors ?? false,
                    'hideDoctorSelection' => $hideDoctorSelection ?? false,
                ])
                <div class="flex items-center justify-end gap-2 border-t border-hairline pt-5">
                    <x-ui.button variant="secondary" :href="route('rme.visits.index')">Batal</x-ui.button>
                    <x-ui.button type="submit" variant="primary">Daftarkan</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-settings-shell>
