{{-- SATUSEHAT-4C — Branch readiness board. Credential-independent: no external
     call, NIK never shown, external readiness stays a separate blocker. --}}
<x-settings-shell title="SATUSEHAT — Kesiapan Cabang">
    <x-ui.page-header
        title="Kesiapan Cabang & Pilot Internal SATUSEHAT"
        subtitle="Skor kesiapan internal per cabang untuk pilot internal. Kesiapan eksternal tetap terpisah — kredensial SATUSEHAT belum tersedia (SATUSEHAT-2 tetap WATCH).">
        <x-slot:breadcrumb>SATUSEHAT · Kesiapan Cabang</x-slot:breadcrumb>
        @can('view_satusehat_pilot_metrics')
            <a href="{{ route('satusehat.branches.pilot-operations') }}">
                <x-ui.button variant="secondary">Dasbor Operasi Pilot</x-ui.button>
            </a>
        @endcan
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
    @endif
    @error('branch_id')<x-ui.alert variant="danger">{{ $message }}</x-ui.alert>@enderror

    <x-ui.alert variant="warning" title="Kesiapan Internal Saja">
        Skor & status pilot di halaman ini bersifat <strong>internal</strong>. Tidak ada pengiriman ke SATUSEHAT,
        tidak ada OAuth/sandbox/produksi. Blocker eksternal <strong>BLOCKED_EXTERNAL_CREDENTIAL</strong> selalu ada
        sampai Kampanye Penutupan Kredensial SATUSEHAT-2.
    </x-ui.alert>

    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
        <x-ui.kpi-card label="Isu Terbuka (Aging)" :value="$aging['total_open']" />
        <x-ui.kpi-card label="Isu Lewat SLA" :value="$aging['overdue']" />
        <x-ui.kpi-card label="Produksi Terblokir" :value="$productionBlocked ? 'YA' : 'TIDAK'" />
        <x-ui.kpi-card label="SATUSEHAT-2" :value="$satusehat2Watch ? 'WATCH' : '—'" />
    </div>

    <x-ui.card class="mt-4">
        <x-ui.table>
            <x-slot:head>
                <tr>
                    <th class="text-left">Cabang</th>
                    <th class="text-left">Status Pilot</th>
                    <th class="text-left">Tahap</th>
                    <th class="text-right">Skor Internal</th>
                    <th class="text-right">Isu Keras</th>
                    <th class="text-right">Adopsi Diagnosis</th>
                    <th class="text-right">Aksi</th>
                </tr>
            </x-slot:head>
            @forelse ($board as $row)
                <tr>
                    <td class="font-medium">{{ $row['code'] }} — {{ $row['name'] }}</td>
                    <td>
                        <x-ui.badge :tone="$row['pilot_status'] === 'approved' ? 'success' : ($row['pilot_status'] === 'suspended' ? 'danger' : 'neutral')">
                            {{ ($row['profile'] ?? null)?->pilotStatusLabel() ?? 'Bukan Pilot' }}
                        </x-ui.badge>
                    </td>
                    <td>{{ ($row['profile'] ?? null)?->stageLabel() ?? 'Belum Dimulai' }}</td>
                    <td class="text-right">{{ $row['internal_readiness_score'] ?? 'N/A' }}</td>
                    <td class="text-right">{{ $row['open_hard_issues'] }}</td>
                    <td class="text-right">{{ $row['diagnosis_adoption_rate'] !== null ? number_format((float) $row['diagnosis_adoption_rate'], 1).'%' : 'N/A' }}</td>
                    <td class="text-right">
                        <a href="{{ route('satusehat.branches.show', $row['branch_id']) }}">
                            <x-ui.button size="sm" variant="secondary">Detail</x-ui.button>
                        </a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7"><x-ui.empty-state title="Belum ada cabang RME dalam cakupan Anda." /></td></tr>
            @endforelse
        </x-ui.table>
    </x-ui.card>
</x-settings-shell>
