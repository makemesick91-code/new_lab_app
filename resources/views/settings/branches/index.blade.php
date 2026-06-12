<x-settings-shell title="Master Data Cabang">
    <div class="bg-white shadow-sm sm:rounded-lg">
        <div class="p-6 space-y-4">
            @if (session('status'))
                <div class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-700">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="rounded-md bg-red-50 px-4 py-2 text-sm text-red-700">{{ session('error') }}</div>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-3">
                <form method="GET" action="{{ route('settings.branches.index') }}" class="flex flex-wrap items-center gap-2">
                    <input type="text" name="search" value="{{ $search }}" placeholder="Cari nama atau kode"
                           class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <button type="submit" class="inline-flex items-center rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Terapkan</button>
                    @if ($search)
                        <a href="{{ route('settings.branches.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Atur Ulang</a>
                    @endif
                </form>
                @can('create', \App\Modules\Branch\Models\Branch::class)
                    <a href="{{ route('settings.branches.create') }}" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">+ Tambah Cabang</a>
                @endcan
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="px-3 py-2 font-medium">Kode Cabang</th>
                            <th class="px-3 py-2 font-medium">Nama Cabang</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 font-medium">RME</th>
                            <th class="px-3 py-2 font-medium">Inventory</th>
                            <th class="px-3 py-2 font-medium text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($branches as $branch)
                            <tr>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $branch->code }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $branch->name }}</td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $branch->is_active ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-600' }}">
                                        {{ $branch->is_active ? 'Aktif' : 'Nonaktif' }}
                                    </span>
                                </td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $branch->is_rme_enabled ? 'bg-indigo-50 text-indigo-700' : 'bg-gray-100 text-gray-500' }}">
                                        {{ $branch->is_rme_enabled ? 'Ya' : 'Tidak' }}
                                    </span>
                                </td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $branch->is_inventory_enabled ? 'bg-amber-50 text-amber-700' : 'bg-gray-100 text-gray-500' }}">
                                        {{ $branch->is_inventory_enabled ? 'Ya' : 'Tidak' }}
                                    </span>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center justify-end gap-2">
                                        @can('update', $branch)
                                            <a href="{{ route('settings.branches.edit', $branch) }}" class="text-indigo-600 hover:text-indigo-500">Ubah</a>
                                        @endcan
                                        @can('delete', $branch)
                                            @if ($branch->code !== \App\Modules\Branch\Models\Branch::MAIN_CODE)
                                                <form method="POST" action="{{ route('settings.branches.destroy', $branch) }}" onsubmit="return confirm('Hapus cabang ini?');">@csrf @method('DELETE')<button class="text-red-600 hover:text-red-500">Hapus</button></form>
                                            @endif
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">Belum ada cabang.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $branches->links() }}</div>
        </div>
    </div>
</x-settings-shell>
