<x-settings-shell title="Daftar Kunjungan Baru">
    <div class="space-y-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Rekam Medis Elektronik</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">Daftar Kunjungan Baru</h2>
            <p class="mt-1 text-sm text-gray-500">Registrasi pasien ke antrian klinik cabang aktif.</p>
        </div>

        <x-ui.card>
            <form method="POST" action="{{ route('rme.visits.store') }}" class="space-y-6">
                @csrf
                @include('rme.visits._form', [
                    'visit'       => null,
                    'clinics'     => $clinics,
                    'patients'    => $patients,
                    'doctors'     => $doctors,
                    'clinicRooms' => $clinicRooms,
                ])
                <div class="flex items-center justify-end gap-2 border-t border-gray-100 pt-5">
                    <x-ui.button variant="secondary" :href="route('rme.visits.index')">Batal</x-ui.button>
                    <x-ui.button type="submit" variant="primary">Daftarkan</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-settings-shell>
