{{-- SATUSEHAT-4D — comparative multi-branch readiness matrix. Read-only,
     PII-free, external readiness always a separate blocker. --}}
<x-settings-shell title="SATUSEHAT — Matriks Kesiapan Multi-Cabang">
    <x-ui.page-header
        title="Matriks Kesiapan Multi-Cabang SATUSEHAT"
        subtitle="Perbandingan kesiapan internal seluruh cabang RME. Kesiapan eksternal tetap terpisah — SATUSEHAT-2 tetap WATCH.">
        <x-slot:breadcrumb>SATUSEHAT · Matriks Multi-Cabang</x-slot:breadcrumb>
        @can('view_satusehat_executive_readiness')
            <a href="{{ route('satusehat.executive.index') }}"><x-ui.button variant="secondary">Dasbor Eksekutif</x-ui.button></a>
        @endcan
    </x-ui.page-header>

    <x-ui.alert variant="warning" title="Kesiapan Internal Saja">
        Tidak ada pengiriman ke SATUSEHAT, tidak ada OAuth/sandbox/produksi. Setiap cabang tetap
        <strong>BLOCKED_EXTERNAL_CREDENTIAL</strong> hingga Kampanye Penutupan Kredensial SATUSEHAT-2.
    </x-ui.alert>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 my-4">
        <x-ui.card><div class="text-ink-muted text-xs">Cabang</div><div class="text-2xl font-semibold">{{ $summary['branches_total'] }}</div></x-ui.card>
        <x-ui.card><div class="text-ink-muted text-xs">Siap Pilot Internal</div><div class="text-2xl font-semibold">{{ $summary['branches_pilot_ready_internal'] }}</div></x-ui.card>
        <x-ui.card><div class="text-ink-muted text-xs">Ada Hard Blocker</div><div class="text-2xl font-semibold">{{ $summary['branches_with_hard_blockers'] }}</div></x-ui.card>
        <x-ui.card><div class="text-ink-muted text-xs">Terblokir Eksternal</div><div class="text-2xl font-semibold">{{ $summary['branches_blocked_external_credential'] }}</div></x-ui.card>
    </div>

    <form method="GET" class="mb-4">
        <x-ui.filter-bar>
            <x-ui.input name="search" :value="$filters['search']" placeholder="Cari cabang…" />
            <x-ui.input name="stage" :value="$filters['stage']" placeholder="Stage…" />
            <x-ui.button type="submit">Terapkan</x-ui.button>
        </x-ui.filter-bar>
    </form>

    <x-ui.table>
        <x-slot:head>
            <tr>
                <th class="text-left">Cabang</th><th>Wave</th><th>Stage</th><th>Skor</th>
                <th>Hard</th><th>Overdue</th><th>Adopsi Dx</th><th>UAT</th><th>Rehearsal</th>
                <th>Blocker Eksternal</th><th>Eligible Promosi</th>
            </tr>
        </x-slot:head>
        @forelse ($rows as $r)
            <tr>
                <td class="text-left">{{ $r['name'] }} <span class="text-ink-muted">({{ $r['code'] }})</span></td>
                <td class="text-center">{{ $r['wave_name'] ?? '—' }}</td>
                <td class="text-center"><x-ui.badge>{{ $r['readiness_stage'] }}</x-ui.badge></td>
                <td class="text-center">{{ $r['internal_readiness_score'] ?? 'N/A' }}</td>
                <td class="text-center">{{ $r['open_hard_issues'] }}</td>
                <td class="text-center">{{ $r['overdue_issues'] }}</td>
                <td class="text-center">{{ $r['diagnosis_adoption_rate'] ?? 'N/A' }}</td>
                <td class="text-center">{{ $r['uat_status'] }}</td>
                <td class="text-center">{{ $r['last_rehearsal_result'] ?? '—' }}</td>
                <td class="text-center"><x-ui.badge tone="danger">blocked_external_credential</x-ui.badge></td>
                <td class="text-center">{{ $r['promotion_eligible'] ? 'Ya' : 'Tidak' }}</td>
            </tr>
        @empty
            <tr><td colspan="11"><x-ui.empty-state title="Belum ada cabang dalam cakupan." /></td></tr>
        @endforelse
    </x-ui.table>

    <div class="mt-4">{{ $rows->withQueryString()->links() }}</div>
</x-settings-shell>
