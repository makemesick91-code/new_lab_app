<x-settings-shell title="Manajemen Role">
    <x-ui.filter-bar :action="route('settings.roles.index')">
        <div class="w-full md:w-72">
            <x-ui.input name="search" type="search" :value="$search" placeholder="Nama role" aria-label="Cari role" />
        </div>
        <x-slot name="actions">
            <x-ui.button type="submit" size="sm">Cari</x-ui.button>
            @if ($search)
                <x-ui.button href="{{ route('settings.roles.index') }}" variant="ghost" size="sm">Atur Ulang</x-ui.button>
            @endif
        </x-slot>
    </x-ui.filter-bar>

    <x-ui.card :padding="'p-0'">
        <div class="flex items-center justify-between gap-3 border-b border-hairline px-5 py-4">
            <div>
                <h3 class="text-base font-semibold text-navy">Daftar Role</h3>
                <p class="text-sm text-ink-soft">{{ $roles->total() }} role terdaftar.</p>
            </div>
            <x-ui.button href="{{ route('settings.roles.create') }}" size="sm">+ Tambah Role</x-ui.button>
        </div>

        @if ($roles->isEmpty())
            <div class="p-5">
                <x-ui.empty-state title="Belum ada role" description="Tambahkan role dan tetapkan permission-nya.">
                    <x-slot name="action">
                        <x-ui.button href="{{ route('settings.roles.create') }}" size="sm">+ Tambah Role</x-ui.button>
                    </x-slot>
                </x-ui.empty-state>
            </div>
        @else
            <x-ui.table>
                <thead class="bg-navy-50 text-left text-xs uppercase tracking-wide text-ink-soft">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">Role</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Permission</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($roles as $role)
                        <tr class="hover:bg-navy-50/60">
                            <td class="px-4 py-3 font-medium text-navy">{{ $role->name }}</td>
                            <td class="px-4 py-3">
                                <x-ui.badge tone="primary">{{ $role->permissions_count }} diberikan</x-ui.badge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <x-ui.button href="{{ route('settings.roles.edit', $role) }}" variant="secondary" size="sm">Ubah</x-ui.button>
                                    @if ($role->name !== 'Super Admin')
                                        <form method="POST" action="{{ route('settings.roles.destroy', $role) }}" onsubmit="return confirm('Hapus role ini?');">
                                            @csrf @method('DELETE')
                                            <x-ui.button type="submit" variant="danger" size="sm">Hapus</x-ui.button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>

    <div>{{ $roles->links() }}</div>
</x-settings-shell>
