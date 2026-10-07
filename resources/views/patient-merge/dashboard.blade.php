{{-- FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — Dashboard Duplikasi Pasien.

     Counts only, inside the viewer's branch scope. No patient identity is
     rendered on this page.

     Charts (dataviz method): both are single-series magnitude → horizontal /
     vertical bars in ONE validated hue (the brand token, light surface, all six
     checks PASS), direct-labeled, no legend (the title names the series),
     recessive axes, a per-bar hover tooltip, and the numbers are also present
     as text so colour is never the only carrier. Status colours are reserved
     for status badges and are not reused as series colours. --}}
@php
    use App\Modules\PatientMerge\Support\PatientMergeStatus;

    $statusRows = collect(PatientMergeStatus::ALL)
        ->reject(fn ($status) => in_array($status, [PatientMergeStatus::APPROVED, PatientMergeStatus::MERGING], true))
        ->map(fn ($status) => ['label' => PatientMergeStatus::label($status), 'value' => $overview['status_counts'][$status] ?? 0])
        ->values();
    $statusMax = max(1, $statusRows->max('value'));
    $monthly = collect($overview['monthly_completed']);
    $monthlyMax = max(1, $monthly->max());
@endphp

<x-settings-shell title="Duplikasi Pasien">
    <div class="space-y-6">
        <x-ui.page-header title="Dashboard Duplikasi Pasien"
            subtitle="Ringkasan kandidat duplikat dan pengajuan penggabungan dalam cakupan cabang Anda.">
            <x-slot:breadcrumb>Duplikasi Pasien / Dashboard</x-slot:breadcrumb>
            @can('request_patient_merge')
                <x-slot:actions>
                    <x-ui.button :href="route('patient-merge.manual.create')">Pilih Pasien Manual</x-ui.button>
                </x-slot:actions>
            @endcan
        </x-ui.page-header>

        @include('patient-merge.partials.nav')

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4" data-testid="patient-merge-kpis">
            <x-ui.kpi-card label="Kandidat Duplikat" :value="number_format($overview['candidates'])" />
            <x-ui.kpi-card label="Menunggu Review" :value="number_format($overview['awaiting_review'])" />
            <x-ui.kpi-card label="Selesai Digabungkan" :value="number_format($overview['completed'])" />
            <x-ui.kpi-card label="Konflik Risiko Tinggi (terbuka)" :value="number_format($overview['high_risk_open'])" />
            <x-ui.kpi-card label="Draf Pengajuan" :value="number_format($overview['drafts'])" />
            <x-ui.kpi-card label="Ditolak" :value="number_format($overview['rejected'])" />
            <x-ui.kpi-card label="Review Reversal" :value="number_format($overview['reversal_review'])" />
            <x-ui.kpi-card label="RM Alias Aktif" :value="number_format($overview['active_aliases'])" />
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-ui.card title="Pengajuan menurut status" description="Jumlah pengajuan merge per status.">
                <ul class="space-y-2" role="list">
                    @foreach ($statusRows as $row)
                        <li class="grid grid-cols-[9rem_1fr_2.5rem] items-center gap-3 text-sm" title="{{ $row['label'] }}: {{ $row['value'] }}">
                            <span class="text-ink-soft">{{ $row['label'] }}</span>
                            <span class="h-3 rounded-r bg-navy-50" aria-hidden="true">
                                <span class="block h-3 rounded-r-[4px] bg-brand" style="width: {{ $row['value'] > 0 ? max(2, round($row['value'] / $statusMax * 100)) : 0 }}%"></span>
                            </span>
                            <span class="text-right font-semibold tabular-nums text-navy">{{ $row['value'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>

            <x-ui.card title="Penggabungan selesai per bulan" description="{{ $monthly->count() }} bulan terakhir.">
                <div class="flex h-40 items-end gap-3 border-b border-hairline" role="img"
                     aria-label="Penggabungan selesai per bulan: {{ $monthly->map(fn ($v, $k) => $k.' '.$v)->implode(', ') }}">
                    @foreach ($monthly as $month => $value)
                        <div class="flex h-full flex-1 flex-col items-center justify-end gap-1" title="{{ $month }}: {{ $value }} penggabungan">
                            <span class="text-xs font-semibold tabular-nums text-navy">{{ $value }}</span>
                            <span class="w-full max-w-[2.5rem] rounded-t-[4px] bg-brand" style="height: {{ $value > 0 ? max(4, round($value / $monthlyMax * 100)) : 0 }}%"></span>
                        </div>
                    @endforeach
                </div>
                <div class="mt-2 flex gap-3">
                    @foreach ($monthly as $month => $value)
                        <span class="flex-1 text-center text-xs text-ink-muted">{{ \Carbon\Carbon::createFromFormat('Y-m', $month)->translatedFormat('M y') }}</span>
                    @endforeach
                </div>
            </x-ui.card>
        </div>

        <x-ui.alert variant="info" title="Prinsip penggabungan">
            Penggabungan memindahkan kepemilikan data, bukan menghapus riwayat klinis. Tidak ada penggabungan otomatis:
            setiap penggabungan membutuhkan rekonsiliasi identitas per kolom, review, dan persetujuan peninjau yang berbeda dari pengaju.
            Nomor RM pasien sumber tetap dapat dicari sebagai RM alias.
        </x-ui.alert>
    </div>
</x-settings-shell>
