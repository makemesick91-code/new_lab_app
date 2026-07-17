{{-- SATUSEHAT-4C — Pilot operations dashboard. Read-only, PII-free.
     External API status is never shown as successful. --}}
<x-settings-shell title="SATUSEHAT — Operasi Pilot Internal">
    <x-ui.page-header
        title="Dasbor Operasi Pilot Internal"
        subtitle="Metrik operasi pilot internal. Tidak ada status API eksternal yang ditampilkan sebagai berhasil.">
        <x-slot:breadcrumb><a href="{{ route('satusehat.branches.index') }}">Kesiapan Cabang</a> · Operasi Pilot</x-slot:breadcrumb>
    </x-ui.page-header>

    <x-ui.alert variant="warning" title="Blocker Eksternal">
        Status kredensial eksternal: <strong>{{ $overview['external_blocker'] }}</strong>.
        Produksi <strong>{{ $overview['production_blocked'] ? 'TERBLOKIR' : 'TIDAK TERBLOKIR (ANOMALI)' }}</strong>,
        SATUSEHAT-2 <strong>{{ $overview['satusehat2_watch'] ? 'WATCH' : '—' }}</strong>,
        pengiriman eksternal <strong>{{ $overview['external_send_disabled'] ? 'NONAKTIF' : 'AKTIF (ANOMALI)' }}</strong>.
    </x-ui.alert>

    @php($primary = $overview['primary_pilot'])
    <x-ui.card class="mt-4" title="Cabang Pilot Utama">
        @if ($primary)
            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <x-ui.kpi-card label="Cabang" :value="($primary->branch?->code ?? '—')" />
                <x-ui.kpi-card label="Status" :value="$primary->pilotStatusLabel()" />
                <x-ui.kpi-card label="Tahap" :value="$primary->stageLabel()" />
                <x-ui.kpi-card label="Skor Internal" :value="$primary->internal_readiness_score ?? 'N/A'" />
                <x-ui.kpi-card label="Isu Keras" :value="$primary->open_hard_issues" />
                <x-ui.kpi-card label="Adopsi Diagnosis" :value="$primary->diagnosis_adoption_rate !== null ? number_format((float) $primary->diagnosis_adoption_rate, 1).'%' : 'N/A'" />
                <x-ui.kpi-card label="Rehearsal Terakhir" :value="$primary->last_rehearsal_result ?? 'Belum ada'" />
                <x-ui.kpi-card label="Konformansi Lokal" :value="$primary->local_conformance_rate !== null ? number_format((float) $primary->local_conformance_rate, 1).'%' : 'N/A'" />
            </div>
        @else
            <x-ui.empty-state title="Belum ada cabang pilot yang dipilih." description="Tidak ada cabang yang menjadi pilot secara default." />
        @endif
    </x-ui.card>

    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
        <x-ui.card title="Aging Isu Terbuka">
            <div class="grid grid-cols-2 gap-3 text-sm">
                <div class="rounded-lg border border-hairline p-3"><div class="text-ink-muted">&lt; 1 hari</div><div class="text-lg font-semibold">{{ $overview['issue_aging']['fresh_lt_1d'] }}</div></div>
                <div class="rounded-lg border border-hairline p-3"><div class="text-ink-muted">1–3 hari</div><div class="text-lg font-semibold">{{ $overview['issue_aging']['age_1_3d'] }}</div></div>
                <div class="rounded-lg border border-hairline p-3"><div class="text-ink-muted">3–7 hari</div><div class="text-lg font-semibold">{{ $overview['issue_aging']['age_3_7d'] }}</div></div>
                <div class="rounded-lg border border-hairline p-3"><div class="text-ink-muted">&gt; 7 hari</div><div class="text-lg font-semibold">{{ $overview['issue_aging']['age_gt_7d'] }}</div></div>
                <div class="rounded-lg border border-hairline p-3"><div class="text-ink-muted">Lewat SLA</div><div class="text-lg font-semibold text-danger-700">{{ $overview['issue_aging']['overdue'] }}</div></div>
                <div class="rounded-lg border border-hairline p-3"><div class="text-ink-muted">Total Terbuka</div><div class="text-lg font-semibold">{{ $overview['issue_aging']['total_open'] }}</div></div>
            </div>
        </x-ui.card>

        <x-ui.card title="Backlog Operator">
            <p class="text-sm text-ink-soft">Belum ditugaskan: <strong>{{ $overview['operator_backlog']['unassigned'] }}</strong></p>
            <x-ui.table>
                <x-slot:head><tr><th class="text-left">Operator</th><th class="text-right">Terbuka</th><th class="text-right">Lewat SLA</th></tr></x-slot:head>
                @forelse ($overview['operator_backlog']['by_assignee'] as $row)
                    <tr><td>{{ $row['name'] }}</td><td class="text-right">{{ $row['open'] }}</td><td class="text-right">{{ $row['overdue'] }}</td></tr>
                @empty
                    <tr><td colspan="3"><x-ui.empty-state title="Tidak ada isu yang ditugaskan." /></td></tr>
                @endforelse
            </x-ui.table>
        </x-ui.card>
    </div>
</x-settings-shell>
