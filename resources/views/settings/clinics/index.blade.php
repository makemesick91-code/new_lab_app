<x-settings-shell title="Klinik">
    <div class="bg-white shadow-sm sm:rounded-lg">
        <div class="p-6 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <form method="GET" action="{{ route('settings.clinics.index') }}" class="flex items-center gap-2">
                    <input type="text" name="search" value="{{ $search }}" placeholder="Cari nama, kode, kota"
                           class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <button type="submit" class="inline-flex items-center rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Cari</button>
                    @if ($search)<a href="{{ route('settings.clinics.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Reset</a>@endif
                </form>
                <a href="{{ route('settings.clinics.create') }}" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">+ Tambah Klinik</a>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="px-3 py-2 font-medium">Kode</th>
                            <th class="px-3 py-2 font-medium">Nama</th>
                            <th class="px-3 py-2 font-medium">Kota</th>
                            <th class="px-3 py-2 font-medium">Telepon</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 font-medium text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($clinics as $clinic)
                            <tr>
                                <td class="px-3 py-2 text-gray-600">{{ $clinic->code }}</td>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $clinic->name }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $clinic->city ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $clinic->phone ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    @if ($clinic->is_active)
                                        <span class="inline-flex items-center rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700">Aktif</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">Nonaktif</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('settings.clinics.edit', $clinic) }}" class="text-indigo-600 hover:text-indigo-500">Edit</a>
                                        @if ($clinic->is_active)
                                            <form method="POST" action="{{ route('settings.clinics.deactivate', $clinic) }}">@csrf @method('PATCH')<button class="text-amber-600 hover:text-amber-500">Nonaktifkan</button></form>
                                        @else
                                            <form method="POST" action="{{ route('settings.clinics.activate', $clinic) }}">@csrf @method('PATCH')<button class="text-green-600 hover:text-green-500">Aktifkan</button></form>
                                        @endif
                                        <form method="POST" action="{{ route('settings.clinics.destroy', $clinic) }}" onsubmit="return confirm('Hapus klinik ini?');">@csrf @method('DELETE')<button class="text-red-600 hover:text-red-500">Hapus</button></form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">Belum ada klinik.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $clinics->links() }}</div>
        </div>
    </div>
</x-settings-shell>
