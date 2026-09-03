<x-settings-shell title="Device Dokter">
    @php
        $statusLabels = [
            'active' => 'Aktif',
            'disabled' => 'Nonaktif',
            'revoked' => 'Dicabut',
        ];
        $statusTone = [
            'active' => 'success',
            'disabled' => 'warning',
            'revoked' => 'danger',
        ];
        $hasFilters = $filters['search'] || $filters['status'] || $filters['branch_id'];
    @endphp

    <x-ui.alert variant="info" class="mb-4">
        Registri perangkat klinik untuk Aplikasi Android DaengtisiaMS.
        <strong>Tahap kapabilitas saja</strong> — pemberlakuan (enforcement) login dokter berbasis perangkat
        <strong>belum aktif</strong>. Dokter tetap login seperti biasa, dan registri kosong tidak memblokir siapa pun.
    </x-ui.alert>

    <x-ui.filter-bar :action="route('settings.doctor-devices.index')">
        <div class="w-full md:w-64">
            <x-ui.input name="search" :value="$filters['search']" placeholder="Cari nama/model perangkat" aria-label="Cari perangkat" />
        </div>
        <div class="w-full md:w-48">
            <x-ui.select name="status" aria-label="Filter status">
                <option value="">Semua status</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $statusLabels[$status] ?? $status }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-56">
            <x-ui.select name="branch_id" aria-label="Filter cabang">
                <option value="">Semua cabang</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}" @selected((int) $filters['branch_id'] === (int) $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <x-slot:actions>
            <x-ui.button type="submit" size="sm">Terapkan</x-ui.button>
            @if ($hasFilters)
                <x-ui.button variant="secondary" size="sm" :href="route('settings.doctor-devices.index')">Atur Ulang</x-ui.button>
            @endif
            @can('create', \App\Modules\DoctorDevice\Models\DoctorDevice::class)
                <x-ui.button size="sm" :href="route('settings.doctor-devices.create')">Daftarkan Perangkat</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.filter-bar>

    @if ($devices->isEmpty())
        <x-ui.empty-state
            title="Belum ada perangkat terdaftar"
            description="Registri masih kosong. Ini tidak memblokir login dokter mana pun." />
    @else
        <x-ui.table>
            <x-slot:head>
                <tr>
                    <th class="px-4 py-3 text-left">Perangkat</th>
                    <th class="px-4 py-3 text-left">Cabang</th>
                    <th class="px-4 py-3 text-left">Platform / Model</th>
                    <th class="px-4 py-3 text-left">Versi Aplikasi</th>
                    <th class="px-4 py-3 text-left">Identitas</th>
                    <th class="px-4 py-3 text-left">Terakhir Terlihat</th>
                    <th class="px-4 py-3 text-left">Status</th>
                    <th class="px-4 py-3 text-left">Terdaftar</th>
                    <th class="px-4 py-3 text-right">Aksi</th>
                </tr>
            </x-slot:head>

            @foreach ($devices as $device)
                <tr class="border-t border-hairline">
                    <td class="px-4 py-3 font-medium text-ink">{{ $device->device_name }}</td>
                    <td class="px-4 py-3 text-ink-soft">{{ $device->branch?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-ink-soft">{{ $device->platform ?? '—' }} / {{ $device->device_model ?? '—' }}</td>
                    <td class="px-4 py-3 text-ink-soft">{{ $device->app_version ?? '—' }}</td>
                    <td class="px-4 py-3 text-ink-soft">
                        @if ($device->isCryptographicallyVerified())
                            <x-ui.badge tone="success">Terverifikasi kriptografis</x-ui.badge>
                        @else
                            <x-ui.badge tone="neutral">Belum terverifikasi</x-ui.badge>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-ink-soft">{{ $device->last_seen_at?->format('d/m/Y H:i') ?? '—' }}</td>
                    <td class="px-4 py-3">
                        <x-ui.badge :tone="$statusTone[$device->status] ?? 'neutral'">{{ $statusLabels[$device->status] ?? $device->status }}</x-ui.badge>
                    </td>
                    <td class="px-4 py-3 text-ink-soft">{{ $device->registered_at?->format('d/m/Y') ?? '—' }}</td>
                    <td class="px-4 py-3 text-right">
                        <x-ui.button variant="secondary" size="sm" :href="route('settings.doctor-devices.show', $device)">Detail</x-ui.button>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>

        <div class="mt-4">{{ $devices->links() }}</div>
    @endif
</x-settings-shell>
