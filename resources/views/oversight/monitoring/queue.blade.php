@php
    use App\Modules\ClinicOversight\Services\OversightMonitoringService;
    use App\Modules\ClinicOversight\Support\OversightFormat;
    $route = $perBranch ? 'oversight.monitoring.queue.branches' : 'oversight.monitoring.queue';
@endphp
<x-settings-shell :title="$perBranch ? 'Antrian per Cabang' : 'Monitoring Antrian'">
    <x-ui.page-header :title="$perBranch ? 'Antrian per Cabang' : 'Monitoring Antrian — Semua Cabang'" :subtitle="'Antrian kunjungan tanggal klinik '.$day->date.' (WITA).'">
        <x-slot:breadcrumb>Finance &amp; Data Analyst · Monitoring Antrian</x-slot:breadcrumb>
        @if ($canExport)
            <x-slot:actions>
                <x-ui.button variant="secondary" size="sm" :href="route('oversight.monitoring.queue.export', request()->query())">Export CSV</x-ui.button>
                <x-ui.button variant="primary" size="sm" :href="route('oversight.monitoring.queue.print', request()->query())" target="_blank">Cetak / PDF</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @include('oversight.partials.readonly-notice')

    @include('oversight.monitoring.partials.filters', ['action' => route($route), 'withStatus' => true])

    <x-ui.card title="Ringkasan per Cabang" description="Kunjungan tertunda dari tanggal sebelumnya ditampilkan terpisah dan tidak dihitung sebagai antrian hari ini." padding="p-5">
        <x-ui.table>
            <thead class="bg-canvas text-left text-xs uppercase tracking-wide text-ink-soft">
                <tr>
                    <th class="px-3 py-2">Cabang</th>
                    @foreach ($statusLabels as $label)
                        <th class="px-3 py-2 text-right">{{ $label }}</th>
                    @endforeach
                    <th class="px-3 py-2 text-right">Total</th>
                    <th class="px-3 py-2 text-right">Tertunda (tanggal sebelumnya)</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-hairline">
                @forelse ($byBranch as $row)
                    <tr>
                        <td class="px-3 py-2 text-navy">{{ $row['branch']->name }}</td>
                        @foreach (array_keys($statusLabels) as $status)
                            <td class="px-3 py-2 text-right">{{ $row['counts'][$status] ?? 0 }}</td>
                        @endforeach
                        <td class="px-3 py-2 text-right font-semibold">{{ $row['total'] }}</td>
                        <td class="px-3 py-2 text-right">{{ $row['stale_unfinished'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($statusLabels) + 3 }}" class="px-3 py-4 text-center text-ink-soft">Tidak ada cabang dalam cakupan.</td></tr>
                @endforelse
            </tbody>
        </x-ui.table>
    </x-ui.card>

    <x-ui.card title="Daftar Antrian" padding="p-5">
        @if ($visits->isEmpty())
            <x-ui.empty-state title="Antrian kosong" description="Tidak ada kunjungan untuk filter ini." />
        @else
            @php $grouped = $perBranch ? $visits->getCollection()->groupBy(fn ($v) => $v->branch?->name ?? '-') : collect(['' => $visits->getCollection()]); @endphp
            @foreach ($grouped as $branchName => $rows)
                @if ($perBranch)
                    <h3 class="mb-2 mt-4 text-sm font-semibold text-navy">{{ $branchName }}</h3>
                @endif
                <x-ui.table>
                    <thead class="bg-canvas text-left text-xs uppercase tracking-wide text-ink-soft">
                        <tr>
                            @unless ($perBranch)<th class="px-3 py-2">Cabang</th>@endunless
                            <th class="px-3 py-2 text-right">No.</th>
                            <th class="px-3 py-2">Pasien</th>
                            <th class="px-3 py-2">Ruangan</th>
                            <th class="px-3 py-2">Dokter</th>
                            <th class="px-3 py-2">Status</th>
                            <th class="px-3 py-2">Check-in</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($rows as $visit)
                            <tr>
                                @unless ($perBranch)<td class="px-3 py-2">{{ $visit->branch?->name ?? '-' }}</td>@endunless
                                <td class="px-3 py-2 text-right">{{ $visit->queue_number }}</td>
                                <td class="px-3 py-2"><span class="text-navy">{{ $visit->patient?->name }}</span> <span class="font-mono text-xs text-ink-soft">{{ $visit->patient?->medical_record_number }}</span></td>
                                <td class="px-3 py-2">{{ $visit->clinicRoom?->name ?? '-' }}</td>
                                <td class="px-3 py-2">{{ $visit->doctor?->name ?? '-' }}</td>
                                <td class="px-3 py-2"><x-ui.badge :status="$visit->status">{{ OversightMonitoringService::statusLabel($visit->status) }}</x-ui.badge></td>
                                <td class="px-3 py-2">{{ OversightFormat::clinicalTime($visit->check_in_at) ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endforeach
            <div class="mt-4">{{ $visits->links() }}</div>
        @endif
    </x-ui.card>
</x-settings-shell>
