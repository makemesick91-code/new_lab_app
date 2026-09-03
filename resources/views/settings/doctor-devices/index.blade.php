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

    {{-- Phase 3 — Android installs waiting to be paired. Approval binds the
         device's public key; the hardware still has to PROVE possession before
         it is treated as verified. --}}
    @if (($pendingEnrollments ?? collect())->isNotEmpty())
        <x-ui.card class="mb-4" accent>
            <h3 class="text-sm font-semibold text-ink">Menunggu Persetujuan Pendaftaran Perangkat</h3>
            <p class="mt-1 text-xs text-ink-soft">
                Cocokkan <strong>kode pemasangan</strong> yang tampil di layar aplikasi Android dengan permintaan di bawah,
                lalu pilih perangkat registri tujuan.
            </p>

            <div class="mt-4 space-y-4">
                @foreach ($pendingEnrollments as $enrollment)
                    <div class="rounded-lg border border-hairline p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="text-sm text-ink">
                                {{ $enrollment->platform ?? '-' }} / {{ $enrollment->device_model ?? '-' }}
                                <span class="text-ink-soft">· OS {{ $enrollment->os_version ?? '-' }}</span>
                                <span class="text-ink-soft">· app {{ $enrollment->app_version ?? '-' }}</span>
                            </div>
                            <span class="font-mono text-xs text-ink-soft">
                                sidik kunci {{ substr($enrollment->public_key_fingerprint, 0, 12) }}...
                            </span>
                        </div>

                        <div class="mt-3 flex flex-wrap items-end gap-3">
                            <form method="POST" action="{{ route('settings.doctor-device-enrollments.approve', $enrollment) }}" class="flex items-end gap-2">
                                @csrf
                                <x-ui.select name="doctor_device_id" label="Pasangkan ke perangkat" required>
                                    <option value="">Pilih perangkat</option>
                                    @foreach ($devices as $candidate)
                                        @if ($candidate->isActive() && $candidate->public_key_fingerprint === null)
                                            <option value="{{ $candidate->id }}">{{ $candidate->device_name }} - {{ $candidate->branch?->name }}</option>
                                        @endif
                                    @endforeach
                                </x-ui.select>
                                <x-ui.button type="submit" variant="success" size="sm">Setujui</x-ui.button>
                            </form>

                            <form method="POST" action="{{ route('settings.doctor-device-enrollments.reject', $enrollment) }}" class="flex items-end gap-2">
                                @csrf
                                <x-ui.input name="reason" label="Alasan tolak" placeholder="Alasan" required />
                                <x-ui.button type="submit" variant="danger" size="sm">Tolak</x-ui.button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    @endif

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
