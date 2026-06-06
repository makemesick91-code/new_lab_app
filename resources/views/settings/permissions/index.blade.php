<x-settings-shell title="Manajemen Permission">
    <div class="bg-white shadow-sm sm:rounded-lg">
        <div class="p-6 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <form method="GET" action="{{ route('settings.permissions.index') }}" class="flex items-center gap-2">
                    <input type="text" name="search" value="{{ $search }}" placeholder="Cari permission"
                           class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <button type="submit" class="inline-flex items-center rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Cari</button>
                    @if ($search)
                        <a href="{{ route('settings.permissions.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Atur Ulang</a>
                    @endif
                </form>
                <p class="text-xs text-gray-400">Atur permission role dari layar ubah role.</p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="px-3 py-2 font-medium">Permission</th>
                            <th class="px-3 py-2 font-medium">Dipakai oleh role</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($permissions as $permission)
                            <tr>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $permission->name }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $permission->roles_count }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="2" class="px-3 py-6 text-center text-gray-400">Belum ada permission.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div>{{ $permissions->links() }}</div>
        </div>
    </div>
</x-settings-shell>
