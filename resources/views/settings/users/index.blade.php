<x-settings-shell title="Manajemen Pengguna">
    <x-ui.filter-bar :action="route('settings.users.index')">
        <div class="w-full md:w-72">
            <x-ui.input name="search" :value="$search" placeholder="Cari nama atau email" aria-label="Cari pengguna" />
        </div>
        <x-slot name="actions">
            <x-ui.button type="submit" size="sm">Cari</x-ui.button>
            @if ($search)
                <x-ui.button href="{{ route('settings.users.index') }}" variant="ghost" size="sm">Atur Ulang</x-ui.button>
            @endif
        </x-slot>
    </x-ui.filter-bar>

    <x-ui.card :padding="'p-0'">
        <div class="flex items-center justify-between gap-3 border-b border-hairline px-5 py-4">
            <h3 class="text-base font-semibold text-navy">Daftar Pengguna</h3>
            <x-ui.button href="{{ route('settings.users.create') }}" size="sm">+ Tambah Pengguna</x-ui.button>
        </div>

        @if ($users->isEmpty())
            <div class="p-5">
                <x-ui.empty-state title="Belum ada pengguna" description="Tambahkan akun pengguna dan tetapkan role akses.">
                    <x-slot name="action">
                        <x-ui.button href="{{ route('settings.users.create') }}" size="sm">+ Tambah Pengguna</x-ui.button>
                    </x-slot>
                </x-ui.empty-state>
            </div>
        @else
            <x-ui.table>
                <thead class="bg-navy-50 text-left text-xs uppercase tracking-wide text-ink-soft">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Nama</th>
                        <th class="px-4 py-3 font-semibold">Email</th>
                        <th class="px-4 py-3 font-semibold">Role</th>
                        <th class="px-4 py-3 font-semibold">Status</th>
                        <th class="px-4 py-3 text-right font-semibold">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($users as $user)
                        <tr class="hover:bg-navy-50/60">
                            <td class="px-4 py-3 font-medium text-navy">{{ $user->name }}</td>
                            <td class="px-4 py-3 text-ink-soft">{{ $user->email }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    @forelse ($user->roles as $role)
                                        <x-ui.badge tone="primary">{{ $role->name }}</x-ui.badge>
                                    @empty
                                        <span class="text-ink-muted">—</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <x-ui.badge :tone="$user->is_active ? 'success' : 'neutral'">{{ $user->is_active ? 'Aktif' : 'Nonaktif' }}</x-ui.badge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <x-ui.button href="{{ route('settings.users.edit', $user) }}" variant="secondary" size="sm">Ubah</x-ui.button>

                                    @if ($user->is_active)
                                        <form method="POST" action="{{ route('settings.users.deactivate', $user) }}">
                                            @csrf @method('PATCH')
                                            <x-ui.button type="submit" variant="warning" size="sm">Nonaktifkan</x-ui.button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('settings.users.activate', $user) }}">
                                            @csrf @method('PATCH')
                                            <x-ui.button type="submit" variant="success" size="sm">Aktifkan</x-ui.button>
                                        </form>
                                    @endif

                                    <form method="POST" action="{{ route('settings.users.destroy', $user) }}" onsubmit="return confirm('Hapus pengguna ini?');">
                                        @csrf @method('DELETE')
                                        <x-ui.button type="submit" variant="danger" size="sm">Hapus</x-ui.button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>

    <div>{{ $users->links() }}</div>
</x-settings-shell>
