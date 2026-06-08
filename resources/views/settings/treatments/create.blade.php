<x-settings-shell title="Tambah Perawatan">
    <div class="bg-white shadow-sm sm:rounded-lg">
        <form method="POST" action="{{ route('settings.treatments.store') }}" class="p-6 space-y-6">
            @csrf
            @include('settings.treatments._form', ['treatment' => null, 'categories' => $categories])
            <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Simpan Perawatan</button>
                <a href="{{ route('settings.treatments.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Batal</a>
            </div>
        </form>
    </div>
</x-settings-shell>
