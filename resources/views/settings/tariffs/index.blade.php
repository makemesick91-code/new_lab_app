<x-settings-shell title="Master Tarif">
    @php($hasFilters = $filters['search'] || $filters['treatment_id'] || $filters['is_active'] !== null || $filters['requires_lab'] !== null)

    <x-ui.filter-bar :action="route('settings.tariffs.index')">
        <div class="w-full md:w-56">
            <x-ui.input name="search" :value="$filters['search']" placeholder="Cari kode atau nama perawatan" aria-label="Cari tarif" />
        </div>
        <div class="w-full md:w-56">
            <x-ui.select name="treatment_id" aria-label="Filter perawatan">
                <option value="">Semua perawatan</option>
                @foreach ($treatments as $treatment)
                    <option value="{{ $treatment->id }}" @selected($filters['treatment_id'] === $treatment->id)>{{ $treatment->code }} — {{ $treatment->name }}</option>
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
                <x-ui.button href="{{ route('settings.tariffs.index') }}" variant="ghost" size="sm">Atur Ulang</x-ui.button>
            @endif
        </x-slot>
    </x-ui.filter-bar>

    <x-ui.card :padding="'p-0'">
        <div class="flex items-center justify-between gap-3 border-b border-hairline px-5 py-4">
            <h3 class="text-base font-semibold text-navy">Daftar Tarif</h3>
            @can('create', \App\Modules\Tariff\Models\Tariff::class)
                <x-ui.button href="{{ route('settings.tariffs.create') }}" size="sm">+ Tambah Tarif</x-ui.button>
            @endcan
        </div>

        @if ($tariffs->isEmpty())
            <div class="p-5">
                <x-ui.empty-state title="Belum ada tarif" description="Tambahkan tarif perawatan berdasarkan tanggal berlaku dan cabang.">
                    @can('create', \App\Modules\Tariff\Models\Tariff::class)
                        <x-slot name="action">
                            <x-ui.button href="{{ route('settings.tariffs.create') }}" size="sm">+ Tambah Tarif</x-ui.button>
                        </x-slot>
                    @endcan
                </x-ui.empty-state>
            </div>
        @else
            <x-ui.table>
                <thead class="bg-navy-50 text-left text-xs uppercase tracking-wide text-ink-soft">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Kode</th>
                        <th class="px-4 py-3 font-semibold">Perawatan</th>
                        <th class="px-4 py-3 font-semibold">Kategori</th>
                        <th class="px-4 py-3 font-semibold">Lab</th>
                        <th class="px-4 py-3 text-right font-semibold">Harga</th>
                        <th class="px-4 py-3 font-semibold">Berlaku</th>
                        <th class="px-4 py-3 font-semibold">Cabang</th>
                        <th class="px-4 py-3 font-semibold">Status</th>
                        <th class="px-4 py-3 text-right font-semibold">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($tariffs as $tariff)
                        <tr class="hover:bg-navy-50/60">
                            <td class="px-4 py-3 text-ink-soft">{{ $tariff->treatment?->code }}</td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-navy">{{ $tariff->treatment?->name ?? '—' }}</div>
                                @if ($tariff->notes)
                                    <div class="text-xs text-ink-muted">{{ $tariff->notes }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <x-ui.badge tone="primary">{{ $tariff->treatment?->category?->name ?? '—' }}</x-ui.badge>
                            </td>
                            <td class="px-4 py-3">
                                @if ($tariff->treatment?->requires_lab)
                                    <x-ui.badge tone="info">Perlu Lab</x-ui.badge>
                                @else
                                    <span class="text-xs text-ink-muted">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-medium text-navy tabular-nums">Rp {{ number_format((float) $tariff->price, 0, ',', '.') }}</td>
                            <td class="px-4 py-3 text-ink-soft">{{ $tariff->effective_date?->format('d/m/Y') }}</td>
                            <td class="px-4 py-3 text-ink-soft">{{ $tariff->branch?->name ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <x-ui.badge :tone="$tariff->is_active ? 'success' : 'neutral'">{{ $tariff->is_active ? 'Aktif' : 'Nonaktif' }}</x-ui.badge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    @can('update', $tariff)
                                        <x-ui.button href="{{ route('settings.tariffs.edit', $tariff) }}" variant="secondary" size="sm">Ubah</x-ui.button>
                                    @endcan
                                    @can('delete', $tariff)
                                        <form method="POST" action="{{ route('settings.tariffs.destroy', $tariff) }}" onsubmit="return confirm('Hapus tarif ini?');">
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

    <div>{{ $tariffs->links() }}</div>
</x-settings-shell>
