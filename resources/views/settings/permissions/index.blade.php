<x-settings-shell title="Manajemen Permission">
    <x-ui.alert variant="info">
        Daftar permission bersifat read-only. Pemberian permission ke role diatur dari layar <span class="font-semibold">Ubah Role</span>.
    </x-ui.alert>

    <x-ui.filter-bar :action="route('settings.permissions.index')">
        <div class="w-full md:w-72">
            <x-ui.input name="search" :value="$search" placeholder="Cari permission" aria-label="Cari permission" />
        </div>
        <x-slot name="actions">
            <x-ui.button type="submit" size="sm">Cari</x-ui.button>
            @if ($search)
                <x-ui.button href="{{ route('settings.permissions.index') }}" variant="ghost" size="sm">Atur Ulang</x-ui.button>
            @endif
        </x-slot>
    </x-ui.filter-bar>

    <x-ui.card :padding="'p-0'">
        <div class="border-b border-hairline px-5 py-4">
            <h3 class="text-base font-semibold text-navy">Daftar Permission</h3>
        </div>

        @if ($permissions->isEmpty())
            <div class="p-5">
                <x-ui.empty-state title="Belum ada permission" description="Permission dikelola oleh seeder sistem." />
            </div>
        @else
            <x-ui.table>
                <thead class="bg-navy-50 text-left text-xs uppercase tracking-wide text-ink-soft">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Permission</th>
                        <th class="px-4 py-3 font-semibold">Dipakai oleh role</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($permissions as $permission)
                        <tr class="hover:bg-navy-50/60">
                            <td class="px-4 py-3 font-medium text-navy">{{ $permission->name }}</td>
                            <td class="px-4 py-3">
                                <x-ui.badge :tone="$permission->roles_count > 0 ? 'primary' : 'neutral'">{{ $permission->roles_count }}</x-ui.badge>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>

    <div>{{ $permissions->links() }}</div>
</x-settings-shell>
