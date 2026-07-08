<x-settings-shell title="Master Perawatan">
    @php($hasFilters = $filters['search'] || $filters['treatment_category_id'] || $filters['is_active'] !== null || $filters['requires_lab'] !== null)

    <x-ui.filter-bar :action="route('settings.treatments.index')">
        <div class="w-full md:w-56">
            <x-ui.input name="search" :value="$filters['search']" placeholder="Cari nama atau kode" aria-label="Cari perawatan" />
        </div>
        <div class="w-full md:w-48">
            <x-ui.select name="treatment_category_id" aria-label="Filter kategori">
                <option value="">Semua kategori</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected($filters['treatment_category_id'] === $category->id)>{{ $category->name }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-40">
            <x-ui.select name="is_active" aria-label="Filter status">
                <option value="">Semua status</option>
                <option value="1" @selected($filters['is_active'] === true)>Aktif</option>
                <option value="0" @selected($filters['is_active'] === false)>Nonaktif</option>
            </x-ui.select>
        </div>
        <div class="w-full md:w-40">
            <x-ui.select name="requires_lab" aria-label="Filter lab">
                <option value="">Semua lab</option>
                <option value="1" @selected($filters['requires_lab'] === true)>Perlu Lab</option>
                <option value="0" @selected($filters['requires_lab'] === false)>Tanpa Lab</option>
            </x-ui.select>
        </div>
        <x-slot name="actions">
            <x-ui.button type="submit" size="sm">Terapkan</x-ui.button>
            @if ($hasFilters)
                <x-ui.button href="{{ route('settings.treatments.index') }}" variant="ghost" size="sm">Atur Ulang</x-ui.button>
            @endif
        </x-slot>
    </x-ui.filter-bar>

    <x-ui.card :padding="'p-0'">
        <div class="flex items-center justify-between gap-3 border-b border-hairline px-5 py-4">
            <h3 class="text-base font-semibold text-navy">Daftar Perawatan</h3>
            @can('create', \App\Modules\Treatment\Models\Treatment::class)
                <x-ui.button href="{{ route('settings.treatments.create') }}" size="sm">+ Tambah Perawatan</x-ui.button>
            @endcan
        </div>

        @if ($treatments->isEmpty())
            <div class="p-5">
                <x-ui.empty-state title="Belum ada perawatan" description="Tambahkan jenis perawatan beserta kategorinya.">
                    @can('create', \App\Modules\Treatment\Models\Treatment::class)
                        <x-slot name="action">
                            <x-ui.button href="{{ route('settings.treatments.create') }}" size="sm">+ Tambah Perawatan</x-ui.button>
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
                        <th class="px-4 py-3 font-semibold">Kategori</th>
                        <th class="px-4 py-3 font-semibold">Durasi</th>
                        <th class="px-4 py-3 font-semibold">Lab</th>
                        <th class="px-4 py-3 font-semibold">Status</th>
                        <th class="px-4 py-3 text-right font-semibold">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($treatments as $treatment)
                        <tr class="hover:bg-navy-50/60">
                            <td class="px-4 py-3 text-ink-soft">{{ $treatment->code }}</td>
                            <td class="px-4 py-3 font-medium text-navy">{{ $treatment->name }}</td>
                            <td class="px-4 py-3">
                                <x-ui.badge tone="primary">{{ $treatment->category?->name ?? '—' }}</x-ui.badge>
                            </td>
                            <td class="px-4 py-3 text-ink-soft">{{ $treatment->default_duration_minutes ? $treatment->default_duration_minutes.' mnt' : '—' }}</td>
                            <td class="px-4 py-3">
                                @if ($treatment->requires_lab)
                                    <x-ui.badge tone="info">Perlu Lab</x-ui.badge>
                                @else
                                    <span class="text-xs text-ink-muted">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <x-ui.badge :tone="$treatment->is_active ? 'success' : 'neutral'">{{ $treatment->is_active ? 'Aktif' : 'Nonaktif' }}</x-ui.badge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    @can('update', $treatment)
                                        <x-ui.button href="{{ route('settings.treatments.edit', $treatment) }}" variant="secondary" size="sm">Ubah</x-ui.button>
                                    @endcan
                                    @can('delete', $treatment)
                                        <form method="POST" action="{{ route('settings.treatments.destroy', $treatment) }}" onsubmit="return confirm('Hapus perawatan ini?');">
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

    <div>{{ $treatments->links() }}</div>
</x-settings-shell>
