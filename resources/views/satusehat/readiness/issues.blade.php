{{-- SATUSEHAT-4A — Data-quality issue workspace (list). Branch-scoped
     server-side; NIK never shown. --}}
<x-settings-shell title="SATUSEHAT — Isu Kualitas Data">
    <x-ui.page-header
        title="Isu Kualitas Data SATUSEHAT"
        subtitle="Isu deterministik dari rule engine — idempotent, tervalidasi server, dan hanya selesai bila datanya benar-benar diperbaiki.">
        <x-slot:breadcrumb>SATUSEHAT</x-slot:breadcrumb>
        <x-ui.button variant="ghost" :href="route('satusehat.readiness.index')">← Dasbor Kesiapan</x-ui.button>
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
    @endif

    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
        <x-ui.kpi-card label="Terbuka (hard)" :value="$aggregates['open_by_severity']['hard'] ?? 0" />
        <x-ui.kpi-card label="Terbuka (soft)" :value="$aggregates['open_by_severity']['soft'] ?? 0" />
        <x-ui.kpi-card label="Terbuka (info)" :value="$aggregates['open_by_severity']['info'] ?? 0" />
        <x-ui.kpi-card label="Selesai" :value="$aggregates['by_status']['resolved'] ?? 0" />
    </div>

    <x-ui.card class="mt-4">
        <x-ui.filter-bar :action="route('satusehat.readiness.issues')" method="GET">
            <x-ui.input label="Cari (nama / no. RM)" name="search" :value="$filters['search'] ?? null" />
            <x-ui.select label="Status" name="status">
                <option value="">Semua</option>
                @foreach (\App\Modules\Satusehat\Models\SatusehatDataQualityIssue::STATUSES as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? null) === $status)>{{ $status }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select label="Severity" name="severity">
                <option value="">Semua</option>
                @foreach (['hard', 'soft', 'info'] as $severity)
                    <option value="{{ $severity }}" @selected(($filters['severity'] ?? null) === $severity)>{{ $severity }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select label="Kategori (rule)" name="rule_code">
                <option value="">Semua</option>
                @foreach ($ruleCodes as $rule)
                    <option value="{{ $rule }}" @selected(($filters['rule_code'] ?? null) === $rule)>{{ $rule }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select label="Pemilik" name="owner_role">
                <option value="">Semua</option>
                @foreach (['Admin Klinik', 'Doctor', 'Supervisor RME', 'Clinical Reviewer', 'IT Operator'] as $role)
                    <option value="{{ $role }}" @selected(($filters['owner_role'] ?? null) === $role)>{{ $role }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select label="Cabang" name="branch_id">
                <option value="">Semua cabang</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}" @selected(($filters['branch_id'] ?? null) == $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </x-ui.select>
            <x-slot:actions>
                <x-ui.button type="submit">Terapkan</x-ui.button>
                <x-ui.button variant="ghost" :href="route('satusehat.readiness.issues')">Atur Ulang</x-ui.button>
            </x-slot:actions>
        </x-ui.filter-bar>

        <div class="mt-4 overflow-x-auto">
            <x-ui.table>
                <thead>
                    <tr>
                        <th class="px-3 py-2 text-left">Isu</th>
                        <th class="px-3 py-2 text-left">Pasien / Kunjungan</th>
                        <th class="px-3 py-2 text-left">Severity</th>
                        <th class="px-3 py-2 text-left">Status</th>
                        <th class="px-3 py-2 text-left">Pemilik</th>
                        <th class="px-3 py-2 text-left">Terdeteksi</th>
                        <th class="px-3 py-2 text-left">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($issues as $issue)
                        <tr class="border-t border-hairline">
                            <td class="px-3 py-2">
                                <span class="font-medium">{{ $issue->rule_code }}</span>
                                <div class="text-xs text-ink-muted">{{ $issue->message }}</div>
                            </td>
                            <td class="px-3 py-2">
                                {{ $issue->patient?->name ?? '—' }}
                                <div class="text-xs text-ink-muted">{{ $issue->clinicVisit?->visit_number }}</div>
                            </td>
                            <td class="px-3 py-2"><x-ui.badge :tone="$issue->severityTone()">{{ $issue->severity }}</x-ui.badge></td>
                            <td class="px-3 py-2"><x-ui.badge :tone="$issue->statusTone()">{{ $issue->statusLabel() }}</x-ui.badge></td>
                            <td class="px-3 py-2">{{ $issue->owner_role ?? '—' }}</td>
                            <td class="px-3 py-2 text-xs text-ink-muted">{{ $issue->last_detected_at?->format('d M Y H:i') }}</td>
                            <td class="px-3 py-2">
                                <x-ui.button size="sm" variant="secondary" :href="route('satusehat.readiness.issues.show', $issue->id)">Detail</x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-3 py-6">
                                <x-ui.empty-state title="Tidak ada isu" description="Jalankan kalkulasi ulang dari dasbor kesiapan untuk memindai kandidat." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table>
        </div>
        <div class="mt-3">{{ $issues->links() }}</div>
    </x-ui.card>
</x-settings-shell>
