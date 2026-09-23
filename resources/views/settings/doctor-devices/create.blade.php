<x-settings-shell title="Daftarkan Perangkat">
    @cannot('manage_doctor_devices')
        <x-ui.alert variant="info" class="mb-5">
            Perangkat yang Anda daftarkan akan berstatus <strong>Menunggu Persetujuan</strong> dan
            <strong>belum dapat dipakai login</strong>. Super Admin menyetujui pendaftaran,
            lalu kredensial didaftarkan di tablet tersebut.
        </x-ui.alert>
    @endcannot

    <x-ui.card>
        <form method="POST" action="{{ route('settings.doctor-devices.store') }}" class="space-y-6">
            @csrf
            @include('settings.doctor-devices._form', ['device' => null, 'branches' => $branches])
            <div class="flex items-center gap-3 border-t border-hairline pt-5">
                <x-ui.button type="submit">Simpan Perangkat</x-ui.button>
                <x-ui.button href="{{ route('settings.doctor-devices.index') }}" variant="ghost">Batal</x-ui.button>
            </div>
        </form>
    </x-ui.card>
</x-settings-shell>
