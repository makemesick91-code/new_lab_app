@php
    use App\Modules\ClinicOversight\Services\OversightMonitoringService;
@endphp
<x-settings-shell title="Ruangan Dental Aktif">
    <x-ui.page-header title="Ruangan Dental Aktif" subtitle="Ruang perawatan & konsultasi aktif di setiap cabang RME, dengan jumlah kunjungan pada tanggal klinik terpilih (WITA).">
        <x-slot:breadcrumb>Finance &amp; Data Analyst · Monitoring Dental · Ruangan Dental Aktif</x-slot:breadcrumb>
    </x-ui.page-header>

    @include('oversight.partials.readonly-notice')

    <x-ui.filter-bar :action="route('oversight.monitoring.rooms')">
        <div class="w-full md:w-56">
            <x-ui.select name="branch_id" label="Cabang">
                <option value="">Semua cabang RME</option>
                @foreach ($branchOptions as $b)
                    <option value="{{ $b->id }}" @selected((string) ($filters['branch_id'] ?? '') === (string) $b->id)>{{ $b->name }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div class="w-full md:w-44">
            <x-ui.input type="date" name="date" label="Tanggal klinik" :value="$day->date" />
        </div>
        <x-slot:actions>
            <x-ui.button type="submit" variant="primary" size="sm">Terapkan</x-ui.button>
            <x-ui.button variant="secondary" size="sm" :href="route('oversight.monitoring.rooms')">Hari ini</x-ui.button>
        </x-slot:actions>
    </x-ui.filter-bar>

    <p class="mb-3 text-xs text-ink-soft">Ruang sterilisasi, laboratorium, x-ray, dan ruang non-dental tidak ditampilkan. Ruangan nonaktif tidak ditampilkan. Dokter tidak terikat permanen ke ruangan — dokter yang tercantum berasal dari data kunjungan.</p>

    @if ($rooms->isEmpty())
        <x-ui.empty-state title="Tidak ada ruangan dental aktif" description="Tidak ada ruang perawatan atau konsultasi aktif pada cabang terpilih." />
    @else
        <x-ui.table>
            <thead class="bg-canvas text-left text-xs uppercase tracking-wide text-ink-soft">
                <tr>
                    <th class="px-4 py-2">Cabang</th>
                    <th class="px-4 py-2">Ruangan</th>
                    <th class="px-4 py-2">Tipe</th>
                    <th class="px-4 py-2 text-right">Kunjungan</th>
                    <th class="px-4 py-2 text-right">Dalam Pemeriksaan</th>
                    <th class="px-4 py-2 text-right">Menunggu</th>
                    <th class="px-4 py-2 text-right">Selesai / Kasir</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-hairline">
                @foreach ($rooms as $room)
                    @php
                        $c = $counts[$room->id] ?? [];
                        $total = array_sum($c);
                    @endphp
                    <tr>
                        <td class="px-4 py-2">{{ $room->branch?->name ?? '-' }}</td>
                        <td class="px-4 py-2 text-navy">{{ $room->name }} <span class="text-xs text-ink-soft">({{ $room->code }})</span></td>
                        <td class="px-4 py-2">{{ OversightMonitoringService::ROOM_TYPE_LABELS[$room->type] ?? $room->type }}</td>
                        <td class="px-4 py-2 text-right">{{ $total }}</td>
                        <td class="px-4 py-2 text-right">{{ $c['in_progress'] ?? 0 }}</td>
                        <td class="px-4 py-2 text-right">{{ ($c['registered'] ?? 0) + ($c['waiting'] ?? 0) }}</td>
                        <td class="px-4 py-2 text-right">{{ ($c['completed'] ?? 0) + ($c['cashier_pending'] ?? 0) }}</td>
                        <td class="px-4 py-2 text-right">
                            <x-ui.button variant="secondary" size="sm" :href="route('oversight.monitoring.room-patients', ['branch_id' => $room->branch_id, 'room_id' => $room->id, 'date' => $day->date])">Pasien</x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>
    @endif
</x-settings-shell>
