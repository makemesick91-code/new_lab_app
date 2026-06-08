<x-settings-shell title="Ubah Ruangan">
    <div class="bg-white shadow-sm sm:rounded-lg">
        <form method="POST" action="{{ route('settings.clinic-rooms.update', $room) }}" class="p-6 space-y-6">
            @csrf @method('PUT')
            @include('settings.clinic-rooms._form', ['room' => $room, 'types' => $types, 'statuses' => $statuses])
            <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Perbarui Ruangan</button>
                <a href="{{ route('settings.clinic-rooms.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Batal</a>
            </div>
        </form>
    </div>
</x-settings-shell>
