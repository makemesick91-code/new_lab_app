<x-settings-shell title="Tambah Ruangan">
    <x-ui.card>
        <form method="POST" action="{{ route('settings.clinic-rooms.store') }}" class="space-y-6">
            @csrf
            @include('settings.clinic-rooms._form', ['room' => null, 'types' => $types, 'statuses' => $statuses])
            <div class="flex items-center gap-3 border-t border-hairline pt-5">
                <x-ui.button type="submit">Simpan Ruangan</x-ui.button>
                <x-ui.button href="{{ route('settings.clinic-rooms.index') }}" variant="ghost">Batal</x-ui.button>
            </div>
        </form>
    </x-ui.card>
</x-settings-shell>
