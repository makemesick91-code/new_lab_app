<x-settings-shell title="Master Perawatan">
    <div class="bg-white shadow-sm sm:rounded-lg">
        <div class="p-6 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <form method="GET" action="{{ route('settings.treatments.index') }}" class="flex flex-wrap items-center gap-2">
                    <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Cari nama atau kode"
                           class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <select name="treatment_category_id" class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Semua kategori</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected($filters['treatment_category_id'] === $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                    <select name="is_active" class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Semua status</option>
                        <option value="1" @selected($filters['is_active'] === true)>Aktif</option>
                        <option value="0" @selected($filters['is_active'] === false)>Nonaktif</option>
                    </select>
                    <select name="requires_lab" class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Semua lab</option>
                        <option value="1" @selected($filters['requires_lab'] === true)>Perlu Lab</option>
                        <option value="0" @selected($filters['requires_lab'] === false)>Tanpa Lab</option>
                    </select>
                    <button type="submit" class="inline-flex items-center rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Terapkan</button>
                    @if ($filters['search'] || $filters['treatment_category_id'] || $filters['is_active'] !== null || $filters['requires_lab'] !== null)
                        <a href="{{ route('settings.treatments.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Atur Ulang</a>
                    @endif
                </form>
                @can('create', \App\Modules\Treatment\Models\Treatment::class)
                    <a href="{{ route('settings.treatments.create') }}" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">+ Tambah Perawatan</a>
                @endcan
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="px-3 py-2 font-medium">Kode</th>
                            <th class="px-3 py-2 font-medium">Nama</th>
                            <th class="px-3 py-2 font-medium">Kategori</th>
                            <th class="px-3 py-2 font-medium">Durasi</th>
                            <th class="px-3 py-2 font-medium">Lab</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 font-medium text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($treatments as $treatment)
                            <tr>
                                <td class="px-3 py-2 text-gray-600">{{ $treatment->code }}</td>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $treatment->name }}</td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">{{ $treatment->category?->name ?? '—' }}</span>
                                </td>
                                <td class="px-3 py-2 text-gray-600">{{ $treatment->default_duration_minutes ? $treatment->default_duration_minutes.' mnt' : '—' }}</td>
                                <td class="px-3 py-2">
                                    @if ($treatment->requires_lab)
                                        <span class="inline-flex items-center rounded-full bg-purple-50 px-2 py-0.5 text-xs font-medium text-purple-700">Perlu Lab</span>
                                    @else
                                        <span class="text-xs text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    @if ($treatment->is_active)
                                        <span class="inline-flex items-center rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700">Aktif</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">Nonaktif</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center justify-end gap-2">
                                        @can('update', $treatment)
                                            <a href="{{ route('settings.treatments.edit', $treatment) }}" class="text-indigo-600 hover:text-indigo-500">Ubah</a>
                                        @endcan
                                        @can('delete', $treatment)
                                            <form method="POST" action="{{ route('settings.treatments.destroy', $treatment) }}" onsubmit="return confirm('Hapus perawatan ini?');">@csrf @method('DELETE')<button class="text-red-600 hover:text-red-500">Hapus</button></form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">Belum ada perawatan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $treatments->links() }}</div>
        </div>
    </div>
</x-settings-shell>
