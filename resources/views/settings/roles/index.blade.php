<x-settings-shell title="Manajemen Role">
    <div class="bg-white shadow-sm sm:rounded-lg">
        <div class="p-6 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <form method="GET" action="{{ route('settings.roles.index') }}" class="flex items-center gap-2">
                    <input type="text" name="search" value="{{ $search }}" placeholder="Cari role"
                           class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <button type="submit" class="inline-flex items-center rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Cari</button>
                    @if ($search)
                        <a href="{{ route('settings.roles.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Reset</a>
                    @endif
                </form>

                <a href="{{ route('settings.roles.create') }}"
                   class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                    + Tambah Role
                </a>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="px-3 py-2 font-medium">Role</th>
                            <th class="px-3 py-2 font-medium">Permission</th>
                            <th class="px-3 py-2 font-medium text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($roles as $role)
                            <tr>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $role->name }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $role->permissions_count }} diberikan</td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('settings.roles.edit', $role) }}" class="text-indigo-600 hover:text-indigo-500">Edit</a>
                                        @if ($role->name !== 'Super Admin')
                                            <form method="POST" action="{{ route('settings.roles.destroy', $role) }}"
                                                  onsubmit="return confirm('Hapus role ini?');">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="text-red-600 hover:text-red-500">Hapus</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-3 py-6 text-center text-gray-400">Belum ada role.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div>{{ $roles->links() }}</div>
        </div>
    </div>
</x-settings-shell>
