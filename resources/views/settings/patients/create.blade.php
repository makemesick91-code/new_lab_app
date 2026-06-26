<x-settings-shell title="Tambah Pasien">
    <div class="bg-white shadow-sm sm:rounded-lg">
        <form method="POST" action="{{ route('settings.patients.store') }}" class="p-6 space-y-6">
            @csrf
            @include('settings.patients._form', ['patient' => null, 'clinics' => $clinics, 'doctors' => $doctors, 'rmeBranches' => $rmeBranches])
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @include('settings.patients._ktp-scan')
            </div>
            <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Simpan Pasien</button>
                <a href="{{ route('settings.patients.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Batal</a>
            </div>
        </form>
    </div>
</x-settings-shell>
