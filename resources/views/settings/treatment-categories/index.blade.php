<x-settings-shell title="Master Kategori Perawatan">
    @php($hasFilters = $filters['search'] || $filters['is_active'] !== null)

    <x-ui.filter-bar :action="route('settings.treatment-categories.index')">
        <div class="w-full md:w-64">
            <x-ui.input name="search" :value="$filters['search']" placeholder="Cari nama atau kode" aria-label="Cari kategori" />
        </div>
        <div class="w-full md:w-48">
            <x-ui.select name="is_active" aria-label="Filter status">
                <option value="">Semua status</option>
                <option value="1" @selected($filters['is_active'] === true)>Aktif</option>
                <option value="0" @selected($filters['is_active'] === false)>Nonaktif</option>
            </x-ui.select>
        </div>
        <x-slot name="actions">
            <x-ui.button type="submit" size="sm">Terapkan</x-ui.button>
            @if ($hasFilters)
                <x-ui.button href="{{ route('settings.treatment-categories.index') }}" variant="ghost" size="sm">Atur Ulang</x-ui.button>
            @endif
        </x-slot>
    </x-ui.filter-bar>

    <x-ui.card :padding="'p-0'">
        <div class="flex items-center justify-between gap-3 border-b border-hairline px-5 py-4">
            <h3 class="text-base font-semibold text-navy">Daftar Kategori</h3>
            @can('create', \App\Modules\TreatmentCategory\Models\TreatmentCategory::class)
                <x-ui.button href="{{ route('settings.treatment-categories.create') }}" size="sm">+ Tambah Kategori</x-ui.button>
            @endcan
        </div>

        @if ($categories->isEmpty())
            <div class="p-5">
                <x-ui.empty-state title="Belum ada kategori perawatan" description="Tambahkan kategori untuk mengelompokkan perawatan.">
                    @can('create', \App\Modules\TreatmentCategory\Models\TreatmentCategory::class)
                        <x-slot name="action">
                            <x-ui.button href="{{ route('settings.treatment-categories.create') }}" size="sm">+ Tambah Kategori</x-ui.button>
                        </x-slot>
                    @endcan
                </x-ui.empty-state>
            </div>
        @else
            <x-ui.table>
                <thead class="bg-navy-50 text-left text-xs uppercase tracking-wide text-ink-soft">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Kode</th>
                        <th class="px-4 py-3 font-semibold">Nama</th>
                        <th class="px-4 py-3 font-semibold">Urutan</th>
                        <th class="px-4 py-3 font-semibold">Status</th>
                        <th class="px-4 py-3 text-right font-semibold">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($categories as $category)
                        <tr class="hover:bg-navy-50/60">
                            <td class="px-4 py-3 text-ink-soft">{{ $category->code }}</td>
                            <td class="px-4 py-3 font-medium text-navy">{{ $category->name }}</td>
                            <td class="px-4 py-3 text-ink-soft">{{ $category->sort_order }}</td>
                            <td class="px-4 py-3">
                                <x-ui.badge :tone="$category->is_active ? 'success' : 'neutral'">{{ $category->is_active ? 'Aktif' : 'Nonaktif' }}</x-ui.badge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    @can('update', $category)
                                        <x-ui.button href="{{ route('settings.treatment-categories.edit', $category) }}" variant="secondary" size="sm">Ubah</x-ui.button>
                                    @endcan
                                    @can('delete', $category)
                                        <form method="POST" action="{{ route('settings.treatment-categories.destroy', $category) }}" onsubmit="return confirm('Hapus kategori ini?');">
                                            @csrf @method('DELETE')
                                            <x-ui.button type="submit" variant="danger" size="sm">Hapus</x-ui.button>
                                        </form>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>

    <div>{{ $categories->links() }}</div>
</x-settings-shell>
