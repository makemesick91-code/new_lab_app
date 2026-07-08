<x-settings-shell title="Master Ruangan">
    @php
        $typeLabels = [
            'treatment_room' => 'Ruang Perawatan',
            'consultation_room' => 'Ruang Konsultasi',
            'xray_room' => 'Ruang Rontgen',
            'sterilization_room' => 'Ruang Sterilisasi',
            'lab_room' => 'Ruang Lab',
            'other' => 'Lainnya',
        ];
        $statusLabels = [
            'active' => 'Aktif',
            'inactive' => 'Nonaktif',
            'maintenance' => 'Pemeliharaan',
        ];
        $statusTone = [
            'active' => 'success',
            'inactive' => 'neutral',
            'maintenance' => 'warning',
        ];
        $hasFilters = $filters['search'] || $filters['type'] || $filters['status'];
    @endphp

    <x-ui.filter-bar :action="route('settings.clinic-rooms.index')">
        <div class="w-full md:w-64">
            <x-ui.input name="search" :value="$filters['search']" placeholder="Cari nama atau kode" aria-label="Cari ruangan" />
        </div>
        <div class="w-full md:w-48">
            <x-ui.select name="type" aria-label="Filter tipe">
                <option value="">Semua tipe</option>
                @foreach ($types as $type)
                    <option value="{{ $type }}" @selected($filters['type'] === $type)>{{ $typeLabels[$type] ?? $type }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-48">
            <x-ui.select name="status" aria-label="Filter status">
                <option value="">Semua status</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $statusLabels[$status] ?? $status }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <x-slot name="actions">
            <x-ui.button type="submit" size="sm">Terapkan</x-ui.button>
            @if ($hasFilters)
                <x-ui.button href="{{ route('settings.clinic-rooms.index') }}" variant="ghost" size="sm">Atur Ulang</x-ui.button>
            @endif
        </x-slot>
    </x-ui.filter-bar>

    <x-ui.card :padding="'p-0'">
        <div class="flex items-center justify-between gap-3 border-b border-hairline px-5 py-4">
            <h3 class="text-base font-semibold text-navy">Daftar Ruangan</h3>
            @can('create', \App\Modules\ClinicRoom\Models\ClinicRoom::class)
                <x-ui.button href="{{ route('settings.clinic-rooms.create') }}" size="sm">+ Tambah Ruangan</x-ui.button>
            @endcan
        </div>

        @if ($rooms->isEmpty())
            <div class="p-5">
                <x-ui.empty-state title="Belum ada ruangan" description="Tambahkan ruangan perawatan untuk cabang RME.">
                    @can('create', \App\Modules\ClinicRoom\Models\ClinicRoom::class)
                        <x-slot name="action">
                            <x-ui.button href="{{ route('settings.clinic-rooms.create') }}" size="sm">+ Tambah Ruangan</x-ui.button>
                        </x-slot>
                    @else
                        <x-slot name="action">
                            <x-ui.restricted-notice description="Anda tidak memiliki akses untuk menambah ruangan. Hubungi administrator jika memerlukan akses." />
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
                        <th class="px-4 py-3 font-semibold">Tipe</th>
                        <th class="px-4 py-3 font-semibold">Cabang</th>
                        <th class="px-4 py-3 font-semibold">Status</th>
                        <th class="px-4 py-3 text-right font-semibold">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($rooms as $room)
                        <tr class="hover:bg-navy-50/60">
                            <td class="px-4 py-3 text-ink-soft">{{ $room->code }}</td>
                            <td class="px-4 py-3 font-medium text-navy">{{ $room->name }}</td>
                            <td class="px-4 py-3 text-ink-soft">{{ $typeLabels[$room->type] ?? $room->type }}</td>
                            <td class="px-4 py-3 text-ink-soft">{{ $room->branch?->name ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <x-ui.badge :tone="$statusTone[$room->status] ?? 'neutral'">{{ $statusLabels[$room->status] ?? $room->status }}</x-ui.badge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    @can('update', $room)
                                        <x-ui.button href="{{ route('settings.clinic-rooms.edit', $room) }}" variant="secondary" size="sm">Ubah</x-ui.button>
                                    @endcan
                                    @can('delete', $room)
                                        <form method="POST" action="{{ route('settings.clinic-rooms.destroy', $room) }}" onsubmit="return confirm('Hapus ruangan ini?');">
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

    <div>{{ $rooms->links() }}</div>
</x-settings-shell>
