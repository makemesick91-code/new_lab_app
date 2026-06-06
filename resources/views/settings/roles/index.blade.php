<x-settings-shell title="Manajemen Role">
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Access Control</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">Manajemen Role</h2>
                <p class="mt-1 text-sm text-gray-500">Kelola role sistem dan jumlah permission yang diberikan ke setiap role.</p>
            </div>

            <a href="{{ route('settings.roles.create') }}"
               class="inline-flex items-center rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                + Tambah Role
            </a>
        </div>

        <form method="GET" action="{{ route('settings.roles.index') }}" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-[16rem] flex-1">
                    <label for="role-search" class="text-sm font-medium text-gray-700">Cari role</label>
                    <input id="role-search" type="search" name="search" value="{{ $search }}" placeholder="Nama role"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
                </div>
                <button type="submit" class="inline-flex items-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-700 focus:ring-offset-2">
                    Cari
                </button>
                @if ($search)
                    <a href="{{ route('settings.roles.index') }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                        Atur Ulang
                    </a>
                @endif
            </div>
        </form>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-200 px-4 py-3">
                <h3 class="text-base font-semibold text-gray-900">Daftar Role</h3>
                <p class="text-sm text-gray-500">{{ $roles->total() }} role terdaftar.</p>
            </div>

            <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr class="text-left text-gray-500">
                            <th scope="col" class="px-4 py-3 font-medium">Role</th>
                            <th scope="col" class="px-3 py-3 font-medium">Permission</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($roles as $role)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 font-medium text-gray-900">{{ $role->name }}</td>
                                <td class="px-3 py-3 text-gray-600">
                                    <span class="inline-flex rounded-full bg-teal-50 px-2.5 py-0.5 text-xs font-medium text-teal-700">
                                        {{ $role->permissions_count }} diberikan
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-3">
                                        <a href="{{ route('settings.roles.edit', $role) }}" class="text-sm font-medium text-teal-700 hover:text-teal-600">Ubah</a>
                                        @if ($role->name !== 'Super Admin')
                                            <form method="POST" action="{{ route('settings.roles.destroy', $role) }}"
                                                  onsubmit="return confirm('Hapus role ini?');">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="text-sm font-medium text-red-600 hover:text-red-500">Hapus</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-4 py-8 text-center text-gray-400">Belum ada role.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-gray-100 md:hidden">
                @forelse ($roles as $role)
                    <article class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h4 class="font-semibold text-gray-900">{{ $role->name }}</h4>
                                <p class="mt-1 text-sm text-gray-500">{{ $role->permissions_count }} permission diberikan</p>
                            </div>
                            <div class="flex items-center gap-3">
                                <a href="{{ route('settings.roles.edit', $role) }}" class="text-sm font-medium text-teal-700 hover:text-teal-600">Ubah</a>
                                @if ($role->name !== 'Super Admin')
                                    <form method="POST" action="{{ route('settings.roles.destroy', $role) }}"
                                          onsubmit="return confirm('Hapus role ini?');">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-sm font-medium text-red-600 hover:text-red-500">Hapus</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </article>
                @empty
                    <p class="p-6 text-center text-gray-400">Belum ada role.</p>
                @endforelse
            </div>

            @if ($roles->hasPages())
                <div class="border-t border-gray-200 px-4 py-3">{{ $roles->links() }}</div>
            @endif
        </section>
    </div>
</x-settings-shell>
