{{-- SATUSEHAT-4A — Operational readiness dashboard. Credential-independent:
     no external call, NIK never shown, external items honestly BLOCKED. --}}
<x-settings-shell title="SATUSEHAT — Kesiapan Data">
    <x-ui.page-header
        title="Kesiapan Operasional SATUSEHAT"
        subtitle="Profil kualitas data internal per cabang. Item eksternal tetap terblokir sampai kredensial resmi tersedia (SATUSEHAT-2 tetap WATCH).">
        <x-slot:breadcrumb>SATUSEHAT</x-slot:breadcrumb>
        @can('manage_satusehat_remediation')
            <form method="POST" action="{{ route('satusehat.readiness.recalculate') }}">
                @csrf
                <x-ui.button type="submit" variant="secondary">Kalkulasi Ulang (Terbatas)</x-ui.button>
            </form>
        @endcan
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
    @endif

    <x-ui.alert variant="info" title="Status Integrasi">
        Pengiriman eksternal <strong>{{ $integrationEnabled ? 'AKTIF' : 'NONAKTIF' }}</strong> —
        halaman ini hanya menilai kesiapan internal; tidak ada data yang dikirim ke SATUSEHAT.
    </x-ui.alert>

    {{-- Headline metrics --}}
    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
        <x-ui.kpi-card label="Total Kandidat" :value="$metrics['total_candidates']" />
        <x-ui.kpi-card label="Siap (Internal)" :value="$metrics['by_readiness_status']['ready'] ?? 0" />
        <x-ui.kpi-card label="Belum Lengkap" :value="$metrics['by_readiness_status']['incomplete'] ?? 0" />
        <x-ui.kpi-card label="Isu Terbuka" :value="$metrics['open_issue_total']" />
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <x-ui.card title="Isu Terbuka per Kategori" description="Kategori isu kualitas data (rule engine deterministik).">
            @forelse (($metrics['issues']['open_by_rule'] ?? []) as $rule => $total)
                <div class="flex items-center justify-between border-b border-hairline py-1.5 text-sm">
                    <span class="text-ink-soft">{{ $rule }}</span>
                    <x-ui.badge tone="warning">{{ $total }}</x-ui.badge>
                </div>
            @empty
                <p class="text-sm text-ink-muted">Tidak ada isu terbuka.</p>
            @endforelse
        </x-ui.card>

        <x-ui.card title="Isu Terbuka per Pemilik" description="Siapa yang harus memperbaiki.">
            @forelse (($metrics['issues']['open_by_owner_role'] ?? []) as $role => $total)
                <div class="flex items-center justify-between border-b border-hairline py-1.5 text-sm">
                    <span class="text-ink-soft">{{ $role ?: 'Tidak ditetapkan' }}</span>
                    <x-ui.badge tone="info">{{ $total }}</x-ui.badge>
                </div>
            @empty
                <p class="text-sm text-ink-muted">Tidak ada isu terbuka.</p>
            @endforelse
        </x-ui.card>
    </div>

    {{-- Candidate board --}}
    <x-ui.card title="Papan Kandidat" description="Satu status operasional paling dapat ditindaklanjuti per kandidat." class="mt-4">
        <x-ui.filter-bar :action="route('satusehat.readiness.index')" method="GET">
            <x-ui.input label="Cari (nama / no. RM)" name="search" :value="$filters['search'] ?? null" />
            <x-ui.input label="Tanggal dari" name="visit_date_from" type="date" :value="$filters['visit_date_from'] ?? null" />
            <x-ui.input label="Tanggal sampai" name="visit_date_to" type="date" :value="$filters['visit_date_to'] ?? null" />
            <x-ui.select label="Cabang RME" name="branch_id">
                <option value="">Semua cabang</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}" @selected(($filters['branch_id'] ?? null) == $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select label="Readiness" name="readiness_status">
                <option value="">Semua</option>
                @foreach (['ready', 'incomplete', 'blocked', 'source_changed'] as $status)
                    <option value="{{ $status }}" @selected(($filters['readiness_status'] ?? null) === $status)>{{ $status }}</option>
                @endforeach
            </x-ui.select>
            <x-slot:actions>
                <x-ui.button type="submit">Terapkan</x-ui.button>
                <x-ui.button variant="ghost" :href="route('satusehat.readiness.index')">Atur Ulang</x-ui.button>
            </x-slot:actions>
        </x-ui.filter-bar>

        <div class="mt-4 overflow-x-auto">
            <x-ui.table>
                <thead>
                    <tr>
                        <th class="px-3 py-2 text-left">Kunjungan</th>
                        <th class="px-3 py-2 text-left">Pasien</th>
                        <th class="px-3 py-2 text-left">Dokter</th>
                        <th class="px-3 py-2 text-left">Status Operasional</th>
                        <th class="px-3 py-2 text-left">Isu Terbuka</th>
                        <th class="px-3 py-2 text-left">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($board as $candidate)
                        <tr class="border-t border-hairline">
                            <td class="px-3 py-2">
                                {{ $candidate->clinicVisit?->visit_number }}
                                <div class="text-xs text-ink-muted">{{ $candidate->clinicVisit?->visit_date }}</div>
                            </td>
                            <td class="px-3 py-2">
                                {{ $candidate->patient?->name }}
                                <div class="text-xs text-ink-muted">RM: {{ $candidate->patient?->medical_record_number ?? '—' }}</div>
                            </td>
                            <td class="px-3 py-2">{{ $candidate->doctor?->name ?? '—' }}</td>
                            <td class="px-3 py-2">
                                @php
                                    $op = $candidate->operational_status;
                                    $tone = match (true) {
                                        $op === 'READY_INTERNAL' => 'success',
                                        $op === 'BLOCKED_EXTERNAL_CREDENTIAL' => 'info',
                                        in_array($op, ['SOURCE_CHANGED', 'LOCAL_CONFORMANCE_FAILED', 'INVALID_PATIENT_DEMOGRAPHICS'], true) => 'danger',
                                        default => 'warning',
                                    };
                                @endphp
                                <x-ui.badge :tone="$tone">{{ $op }}</x-ui.badge>
                            </td>
                            <td class="px-3 py-2">{{ $candidate->open_issue_count }}</td>
                            <td class="px-3 py-2">
                                <x-ui.button size="sm" variant="secondary" :href="route('satusehat.submissions.show', $candidate)">Detail Kandidat</x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-6">
                                <x-ui.empty-state title="Belum ada kandidat" description="Kandidat dibuat otomatis dari kunjungan selesai dengan rekam medis final." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table>
        </div>
        <div class="mt-3">{{ $board->links() }}</div>
        <div class="mt-2">
            <x-ui.button size="sm" variant="ghost" :href="route('satusehat.readiness.issues')">Lihat Semua Isu Kualitas Data →</x-ui.button>
        </div>
    </x-ui.card>

    {{-- Practitioner / Organization / Location readiness --}}
    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <x-ui.card title="Kesiapan Dokter (Practitioner)" description="IHS tidak pernah dibuat lokal — menunggu kampanye kredensial.">
            <div class="max-h-72 overflow-y-auto">
                @foreach ($practitioners as $row)
                    <div class="flex items-center justify-between border-b border-hairline py-1.5 text-sm">
                        <span class="text-ink-soft">{{ $row['name'] }}</span>
                        <x-ui.badge :tone="str_starts_with($row['status'], 'verified') ? 'success' : ($row['status'] === 'ready_for_lookup' ? 'info' : 'warning')">{{ $row['status'] }}</x-ui.badge>
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        <x-ui.card title="Kesiapan Organization (Cabang)">
            @foreach ($orgLocation['organizations'] as $row)
                <div class="flex items-center justify-between border-b border-hairline py-1.5 text-sm">
                    <span class="text-ink-soft">{{ $row['code'] }} — {{ $row['name'] }}</span>
                    <x-ui.badge :tone="$row['status'] === 'verified_external' ? 'success' : 'info'">{{ $row['status'] }}</x-ui.badge>
                </div>
            @endforeach
        </x-ui.card>

        <x-ui.card title="Kesiapan Location (Ruangan)">
            <div class="max-h-72 overflow-y-auto">
                @foreach ($orgLocation['locations'] as $row)
                    <div class="flex items-center justify-between border-b border-hairline py-1.5 text-sm">
                        <span class="text-ink-soft">{{ $row['code'] }} — {{ $row['name'] }}</span>
                        <x-ui.badge :tone="$row['status'] === 'verified_external' ? 'success' : 'info'">{{ $row['status'] }}</x-ui.badge>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    </div>

    {{-- Treatment mapping + onboarding --}}
    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <x-ui.card title="Ringkasan Mapping Tindakan (Procedure)">
            <div class="grid grid-cols-2 gap-3">
                <x-ui.kpi-card label="Tindakan Aktif" :value="$treatmentSummary['total_active_treatments']" />
                <x-ui.kpi-card label="Ter-mapping Aktif" :value="$treatmentSummary['mapped_active']" />
                <x-ui.kpi-card label="Belum Ter-mapping" :value="$treatmentSummary['unmapped']" />
                <x-ui.kpi-card label="Draft" :value="$treatmentSummary['draft']" />
            </div>
            @can('manage_satusehat_mappings')
                <div class="mt-3">
                    <x-ui.button size="sm" variant="secondary" :href="route('satusehat.mappings.index')">Kelola Mapping Kode</x-ui.button>
                </div>
            @endcan
        </x-ui.card>

        <x-ui.card title="Checklist Onboarding Produksi" description="Item eksternal jujur terblokir sampai kredensial resmi tersedia.">
            <div class="max-h-72 overflow-y-auto">
                @foreach ($checklist['items'] as $item)
                    <div class="flex items-center justify-between border-b border-hairline py-1.5 text-sm">
                        <span class="text-ink-soft">{{ $item['label'] }}</span>
                        <x-ui.badge :tone="match ($item['status']) {
                            'ready_internal', 'verified_external', 'approved' => 'success',
                            'blocked_external' => 'info',
                            'not_started' => 'neutral',
                            default => 'warning',
                        }">{{ $item['status'] }}</x-ui.badge>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    </div>
</x-settings-shell>
