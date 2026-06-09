<x-settings-shell title="Daftar Kunjungan Baru">
    <div class="bg-white shadow-sm sm:rounded-lg">
        <form method="POST" action="{{ route('rme.visits.store') }}" class="p-6 space-y-6">
            @csrf
            @include('rme.visits._form', [
                'visit'       => null,
                'clinics'     => $clinics,
                'patients'    => $patients,
                'doctors'     => $doctors,
                'clinicRooms' => $clinicRooms,
            ])
            <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Daftarkan</button>
                <a href="{{ route('rme.visits.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Batal</a>
            </div>
        </form>
    </div>
</x-settings-shell>
