@php
    use App\Modules\ClinicOversight\Services\OversightMonitoringService;
    use App\Modules\ClinicOversight\Support\OversightFormat;
@endphp
<x-settings-shell title="Pasien per Ruangan">
    <x-ui.page-header title="Pasien per Ruangan" :subtitle="'Kunjungan pada ruangan dental — tanggal klinik '.$day->date.' (WITA).'">
        <x-slot:breadcrumb>Finance &amp; Data Analyst · Monitoring Dental · Pasien per Ruangan</x-slot:breadcrumb>
        @if ($canExport)
            <x-slot:actions>
                <x-ui.button variant="secondary" size="sm" :href="route('oversight.monitoring.room-patients.export', request()->query())">Export CSV</x-ui.button>
                <x-ui.button variant="primary" size="sm" :href="route('oversight.monitoring.room-patients.print', request()->query())" target="_blank">Cetak / PDF</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @include('oversight.partials.readonly-notice')

    @include('oversight.monitoring.partials.filters', ['action' => route('oversight.monitoring.room-patients'), 'withRoom' => true, 'withStatus' => true])

    <p class="mb-3 text-xs text-ink-soft">Atribusi ruangan = ruangan yang saat ini tersimpan pada kunjungan; riwayat perpindahan ruangan tidak tercatat.</p>

    @if ($visits->isEmpty())
        <x-ui.empty-state title="Tidak ada pasien" description="Tidak ada kunjungan di ruangan dental untuk filter ini." />
    @else
        <x-ui.table>
            <thead class="bg-canvas text-left text-xs uppercase tracking-wide text-ink-soft">
                <tr>
                    <th class="px-3 py-2">Cabang</th>
                    <th class="px-3 py-2">Ruangan</th>
                    <th class="px-3 py-2 text-right">Antrian</th>
                    <th class="px-3 py-2">No. RM</th>
                    <th class="px-3 py-2">Pasien</th>
                    <th class="px-3 py-2">Telepon</th>
                    <th class="px-3 py-2">Dokter</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2">Check-in</th>
                    <th class="px-3 py-2">Status RM</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-hairline">
                @foreach ($visits as $visit)
                    <tr>
                        <td class="px-3 py-2">{{ $visit->branch?->name ?? '-' }}</td>
                        <td class="px-3 py-2">{{ $visit->clinicRoom?->name ?? '-' }}@if ($visit->clinicRoom && $visit->clinicRoom->status !== 'active') <span class="text-xs text-ink-soft">(nonaktif)</span>@endif</td>
                        <td class="px-3 py-2 text-right">{{ $visit->queue_number }}</td>
                        <td class="px-3 py-2 font-mono text-xs">{{ $visit->patient?->medical_record_number }}</td>
                        <td class="px-3 py-2 text-navy">{{ $visit->patient?->name }}</td>
                        <td class="px-3 py-2">{{ $visit->patient?->phone ?: ($visit->patient?->whatsapp_number ?: '-') }}</td>
                        <td class="px-3 py-2">{{ $visit->doctor?->name ?? '-' }}</td>
                        <td class="px-3 py-2"><x-ui.badge :status="$visit->status">{{ OversightMonitoringService::statusLabel($visit->status) }}</x-ui.badge></td>
                        <td class="px-3 py-2">{{ OversightFormat::clinicalTime($visit->check_in_at) ?? '-' }}</td>
                        <td class="px-3 py-2">{{ $visit->medicalRecord ? ($visit->medicalRecord->status === 'final' ? 'Final' : 'Draft') : '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>
        <div class="mt-4">{{ $visits->links() }}</div>
    @endif
</x-settings-shell>
