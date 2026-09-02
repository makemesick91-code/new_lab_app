<x-settings-shell title="Ubah Perangkat">
    <x-ui.card>
        <form method="POST" action="{{ route('settings.doctor-devices.update', $device) }}" class="space-y-6">
            @csrf
            @method('PUT')
            @include('settings.doctor-devices._form', ['device' => $device, 'branches' => $branches])
            <div class="flex items-center gap-3 border-t border-hairline pt-5">
                <x-ui.button type="submit">Simpan Perubahan</x-ui.button>
                <x-ui.button href="{{ route('settings.doctor-devices.show', $device) }}" variant="ghost">Batal</x-ui.button>
            </div>
        </form>
    </x-ui.card>
</x-settings-shell>
