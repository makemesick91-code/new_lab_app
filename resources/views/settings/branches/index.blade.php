<x-settings-shell title="Master Cabang RME">
    <x-ui.filter-bar :action="route('settings.branches.index')">
        <div class="w-full md:w-72">
            <x-ui.input name="search" :value="$search" placeholder="Cari nama atau kode" aria-label="Cari cabang" />
        </div>
        <x-slot name="actions">
            <x-ui.button type="submit" size="sm">Terapkan</x-ui.button>
            @if ($search)
                <x-ui.button href="{{ route('settings.branches.index') }}" variant="ghost" size="sm">Atur Ulang</x-ui.button>
            @endif
        </x-slot>
    </x-ui.filter-bar>

    <x-ui.card :padding="'p-0'">
        <div class="flex items-center justify-between gap-3 border-b border-hairline px-5 py-4">
            <h3 class="text-base font-semibold text-navy">Daftar Cabang</h3>
            @can('create', \App\Modules\Branch\Models\Branch::class)
                <x-ui.button href="{{ route('settings.branches.create') }}" size="sm">+ Tambah Cabang</x-ui.button>
            @endcan
        </div>

        @if ($branches->isEmpty())
            <div class="p-5">
                <x-ui.empty-state title="Belum ada cabang" description="Tambahkan cabang dan atur modul RME / Inventory yang aktif." />
            </div>
        @else
            <x-ui.table>
                <thead class="bg-navy-50 text-left text-xs uppercase tracking-wide text-ink-soft">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Kode Cabang</th>
                        <th class="px-4 py-3 font-semibold">Nama Cabang</th>
                        <th class="px-4 py-3 font-semibold">Status</th>
                        <th class="px-4 py-3 font-semibold">RME</th>
                        <th class="px-4 py-3 font-semibold">Inventory</th>
                        <th class="px-4 py-3 text-right font-semibold">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($branches as $branch)
                        <tr class="hover:bg-navy-50/60">
                            <td class="px-4 py-3 font-medium text-navy">{{ $branch->code }}</td>
                            <td class="px-4 py-3 text-ink-soft">{{ $branch->name }}</td>
                            <td class="px-4 py-3">
                                <x-ui.badge :tone="$branch->is_active ? 'success' : 'neutral'">{{ $branch->is_active ? 'Aktif' : 'Nonaktif' }}</x-ui.badge>
                            </td>
                            <td class="px-4 py-3">
                                <x-ui.badge :tone="$branch->is_rme_enabled ? 'primary' : 'neutral'">{{ $branch->is_rme_enabled ? 'Ya' : 'Tidak' }}</x-ui.badge>
                            </td>
                            <td class="px-4 py-3">
                                <x-ui.badge :tone="$branch->is_inventory_enabled ? 'warning' : 'neutral'">{{ $branch->is_inventory_enabled ? 'Ya' : 'Tidak' }}</x-ui.badge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    @can('update', $branch)
                                        <x-ui.button href="{{ route('settings.branches.edit', $branch) }}" variant="secondary" size="sm">Ubah</x-ui.button>
                                    @endcan
                                    @can('delete', $branch)
                                        @if ($branch->code !== \App\Modules\Branch\Models\Branch::MAIN_CODE)
                                            <form method="POST" action="{{ route('settings.branches.destroy', $branch) }}" onsubmit="return confirm('Hapus cabang ini?');">
                                                @csrf @method('DELETE')
                                                <x-ui.button type="submit" variant="danger" size="sm">Hapus</x-ui.button>
                                            </form>
                                        @endif
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>

    <div>{{ $branches->links() }}</div>
</x-settings-shell>
