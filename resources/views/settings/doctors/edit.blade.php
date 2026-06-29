<x-settings-shell title="Ubah Dokter">
    <div class="bg-white shadow-sm sm:rounded-lg">
        <form method="POST" action="{{ route('settings.doctors.update', $doctor) }}" class="p-6 space-y-6">
            @csrf @method('PUT')
            @include('settings.doctors._form', ['doctor' => $doctor, 'rmeBranches' => $rmeBranches])
            <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Perbarui Dokter</button>
                <a href="{{ route('settings.doctors.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Batal</a>
            </div>
        </form>
    </div>
</x-settings-shell>
