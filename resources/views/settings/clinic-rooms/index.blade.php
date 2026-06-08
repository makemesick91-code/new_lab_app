<x-settings-shell title="Master Ruangan">
    @php
        $typeLabels = [
            'treatment_room' => 'Ruang Perawatan',
            'consultation_room' => 'Ruang Konsultasi',
            'xray_room' => 'Ruang Rontgen',
            'sterilization_room' => 'Ruang Sterilisasi',
            'lab_room' => 'Ruang Lab',
            'other' => 'Lainnya',
        ];
        $statusLabels = [
            'active' => 'Aktif',
            'inactive' => 'Nonaktif',
            'maintenance' => 'Pemeliharaan',
        ];
        $statusBadge = [
            'active' => 'bg-green-50 text-green-700',
            'inactive' => 'bg-gray-100 text-gray-600',
            'maintenance' => 'bg-amber-50 text-amber-700',
        ];
    @endphp

    <div class="bg-white shadow-sm sm:rounded-lg">
        <div class="p-6 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <form method="GET" action="{{ route('settings.clinic-rooms.index') }}" class="flex flex-wrap items-center gap-2">
                    <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Cari nama atau kode"
                           class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <select name="type" class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Semua tipe</option>
                        @foreach ($types as $type)
                            <option value="{{ $type }}" @selected($filters['type'] === $type)>{{ $typeLabels[$type] ?? $type }}</option>
                        @endforeach
                    </select>
                    <select name="status" class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Semua status</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $statusLabels[$status] ?? $status }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="inline-flex items-center rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Terapkan</button>
                    @if ($filters['search'] || $filters['type'] || $filters['status'])
                        <a href="{{ route('settings.clinic-rooms.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Atur Ulang</a>
                    @endif
                </form>
                @can('create', \App\Modules\ClinicRoom\Models\ClinicRoom::class)
                    <a href="{{ route('settings.clinic-rooms.create') }}" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">+ Tambah Ruangan</a>
                @endcan
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="px-3 py-2 font-medium">Kode</th>
                            <th class="px-3 py-2 font-medium">Nama</th>
                            <th class="px-3 py-2 font-medium">Tipe</th>
                            <th class="px-3 py-2 font-medium">Cabang</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 font-medium text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rooms as $room)
                            <tr>
                                <td class="px-3 py-2 text-gray-600">{{ $room->code }}</td>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $room->name }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $typeLabels[$room->type] ?? $room->type }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $room->branch?->name ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $statusBadge[$room->status] ?? 'bg-gray-100 text-gray-600' }}">
                                        {{ $statusLabels[$room->status] ?? $room->status }}
                                    </span>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center justify-end gap-2">
                                        @can('update', $room)
                                            <a href="{{ route('settings.clinic-rooms.edit', $room) }}" class="text-indigo-600 hover:text-indigo-500">Ubah</a>
                                        @endcan
                                        @can('delete', $room)
                                            <form method="POST" action="{{ route('settings.clinic-rooms.destroy', $room) }}" onsubmit="return confirm('Hapus ruangan ini?');">@csrf @method('DELETE')<button class="text-red-600 hover:text-red-500">Hapus</button></form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">Belum ada ruangan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $rooms->links() }}</div>
        </div>
    </div>
</x-settings-shell>
