<x-settings-shell title="Pasien">
    <div class="bg-white shadow-sm sm:rounded-lg">
        <div class="p-6 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <form method="GET" action="{{ route('settings.patients.index') }}" class="flex flex-wrap items-center gap-2">
                    <input type="text" name="search" value="{{ $search }}" placeholder="Cari nama atau MRN"
                           class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <select name="clinic_id" class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Semua klinik</option>
                        @foreach ($clinics as $clinic)
                            <option value="{{ $clinic->id }}" @selected($clinicId === $clinic->id)>{{ $clinic->name }}</option>
                        @endforeach
                    </select>
                    <select name="doctor_id" class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Semua dokter</option>
                        @foreach ($doctors as $doctor)
                            <option value="{{ $doctor->id }}" @selected($doctorId === $doctor->id)>{{ $doctor->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="inline-flex items-center rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Filter</button>
                    @if ($search || $clinicId || $doctorId)<a href="{{ route('settings.patients.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Reset</a>@endif
                </form>
                <a href="{{ route('settings.patients.create') }}" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">+ Tambah Pasien</a>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="px-3 py-2 font-medium">MRN</th>
                            <th class="px-3 py-2 font-medium">Nama</th>
                            <th class="px-3 py-2 font-medium">Klinik</th>
                            <th class="px-3 py-2 font-medium">Dokter</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 font-medium text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($patients as $patient)
                            <tr>
                                <td class="px-3 py-2 text-gray-600">{{ $patient->medical_record_number ?? '—' }}</td>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $patient->name }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $patient->clinic?->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $patient->doctor?->name ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    @if ($patient->is_active)
                                        <span class="inline-flex items-center rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700">Aktif</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">Nonaktif</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('settings.patients.edit', $patient) }}" class="text-indigo-600 hover:text-indigo-500">Edit</a>
                                        @if ($patient->is_active)
                                            <form method="POST" action="{{ route('settings.patients.deactivate', $patient) }}">@csrf @method('PATCH')<button class="text-amber-600 hover:text-amber-500">Nonaktifkan</button></form>
                                        @else
                                            <form method="POST" action="{{ route('settings.patients.activate', $patient) }}">@csrf @method('PATCH')<button class="text-green-600 hover:text-green-500">Aktifkan</button></form>
                                        @endif
                                        <form method="POST" action="{{ route('settings.patients.destroy', $patient) }}" onsubmit="return confirm('Hapus pasien ini?');">@csrf @method('DELETE')<button class="text-red-600 hover:text-red-500">Hapus</button></form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">Belum ada pasien.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $patients->links() }}</div>
        </div>
    </div>
</x-settings-shell>
