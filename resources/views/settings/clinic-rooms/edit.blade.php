<x-settings-shell title="Ubah Ruangan">
    <x-ui.card>
        <form method="POST" action="{{ route('settings.clinic-rooms.update', $room) }}" class="space-y-6">
            @csrf @method('PUT')
            @include('settings.clinic-rooms._form', ['room' => $room, 'types' => $types, 'statuses' => $statuses])
            <div class="flex items-center gap-3 border-t border-hairline pt-5">
                <x-ui.button type="submit">Perbarui Ruangan</x-ui.button>
                <x-ui.button href="{{ route('settings.clinic-rooms.index') }}" variant="ghost">Batal</x-ui.button>
            </div>
        </form>
    </x-ui.card>
</x-settings-shell>
