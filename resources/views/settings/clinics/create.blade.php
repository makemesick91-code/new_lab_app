<x-settings-shell title="Tambah Klinik">
    <div class="bg-white shadow-sm sm:rounded-lg">
        <form method="POST" action="{{ route('settings.clinics.store') }}" class="p-6 space-y-6">
            @csrf
            @include('settings.clinics._form', ['clinic' => null])
            <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Simpan Klinik</button>
                <a href="{{ route('settings.clinics.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Batal</a>
            </div>
        </form>
    </div>
</x-settings-shell>
