@php
    /**
     * LAB-PROD-2 — Operational Analytics & KPI (presentation only).
     * Every figure is server-computed + scoped in LabOperationalAnalyticsService.
     * No query, no formula, no PII in this view.
     */
    $kpi = $data['kpi'];
    $scope = $data['scope'];
    $period = $data['period'];
    $dq = $data['data_quality'];

    $fmtPct = fn ($v) => $v === null ? 'N/A' : number_format($v, 1) . '%';
    $fmtNum = fn ($v) => $v === null ? '—' : number_format((float) $v, 0);
    $fmtDays = fn ($v) => $v === null ? '—' : number_format($v, 2) . ' hari';
    $fmtMinutes = function ($m) {
        if ($m === null) return '—';
        return $m >= 60 ? number_format($m / 60, 1) . ' jam' : number_format($m, 1) . ' mnt';
    };
    $trendMax = max(1, collect($data['throughput_trend'])->max('count') ?? 1);
    $exportUrl = route('lab-analytics.operational-kpi.export', request()->query());
@endphp

<x-settings-shell title="Analitik & KPI Operasional Lab">
    <x-ui.page-header
        title="Analitik &amp; KPI Operasional Lab"
        subtitle="KPI Operasional Lab dari data kanonik Lab Workflow V2 — periode {{ $period['label'] }} ({{ $period['from'] }} s/d {{ $period['to'] }}).">
        <x-slot:breadcrumb>Laboratorium / Analitik &amp; KPI Lab</x-slot:breadcrumb>
        <x-slot:actions>
            <x-ui.button :href="$exportUrl" variant="secondary" size="sm">Ekspor CSV</x-ui.button>
            @if (Route::has('lab-workflow-dashboard.index'))
                <x-ui.button :href="route('lab-workflow-dashboard.index')" variant="ghost" size="sm">Dasbor Operasional</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($scope['tier'] === 'own')
        <x-ui.alert variant="info" class="mb-4" data-scope="own">
            Anda melihat KPI operasional untuk data Anda sendiri sebagai teknisi
            (<span class="font-semibold">{{ $scope['technician_name'] }}</span>). Data teknisi lain tidak ditampilkan.
        </x-ui.alert>
    @endif

    {{-- Filter bar (GET) --}}
    <x-ui.filter-bar :action="route('lab-analytics.operational-kpi.index')">
        <x-ui.select name="period" label="Periode">
            @foreach (['today' => 'Hari Ini', '7d' => '7 Hari', 'month' => 'Bulan Ini', '30d' => '30 Hari', 'custom' => 'Rentang Kustom'] as $val => $lbl)
                <option value="{{ $val }}" @selected(($filters['period'] ?? 'month') === $val)>{{ $lbl }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.input type="date" name="from" label="Dari" :value="$filters['from']" />
        <x-ui.input type="date" name="to" label="Sampai" :value="$filters['to']" />
        @if ($scope['tier'] === 'full')
            <x-ui.select name="branch_id" label="Cabang">
                <option value="">Semua Cabang RME</option>
                @foreach ($scope['branch_options'] as $b)
                    <option value="{{ $b['id'] }}" @selected($scope['branch_id'] === $b['id'])>{{ $b['name'] }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select name="technician_id" label="Teknisi">
                <option value="">Semua Teknisi</option>
                @foreach ($scope['technician_options'] as $t)
                    <option value="{{ $t['id'] }}" @selected($scope['technician_id'] === $t['id'])>{{ $t['name'] }}</option>
                @endforeach
            </x-ui.select>
        @endif
        <x-ui.select name="lab_service_id" label="Layanan Lab">
            <option value="">Semua Layanan</option>
            @foreach ($scope['lab_service_options'] as $s)
                <option value="{{ $s['id'] }}" @selected(($filters['lab_service_id'] ?? null) === $s['id'])>{{ $s['name'] }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.select name="sourcing" label="Sumber Produksi">
            <option value="">Semua</option>
            <option value="internal" @selected(($filters['sourcing'] ?? null) === 'internal')>Internal</option>
            <option value="external" @selected(($filters['sourcing'] ?? null) === 'external')>Lab Eksternal</option>
        </x-ui.select>
    </x-ui.filter-bar>

    {{-- KPI summary cards --}}
    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3" data-block="kpi-summary">
        <x-ui.kpi-card label="Order Masuk (periode)" :value="$fmtNum($kpi['orders_received'])" />
        <x-ui.kpi-card label="WIP Terbuka" :value="$fmtNum($kpi['open_wip'])" />
        <x-ui.kpi-card
            label="Throughput Selesai"
            :value="$fmtNum($kpi['throughput'])"
            :delta="($kpi['throughput_delta'] >= 0 ? '+' : '') . $kpi['throughput_delta'] . ' vs periode lalu'"
            :trend="$kpi['throughput_delta'] > 0 ? 'up' : ($kpi['throughput_delta'] < 0 ? 'down' : 'flat')" />
        <x-ui.kpi-card label="Kepatuhan SLA" :value="$fmtPct($kpi['sla']['compliance_pct'])" />
        <x-ui.kpi-card label="First-Pass Yield QC" :value="$fmtPct($kpi['qc']['first_pass_yield_pct'])" />
        <x-ui.kpi-card label="Overdue Terbuka" :value="$fmtNum($kpi['open_overdue'])" />
    </div>

    @if ($dq['total'] === 0)
        <x-ui.empty-state
            class="mt-6"
            title="Belum ada data order V2 pada periode ini"
            description="KPI tidak diisi dengan angka palsu — tampil kosong sampai ada order Lab Workflow V2 pada rentang terpilih." />
    @endif

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- WIP per stage --}}
        <x-ui.card title="WIP per Tahap" data-block="wip-by-stage">
            <div class="space-y-3">
                @foreach ($data['wip_by_stage'] as $stage)
                    @php $max = max(1, collect($data['wip_by_stage'])->max('count')); @endphp
                    <div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-ink-soft">{{ $stage['label'] }}</span>
                            <span class="font-semibold text-navy">{{ $fmtNum($stage['count']) }}</span>
                        </div>
                        <div class="mt-1 h-2 w-full rounded-full bg-navy-50">
                            <div class="h-2 rounded-full bg-brand-500" style="width: {{ (int) round($stage['count'] / $max * 100) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        {{-- SLA performance --}}
        <x-ui.card title="Kinerja SLA (vs due date)" data-block="sla-performance">
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div><dt class="text-ink-muted">Kasus SLA Eligible</dt><dd class="text-lg font-semibold text-navy">{{ $fmtNum($kpi['sla']['eligible']) }}</dd></div>
                <div><dt class="text-ink-muted">Tepat Waktu</dt><dd class="text-lg font-semibold text-success-700">{{ $fmtNum($kpi['sla']['on_time']) }}</dd></div>
                <div><dt class="text-ink-muted">Terlambat</dt><dd class="text-lg font-semibold text-danger-700">{{ $fmtNum($kpi['sla']['late']) }}</dd></div>
                <div><dt class="text-ink-muted">Kepatuhan</dt><dd class="text-lg font-semibold text-navy">{{ $fmtPct($kpi['sla']['compliance_pct']) }}</dd></div>
                <div><dt class="text-ink-muted">Median Keterlambatan</dt><dd class="text-navy">{{ $fmtDays($kpi['sla']['median_lateness_days']) }}</dd></div>
                <div><dt class="text-ink-muted">Overdue Terbuka</dt><dd class="text-navy">{{ $fmtNum($kpi['sla']['open_overdue']) }}</dd></div>
            </dl>
            <p class="mt-3 text-xs text-ink-muted">
                Denominator = order selesai (terkirim) pada periode yang <span class="font-medium">memiliki due date</span>.
                {{ $fmtNum($dq['without_due_date']) }} order tanpa due date dikecualikan (lihat cakupan data).
            </p>
        </x-ui.card>
    </div>

    {{-- Throughput trend --}}
    <x-ui.card title="Tren Throughput (Selesai per Hari)" class="mt-6" data-block="throughput-trend">
        @if (collect($data['throughput_trend'])->sum('count') === 0)
            <p class="text-sm text-ink-muted">Belum ada order selesai pada periode ini.</p>
        @else
            <div class="flex items-end gap-1 overflow-x-auto" style="height: 140px">
                @foreach ($data['throughput_trend'] as $point)
                    <div class="flex min-w-[10px] flex-1 flex-col items-center justify-end" title="{{ $point['date'] }}: {{ $point['count'] }}">
                        <span class="text-[10px] text-ink-muted">{{ $point['count'] > 0 ? $point['count'] : '' }}</span>
                        <div class="w-full rounded-t bg-brand-500" style="height: {{ (int) round($point['count'] / $trendMax * 110) }}px; min-height: {{ $point['count'] > 0 ? 4 : 1 }}px"></div>
                    </div>
                @endforeach
            </div>
            <div class="mt-1 flex justify-between text-[10px] text-ink-muted">
                <span>{{ $period['from'] }}</span><span>{{ $period['to'] }}</span>
            </div>
        @endif
    </x-ui.card>

    {{-- Cycle time (full tier) --}}
    @if ($data['cycle_time'] !== null)
        <x-ui.card title="Cycle Time / Turnaround per Tahap" class="mt-6" data-block="cycle-time">
            <x-slot:description>{{ $data['cycle_time']['note'] }}</x-slot:description>
            <x-ui.table>
                <thead>
                    <tr><th class="px-4 py-2 text-left">Tahap</th><th class="px-4 py-2 text-right">Sampel</th><th class="px-4 py-2 text-right">Median</th><th class="px-4 py-2 text-right">Rata-rata</th><th class="px-4 py-2 text-right">Maks</th></tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($data['cycle_time']['stages'] as $stage)
                        <tr>
                            <td class="px-4 py-2">{{ $stage['label'] }}</td>
                            <td class="px-4 py-2 text-right">{{ $stage['count'] }}</td>
                            <td class="px-4 py-2 text-right">{{ $fmtMinutes($stage['median_minutes']) }}</td>
                            <td class="px-4 py-2 text-right">{{ $fmtMinutes($stage['avg_minutes']) }}</td>
                            <td class="px-4 py-2 text-right">{{ $fmtMinutes($stage['max_minutes']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        </x-ui.card>
    @endif

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- QC quality --}}
        <x-ui.card title="Kualitas QC" data-block="qc-quality">
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div><dt class="text-ink-muted">Order dgn Attempt QC</dt><dd class="text-lg font-semibold text-navy">{{ $fmtNum($kpi['qc']['attempts']) }}</dd></div>
                <div><dt class="text-ink-muted">First-Pass Yield</dt><dd class="text-lg font-semibold text-success-700">{{ $fmtPct($kpi['qc']['first_pass_yield_pct']) }}</dd></div>
                <div><dt class="text-ink-muted">Order Rework</dt><dd class="text-lg font-semibold text-danger-700">{{ $fmtNum($kpi['qc']['rework_orders']) }}</dd></div>
                <div><dt class="text-ink-muted">Rework Rate</dt><dd class="text-lg font-semibold text-navy">{{ $fmtPct($kpi['qc']['rework_rate_pct']) }}</dd></div>
            </dl>
        </x-ui.card>

        {{-- Internal vs external --}}
        <x-ui.card title="Internal vs Lab Eksternal" data-block="internal-external">
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div><dt class="text-ink-muted">Internal</dt><dd class="text-lg font-semibold text-brand-700">{{ $fmtNum($kpi['internal_vs_external']['internal']) }}</dd></div>
                <div><dt class="text-ink-muted">Lab Eksternal</dt><dd class="text-lg font-semibold text-info-700">{{ $fmtNum($kpi['internal_vs_external']['external']) }}</dd></div>
                <div><dt class="text-ink-muted">Total Keputusan</dt><dd class="text-navy">{{ $fmtNum($kpi['internal_vs_external']['total']) }}</dd></div>
                <div><dt class="text-ink-muted">Median Turnaround Eksternal</dt><dd class="text-navy">{{ $fmtDays($kpi['external_turnaround']['median_days']) }}</dd></div>
            </dl>
        </x-ui.card>
    </div>

    {{-- Technician operational --}}
    <x-ui.card title="KPI Operasional Teknisi" class="mt-6" data-block="technician-kpi">
        <x-slot:description>Ukuran operasional (bukan skor pegawai). Median & sampel ditampilkan agar dapat dinilai adil.</x-slot:description>
        @if ($data['technicians'] === [])
            <x-ui.empty-state title="Belum ada assignment teknisi pada periode ini" />
        @else
            <x-ui.table>
                <thead>
                    <tr><th class="px-4 py-2 text-left">Teknisi</th><th class="px-4 py-2 text-right">WIP Aktif</th><th class="px-4 py-2 text-right">Ditugaskan</th><th class="px-4 py-2 text-right">Selesai</th><th class="px-4 py-2 text-right">Median Waktu</th><th class="px-4 py-2 text-right">Sampel</th></tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($data['technicians'] as $t)
                        <tr>
                            <td class="px-4 py-2">{{ $t['name'] }}</td>
                            <td class="px-4 py-2 text-right">{{ $fmtNum($t['active_wip']) }}</td>
                            <td class="px-4 py-2 text-right">{{ $fmtNum($t['assigned']) }}</td>
                            <td class="px-4 py-2 text-right">{{ $fmtNum($t['completed']) }}</td>
                            <td class="px-4 py-2 text-right">{{ $fmtMinutes($t['median_minutes']) }}</td>
                            <td class="px-4 py-2 text-right">{{ $fmtNum($t['sample']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>

    {{-- Data quality coverage --}}
    <x-ui.card title="Cakupan Kualitas Data" class="mt-6" data-block="data-quality">
        <x-slot:description>Kesehatan sumber KPI — data tidak lengkap ditampilkan sebagai dikecualikan, bukan nol.</x-slot:description>
        <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-3 lg:grid-cols-6">
            <div><dt class="text-ink-muted">Total Order</dt><dd class="text-lg font-semibold text-navy">{{ $fmtNum($dq['total']) }}</dd></div>
            <div><dt class="text-ink-muted">Ada Due Date</dt><dd class="text-lg font-semibold text-success-700">{{ $fmtNum($dq['with_due_date']) }}</dd></div>
            <div><dt class="text-ink-muted">Tanpa Due Date</dt><dd class="text-lg font-semibold text-warning-700">{{ $fmtNum($dq['without_due_date']) }}</dd></div>
            <div><dt class="text-ink-muted">Cakupan Due Date</dt><dd class="text-navy">{{ $fmtPct($dq['due_coverage_pct']) }}</dd></div>
            <div><dt class="text-ink-muted">Terkirim (periode)</dt><dd class="text-navy">{{ $fmtNum($dq['delivered_in_period']) }}</dd></div>
            <div><dt class="text-ink-muted">Stuck (idle)</dt><dd class="text-danger-700">{{ $fmtNum($dq['stuck']) }}</dd></div>
        </dl>
    </x-ui.card>

    <p class="mt-4 text-xs text-ink-muted">{{ $data['note'] }} — dibuat {{ $data['generated_at']->format('d M Y H:i') }}.</p>
</x-settings-shell>
