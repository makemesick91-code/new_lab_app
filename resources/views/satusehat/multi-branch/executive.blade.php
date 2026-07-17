{{-- SATUSEHAT-4D — executive/owner aggregate dashboard. Aggregate-by-default,
     PII-free, read-only. External submission always disabled. --}}
<x-settings-shell title="SATUSEHAT — Dasbor Eksekutif Multi-Cabang">
    <x-ui.page-header
        title="Dasbor Eksekutif Kesiapan SATUSEHAT"
        subtitle="Ringkasan agregat lintas cabang (tanpa PII). Kesiapan eksternal tetap terpisah — SATUSEHAT-2 tetap WATCH.">
        <x-slot:breadcrumb>SATUSEHAT · Dasbor Eksekutif</x-slot:breadcrumb>
    </x-ui.page-header>

    <x-ui.alert variant="warning" title="Terblokir Eksternal (by design)">
        Pengiriman eksternal <strong>{{ $overview['external_submission_enabled'] ? 'AKTIF (TIDAK DIHARAPKAN)' : 'nonaktif' }}</strong>,
        produksi <strong>{{ $overview['production_blocked'] ? 'terblokir' : 'TIDAK terblokir (TIDAK DIHARAPKAN)' }}</strong>,
        SATUSEHAT-2 <strong>{{ $overview['satusehat_2_status'] }}</strong>.
    </x-ui.alert>

    @php($s = $overview['summary'])
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 my-4">
        <x-ui.card><div class="text-ink-muted text-xs">Total Cabang</div><div class="text-2xl font-semibold">{{ $s['branches_total'] }}</div></x-ui.card>
        <x-ui.card><div class="text-ink-muted text-xs">Remediasi</div><div class="text-2xl font-semibold">{{ $s['branches_in_remediation'] }}</div></x-ui.card>
        <x-ui.card><div class="text-ink-muted text-xs">Siap UAT</div><div class="text-2xl font-semibold">{{ $s['branches_uat_ready'] }}</div></x-ui.card>
        <x-ui.card><div class="text-ink-muted text-xs">Siap Pilot Internal</div><div class="text-2xl font-semibold">{{ $s['branches_pilot_ready_internal'] }}</div></x-ui.card>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 my-4">
        <x-ui.card>
            <div class="font-semibold mb-2">Progres Wave</div>
            <div>Aktif: {{ $overview['wave_progress']['active'] }} · Ditutup: {{ $overview['wave_progress']['closed'] }} · Total: {{ $overview['wave_progress']['total'] }}</div>
        </x-ui.card>
        <x-ui.card>
            <div class="font-semibold mb-2">UAT</div>
            <div>Signed off: {{ $overview['uat_completion']['signed_off_runs'] }} / {{ $overview['uat_completion']['total_runs'] }}
                ({{ $overview['uat_completion']['completion_rate'] ?? 'N/A' }}%)</div>
        </x-ui.card>
        <x-ui.card>
            <div class="font-semibold mb-2">Cakupan Rehearsal</div>
            <div>{{ $overview['rehearsal_coverage']['branches_rehearsed'] }} / {{ $overview['rehearsal_coverage']['branches_total'] }}
                ({{ $overview['rehearsal_coverage']['coverage_rate'] ?? 'N/A' }}%)</div>
        </x-ui.card>
    </div>

    <x-ui.card class="my-4">
        <div class="font-semibold mb-2">Jendela Tata Kelola (agregat)</div>
        <x-ui.table>
            <x-slot:head><tr><th class="text-left">Periode</th><th>Isu Hard Baru</th><th>Source Drift</th><th>Overdue Terbuka</th><th>Demosi</th></tr></x-slot:head>
            @foreach (['daily' => 'Harian', 'weekly' => 'Mingguan', 'monthly' => 'Bulanan'] as $key => $label)
                <tr>
                    <td class="text-left">{{ $label }}</td>
                    <td class="text-center">{{ $windows[$key]['new_hard_issues'] }}</td>
                    <td class="text-center">{{ $windows[$key]['source_drift_issues'] }}</td>
                    <td class="text-center">{{ $windows[$key]['overdue_open_issues'] }}</td>
                    <td class="text-center">{{ $windows[$key]['demotions'] }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-settings-shell>
