@php
    /**
     * LAB-PROD-3 — Technician Capacity Planning (presentation only).
     * Every figure is server-computed + scoped in LabTechnicianCapacityPlanningService.
     * No query, no formula, no PII in this view. No auto-assignment.
     */
    $summary = $plan['summary'];
    $period = $plan['period'];
    $dq = $plan['data_quality'];
    $unit = $plan['planning_unit'];

    $fmtNum = fn ($v) => $v === null ? 'N/A' : number_format((float) $v, 2);
    $fmtPct = fn ($v) => $v === null ? 'N/A' : number_format((float) $v, 1) . '%';
    $fmtDays = fn ($v) => $v === null ? '—' : number_format((float) $v, 1) . ' hari';

    $bandTone = [
        'NORMAL' => 'success', 'WATCH' => 'warning', 'OVER_CAPACITY' => 'danger',
        'UNAVAILABLE' => 'neutral', 'UNCONFIGURED' => 'neutral', 'PARTIAL_DATA' => 'info',
    ];
    $riskTone = [
        'ON_TRACK' => 'success', 'AT_RISK' => 'warning', 'PROJECTED_LATE' => 'danger',
        'OVERDUE' => 'danger', 'NO_DUE_DATE' => 'neutral', 'UNPLANNABLE' => 'neutral',
    ];
    $dailyMax = max(1, collect($plan['daily'])->max('available_capacity') ?: 1);
    $exportUrl = route('lab-capacity-planning.export', request()->query());
@endphp

<x-settings-shell title="Perencanaan Kapasitas Teknisi">
    <x-ui.page-header
        title="Perencanaan Kapasitas Teknisi"
        subtitle="Dukungan keputusan kapasitas — periode {{ $period['from'] }} s/d {{ $period['to'] }} · unit {{ $unit }}. Bukan penugasan otomatis.">
        <x-slot:breadcrumb>Laboratorium / Kapasitas Teknisi</x-slot:breadcrumb>
        <x-slot:actions>
            @if ($scope['can_export'])
                <x-ui.button :href="$exportUrl" variant="secondary" size="sm">Ekspor CSV</x-ui.button>
            @endif
            @if ($scope['can_manage'] && Route::has('lab-capacity-planning.configuration'))
                <x-ui.button :href="route('lab-capacity-planning.configuration')" variant="primary" size="sm">Kelola Konfigurasi</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @unless ($configured)
        <x-ui.alert variant="warning" title="Perencanaan belum siap">
            Perencanaan kapasitas belum lengkap karena profil kapasitas teknisi atau profil workload layanan belum dikonfigurasi.
            Angka di bawah dapat berstatus UNCONFIGURED/UNPLANNABLE — bukan nol yang berarti kosong.
            @if ($scope['can_manage'] && Route::has('lab-capacity-planning.configuration'))
                <a class="font-semibold underline" href="{{ route('lab-capacity-planning.configuration') }}">Lengkapi konfigurasi</a>.
            @endif
        </x-ui.alert>
    @endunless

    {{-- Filters --}}
    <x-ui.filter-bar :action="route('lab-capacity-planning.index')" method="GET">
        <x-ui.select name="horizon" label="Horizon">
            @foreach ($options['horizons'] as $h)
                <option value="{{ $h }}" @selected(request('horizon') == $h)>{{ $h }} hari</option>
            @endforeach
            <option value="custom" @selected(request('horizon') === 'custom')>Kustom</option>
        </x-ui.select>
        <x-ui.input type="date" name="from" label="Dari (kustom)" :value="request('from')" />
        <x-ui.input type="date" name="to" label="Sampai (kustom)" :value="request('to')" />
        @if ($scope['tier'] === 'full')
            <x-ui.select name="branch_id" label="Cabang (Demand)">
                <option value="">Semua cabang</option>
                @foreach ($options['branches'] as $b)
                    <option value="{{ $b['id'] }}" @selected(request('branch_id') == $b['id'])>{{ $b['name'] }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select name="technician_id" label="Teknisi">
                <option value="">Semua teknisi</option>
                @foreach ($options['technicians'] as $t)
                    <option value="{{ $t['id'] }}" @selected(request('technician_id') == $t['id'])>{{ $t['name'] }}</option>
                @endforeach
            </x-ui.select>
        @endif
        <x-ui.select name="lab_service_id" label="Layanan">
            <option value="">Semua layanan</option>
            @foreach ($options['services'] as $s)
                <option value="{{ $s['id'] }}" @selected(request('lab_service_id') == $s['id'])>{{ $s['name'] }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.select name="sourcing" label="Sumber">
            <option value="">Internal + Eksternal</option>
            <option value="internal" @selected(request('sourcing') === 'internal')>Internal</option>
            <option value="external" @selected(request('sourcing') === 'external')>Eksternal</option>
        </x-ui.select>
        <x-slot:actions>
            <x-ui.button type="submit" variant="primary" size="sm">Terapkan</x-ui.button>
            <x-ui.button :href="route('lab-capacity-planning.index')" variant="ghost" size="sm">Atur Ulang</x-ui.button>
        </x-slot:actions>
    </x-ui.filter-bar>

    {{-- Summary KPIs --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.kpi-card label="Teknisi Aktif" :value="$summary['active_technicians']" :delta="$summary['configured_technicians'].' terkonfigurasi'" />
        <x-ui.kpi-card label="Kapasitas Tersedia" :value="$fmtNum($summary['available_capacity'])" :delta="$unit" />
        <x-ui.kpi-card label="Beban Ditugaskan" :value="$fmtNum($summary['assigned_load'])" :delta="$unit" />
        <x-ui.kpi-card label="Permintaan Belum Ditugaskan" :value="$fmtNum($summary['unassigned_demand'])" :delta="$unit" />
        <x-ui.kpi-card label="Selisih Kapasitas" :value="$fmtNum($summary['capacity_gap'])" :delta="'tersedia − ditugaskan'" />
        <x-ui.kpi-card label="Utilisasi" :value="$fmtPct($summary['utilization'])" :delta="'Status: '.$summary['band']" />
        <x-ui.kpi-card label="Order Berisiko Telat" :value="$summary['projected_late_count'] + $summary['overdue_count']" :delta="$summary['overdue_count'].' sudah lewat'" />
        <x-ui.kpi-card label="Tidak Dapat Direncanakan" :value="$summary['unplannable_count']" :delta="'profil workload hilang'" />
    </div>

    {{-- Data quality / coverage --}}
    <x-ui.card title="Cakupan & Kualitas Data" class="mt-6">
        <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
            <div><dt class="text-ink-muted">Order terbuka</dt><dd class="font-semibold">{{ $dq['total_open_orders'] }}</dd></div>
            <div><dt class="text-ink-muted">Teknisi terkonfigurasi</dt><dd class="font-semibold">{{ $dq['technicians_configured'] }} / {{ $summary['active_technicians'] }}</dd></div>
            <div><dt class="text-ink-muted">Teknisi UNCONFIGURED</dt><dd class="font-semibold">{{ $dq['technicians_unconfigured'] }}</dd></div>
            <div><dt class="text-ink-muted">Unit tidak cocok</dt><dd class="font-semibold">{{ $dq['unit_mismatch_technicians'] }}</dd></div>
            <div><dt class="text-ink-muted">Layanan punya profil workload</dt><dd class="font-semibold">{{ $dq['services_with_workload_profile'] }} / {{ $dq['services_total'] }}</dd></div>
            <div><dt class="text-ink-muted">Order diasumsikan penuh</dt><dd class="font-semibold">{{ $dq['assumed_full_orders'] }}</dd></div>
            <div><dt class="text-ink-muted">Order UNPLANNABLE</dt><dd class="font-semibold">{{ $dq['unplannable_orders'] }}</dd></div>
            <div><dt class="text-ink-muted">Konteks historis</dt><dd class="font-semibold">{{ $dq['historical_available'] ? 'Tersedia' : 'Tidak tersedia' }}</dd></div>
        </div>
    </x-ui.card>

    {{-- Daily capacity timeline --}}
    <x-ui.card title="Kapasitas Harian ({{ $unit }})" class="mt-6">
        <div class="overflow-x-auto">
            <div class="flex min-w-full items-end gap-1" style="height: 120px;">
                @foreach ($plan['daily'] as $d)
                    <div class="flex flex-1 flex-col items-center justify-end" title="{{ $d['date'] }} · {{ $fmtNum($d['available_capacity']) }} {{ $unit }} · {{ $d['due_count'] }} jatuh tempo">
                        <div class="w-full rounded-t bg-brand-500" style="height: {{ max(2, (int) ($d['available_capacity'] / $dailyMax * 100)) }}%"></div>
                        <span class="mt-1 text-[10px] text-ink-muted">{{ \Illuminate\Support\Carbon::parse($d['date'])->format('d/m') }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </x-ui.card>

    {{-- Technician capacity table --}}
    <x-ui.card title="Kapasitas per Teknisi" class="mt-6">
        <x-ui.table>
            <thead class="bg-navy-50 text-ink">
                <tr>
                    <th class="px-3 py-2 text-left">Teknisi</th>
                    <th class="px-3 py-2 text-right">Tersedia</th>
                    <th class="px-3 py-2 text-right">Ditugaskan</th>
                    <th class="px-3 py-2 text-right">Selisih</th>
                    <th class="px-3 py-2 text-right">Utilisasi</th>
                    <th class="px-3 py-2 text-right">Order Aktif</th>
                    <th class="px-3 py-2 text-right">Berisiko</th>
                    <th class="px-3 py-2 text-left">Status</th>
                    <th class="px-3 py-2 text-left">Cakupan</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($plan['technicians'] as $t)
                <tr class="border-t border-hairline">
                    <td class="px-3 py-2">{{ $t['name'] }}</td>
                    <td class="px-3 py-2 text-right">{{ $fmtNum($t['available']) }}</td>
                    <td class="px-3 py-2 text-right">{{ $fmtNum($t['assigned_load']) }}</td>
                    <td class="px-3 py-2 text-right">{{ $fmtNum($t['capacity_gap']) }}</td>
                    <td class="px-3 py-2 text-right">{{ $fmtPct($t['utilization']) }}</td>
                    <td class="px-3 py-2 text-right">{{ $t['active_orders'] }}</td>
                    <td class="px-3 py-2 text-right">{{ $t['due_risk_count'] }}</td>
                    <td class="px-3 py-2"><x-ui.badge :tone="$bandTone[$t['band']] ?? 'neutral'">{{ $t['band'] }}</x-ui.badge></td>
                    <td class="px-3 py-2 text-ink-muted">{{ $t['coverage'] }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-3 py-6 text-center text-ink-muted">Tidak ada teknisi dalam cakupan.</td></tr>
            @endforelse
            </tbody>
        </x-ui.table>
    </x-ui.card>

    {{-- Service demand table --}}
    <x-ui.card title="Permintaan per Layanan" class="mt-6">
        <x-ui.table>
            <thead class="bg-navy-50 text-ink">
                <tr>
                    <th class="px-3 py-2 text-left">Layanan</th>
                    <th class="px-3 py-2 text-right">Order Terbuka</th>
                    <th class="px-3 py-2 text-right">Ditugaskan</th>
                    <th class="px-3 py-2 text-right">Belum Ditugaskan</th>
                    <th class="px-3 py-2 text-right">Kapasitas Layak</th>
                    <th class="px-3 py-2 text-right">Tanpa Profil</th>
                    <th class="px-3 py-2 text-left">Profil Workload</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($plan['services'] as $s)
                <tr class="border-t border-hairline">
                    <td class="px-3 py-2">{{ $s['name'] }}</td>
                    <td class="px-3 py-2 text-right">{{ $s['open'] }}</td>
                    <td class="px-3 py-2 text-right">{{ $s['assigned'] }}</td>
                    <td class="px-3 py-2 text-right">{{ $s['unassigned'] }}</td>
                    <td class="px-3 py-2 text-right">{{ $fmtNum($s['eligible_capacity']) }}</td>
                    <td class="px-3 py-2 text-right">{{ $s['missing_profile'] }}</td>
                    <td class="px-3 py-2">
                        <x-ui.badge :tone="$s['has_workload_profile'] ? 'success' : 'warning'">
                            {{ $s['has_workload_profile'] ? 'Ada' : 'UNPLANNABLE' }}
                        </x-ui.badge>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-3 py-6 text-center text-ink-muted">Tidak ada permintaan layanan terbuka.</td></tr>
            @endforelse
            </tbody>
        </x-ui.table>
    </x-ui.card>

    {{-- Unassigned queue + recommendations --}}
    <x-ui.card title="Antrian Belum Ditugaskan & Rekomendasi" class="mt-6">
        <p class="mb-3 text-sm text-ink-muted">Rekomendasi bersifat baca-saja dan dapat dijelaskan. Tidak ada penugasan otomatis.</p>
        <x-ui.table>
            <thead class="bg-navy-50 text-ink">
                <tr>
                    <th class="px-3 py-2 text-left">Order</th>
                    <th class="px-3 py-2 text-left">Cabang</th>
                    <th class="px-3 py-2 text-left">Jatuh Tempo</th>
                    <th class="px-3 py-2 text-right">Sisa Beban</th>
                    <th class="px-3 py-2 text-left">Risiko</th>
                    <th class="px-3 py-2 text-left">Kandidat Teknisi</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($plan['unassigned_orders'] as $o)
                <tr class="border-t border-hairline align-top">
                    <td class="px-3 py-2 font-mono text-xs">{{ $o['order_number'] }}</td>
                    <td class="px-3 py-2">{{ $o['branch_name'] ?? '—' }}</td>
                    <td class="px-3 py-2">{{ $o['due_date'] ?? '—' }}</td>
                    <td class="px-3 py-2 text-right">{{ $fmtNum($o['remaining']) }}</td>
                    <td class="px-3 py-2"><x-ui.badge :tone="$riskTone[$o['due_risk']] ?? 'neutral'">{{ $o['due_risk'] ?? '—' }}</x-ui.badge></td>
                    <td class="px-3 py-2">
                        @forelse ($o['candidates'] as $c)
                            <div class="mb-1 text-xs">
                                <span class="font-semibold">{{ $c['name'] }}</span>
                                — util. proyeksi {{ $fmtPct($c['projected_utilization']) }},
                                sisa kapasitas {{ $fmtNum($c['capacity_gap']) }}
                            </div>
                        @empty
                            <span class="text-xs text-danger-700">
                                {{ implode(', ', $o['reason_codes']) ?: 'Tidak ada kandidat' }}
                            </span>
                        @endforelse
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-3 py-6 text-center text-ink-muted">Tidak ada order internal yang belum ditugaskan.</td></tr>
            @endforelse
            </tbody>
        </x-ui.table>
    </x-ui.card>
</x-settings-shell>
