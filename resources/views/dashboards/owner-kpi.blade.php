@php
    /**
     * Sprint 62.0 — Executive Owner KPI Dashboard block.
     * UIX-2 — Polished with the DaengtisiaMS Luxury Healthcare Design System
     * (x-ui.* components + semantic tokens). Presentation only; KPI logic and
     * data source are unchanged (Sprint 62.0 OwnerDashboardKpiService).
     * Privacy: never renders KTP/NIK, scanned documents, or raw medical notes.
     */
    $ownerKpi = $ownerKpi ?? [];
    $period = $ownerKpi['period'] ?? [];
    $metrics = $ownerKpi['metrics'] ?? [];
    $branchPerformance = $ownerKpi['branch_performance'] ?? [];
    $visitTrend = $ownerKpi['visit_trend'] ?? [];
    $paymentTrend = $ownerKpi['payment_trend'] ?? [];
    $topUnpaid = $ownerKpi['top_unpaid'] ?? [];
    $links = $ownerKpi['drilldowns'] ?? [];
    $moduleShortcuts = $ownerKpi['module_shortcuts'] ?? [];
    $selectedBranchId = $ownerKpi['selected_branch_id'] ?? null;
    $activeBranches = $ownerRmeLabActiveBranches ?? collect();
    $currentRange = $period['range'] ?? 'month';

    $rateDisplay = $metrics['collection_rate'] === null
        ? 'Belum ada data'
        : number_format((float) $metrics['collection_rate'], 1, ',', '.').'%';

    $stockValueDisplay = ($metrics['stock_available'] ?? false)
        ? format_currency_id($metrics['stock_value'] ?? 0)
        : 'Belum tersedia';
    $lowStockDisplay = ($metrics['stock_available'] ?? false)
        ? format_number_id($metrics['low_stock_items'] ?? 0)
        : 'Belum tersedia';

    // `accent` = gold premium rail — reserved for the revenue KPI only (governance).
    $kpiCards = [
        ['key' => 'total_visits', 'label' => 'Total Kunjungan', 'value' => format_number_id($metrics['total_visits'] ?? 0)],
        ['key' => 'new_patients', 'label' => 'Pasien Baru', 'value' => format_number_id($metrics['new_patients'] ?? 0)],
        ['key' => 'total_revenue', 'label' => 'Total Pendapatan', 'value' => format_currency_id($metrics['total_revenue'] ?? 0), 'accent' => true],
        ['key' => 'active_receivable', 'label' => 'Piutang Aktif', 'value' => format_currency_id($metrics['active_receivable'] ?? 0)],
        ['key' => 'unpaid_invoices', 'label' => 'Invoice Belum Lunas', 'value' => format_number_id($metrics['unpaid_invoices'] ?? 0)],
        ['key' => 'follow_up_due', 'label' => 'Follow-up Jatuh Tempo', 'value' => format_number_id($metrics['follow_up_due'] ?? 0)],
        ['key' => 'lab_orders_active', 'label' => 'Lab Order Aktif', 'value' => format_number_id($metrics['lab_orders_active'] ?? 0)],
        ['key' => 'low_stock_items', 'label' => 'Low Stock', 'value' => $lowStockDisplay],
        ['key' => 'stock_value', 'label' => 'Nilai Stok', 'value' => $stockValueDisplay],
        ['key' => 'collection_rate', 'label' => 'Tingkat Penagihan', 'value' => $rateDisplay],
    ];

    $hasAnyData = ($metrics['total_visits'] ?? 0) > 0
        || ($metrics['new_patients'] ?? 0) > 0
        || ($metrics['total_revenue'] ?? 0) > 0
        || ($metrics['active_receivable'] ?? 0) > 0;

    $visitTrendMax = collect($visitTrend)->max('count') ?: 1;
    $paymentTrendMax = collect($paymentTrend)->max('count') ?: 1;

    $alerts = collect([
        ['label' => 'Piutang aktif', 'count' => ($metrics['active_receivable'] ?? 0) > 0 ? 1 : 0, 'text' => format_currency_id($metrics['active_receivable'] ?? 0).' belum tertagih.', 'severity' => 'warning'],
        ['label' => 'Follow-up jatuh tempo', 'count' => $metrics['follow_up_due'] ?? 0, 'text' => format_number_id($metrics['follow_up_due'] ?? 0).' piutang perlu ditindaklanjuti.', 'severity' => 'warning'],
        ['label' => 'Low stock', 'count' => ($metrics['stock_available'] ?? false) ? ($metrics['low_stock_items'] ?? 0) : 0, 'text' => $lowStockDisplay.' item di bawah titik pemesanan ulang.', 'severity' => 'warning'],
        ['label' => 'Lab order aktif', 'count' => $metrics['lab_orders_active'] ?? 0, 'text' => format_number_id($metrics['lab_orders_active'] ?? 0).' lab order belum selesai.', 'severity' => 'info'],
    ])->filter(fn ($a) => $a['count'] > 0)->values();

    $alertToneClasses = [
        'warning' => ['wrap' => 'border-warning-100 bg-warning-50', 'chip' => 'bg-warning text-white'],
        'info' => ['wrap' => 'border-info-100 bg-info-50', 'chip' => 'bg-info text-white'],
    ];

    $receivableTone = fn (?string $status): string => match (strtoupper((string) $status)) {
        'PARTIAL' => 'info',
        'UNPAID' => 'warning',
        default => 'neutral',
    };

    $rangeButtons = [
        'today' => 'Hari ini',
        '7d' => '7 hari',
        'month' => 'Bulan ini',
        '30d' => '30 hari',
    ];
@endphp

<section aria-labelledby="owner-kpi-heading" class="space-y-6">
    <x-ui.card>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0 flex-1">
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">Dashboard KPI Owner</p>
                <h3 id="owner-kpi-heading" class="mt-1 text-lg font-semibold text-navy">Kesehatan klinik &amp; performa bisnis</h3>
                <p class="mt-2 max-w-3xl text-sm text-ink-soft">
                    Ringkasan eksekutif lintas cabang. Periode: <span class="font-semibold text-navy">{{ $period['label'] ?? 'Bulan ini' }}</span>.
                    Data agregat read-only; tidak menampilkan KTP/NIK, dokumen scan, atau catatan medis mentah.
                </p>
            </div>
        </div>

        <form method="GET" action="{{ route('dashboard') }}" class="mt-4 flex flex-wrap items-end gap-3">
            <div class="flex flex-wrap gap-2">
                @foreach ($rangeButtons as $value => $label)
                    <a href="{{ route('dashboard', array_filter(['range' => $value, 'branch_id' => $selectedBranchId])) }}"
                       @class([
                           'rounded-lg border px-3 py-2 text-sm font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2',
                           'border-brand-600 bg-brand-600 text-white' => $currentRange === $value,
                           'border-hairline bg-surface text-ink-soft hover:bg-navy-50' => $currentRange !== $value,
                       ])>
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            <x-ui.select label="Cabang" name="branch_id" id="owner-kpi-branch" onchange="this.form.submit()" class="min-w-[12rem]">
                <option value="">Semua cabang</option>
                @foreach ($activeBranches as $branch)
                    <option value="{{ $branch->id }}" @selected($selectedBranchId === $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </x-ui.select>

            <input type="hidden" name="range" value="custom">
            <x-ui.input type="date" label="Dari" name="date_from" id="owner-kpi-from" :value="optional($period['from'] ?? null)->toDateString()" />
            <x-ui.input type="date" label="Sampai" name="date_to" id="owner-kpi-to" :value="optional($period['to'] ?? null)->toDateString()" />
            <x-ui.button type="submit" variant="primary">Terapkan rentang</x-ui.button>
        </form>

        {{-- FIX-PRE-68-45 Scope B — module/report-category shortcuts. Each shortcut
             is server-side permission-gated (OwnerDashboardKpiService::moduleShortcuts);
             a user only sees reports they may already open, and the target report's
             own branch scope still applies (no cross-branch leak from here). --}}
        @if (count($moduleShortcuts))
            <div class="mt-4 border-t border-hairline pt-4">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-soft">Pintasan Laporan Modul</p>
                <div class="flex flex-wrap gap-2">
                    @foreach ($moduleShortcuts as $shortcut)
                        <a href="{{ $shortcut['href'] }}"
                           class="group flex flex-col rounded-lg border border-hairline bg-surface px-3 py-2 transition-colors hover:border-brand-500 hover:bg-brand-50 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                            <span class="text-sm font-semibold text-navy group-hover:text-brand-700">{{ $shortcut['label'] }}</span>
                            <span class="text-xs text-ink-soft">{{ $shortcut['description'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </x-ui.card>

    @unless ($hasAnyData)
        <x-ui.empty-state title="Belum ada data pada periode ini." description="Ubah rentang periode atau cabang untuk melihat ringkasan KPI." />
    @endunless

    {{-- KPI cards --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($kpiCards as $card)
            @php $href = $links[$card['key']] ?? null; @endphp
            @if ($href)
                <a href="{{ $href }}" class="group block rounded-xl focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                    <x-ui.kpi-card
                        :label="$card['label']"
                        :value="$card['value']"
                        :accent="$card['accent'] ?? false"
                        class="h-full transition-shadow group-hover:shadow-md group-hover:ring-1 group-hover:ring-brand-100"
                    />
                </a>
            @else
                <x-ui.kpi-card :label="$card['label']" :value="$card['value']" :accent="$card['accent'] ?? false" class="h-full" />
            @endif
        @endforeach
    </div>

    {{-- Trends --}}
    {{-- FIX-PRE-68-45 Scope B — activated visit/payment trend charts. Real
         aggregated data comes from OwnerDashboardKpiService::dailyVisitTrend /
         dailyPaymentTrend (branch-scoped). Rendered server-side with the app's
         Tailwind stack (no React/Vue, no charting dependency): peak + total
         captions, per-bar hover value labels, and a first/last date axis. --}}
    @php
        $visitTrendTotal = collect($visitTrend)->sum('count');
        $paymentTrendTotal = collect($paymentTrend)->sum('count');
        $visitFirstLabel = collect($visitTrend)->first()['label'] ?? '';
        $visitLastLabel = collect($visitTrend)->last()['label'] ?? '';
        $paymentFirstLabel = collect($paymentTrend)->first()['label'] ?? '';
        $paymentLastLabel = collect($paymentTrend)->last()['label'] ?? '';
    @endphp
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <x-ui.card title="Tren Kunjungan">
            @if (count($visitTrend))
                <p class="mb-2 text-xs text-ink-soft">
                    Puncak harian <span class="font-semibold text-navy">{{ format_number_id($visitTrendMax) }}</span>
                    &middot; Total periode <span class="font-semibold text-navy">{{ format_number_id($visitTrendTotal) }}</span> kunjungan
                </p>
                <div class="flex items-end gap-1" style="height: 140px;">
                    @foreach ($visitTrend as $point)
                        <div class="group relative flex flex-1 flex-col items-center justify-end" title="{{ $point['label'] }}: {{ format_number_id($point['count']) }}">
                            <span class="pointer-events-none absolute -top-5 z-10 hidden whitespace-nowrap rounded bg-navy px-1.5 py-0.5 text-[10px] font-semibold text-white group-hover:block">{{ format_number_id($point['count']) }}</span>
                            <div class="w-full rounded-t bg-brand-500 transition-colors group-hover:bg-brand-600" style="height: {{ max(2, (int) round(($point['count'] / $visitTrendMax) * 100)) }}%"></div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-1 flex justify-between text-[10px] text-ink-muted">
                    <span>{{ $visitFirstLabel }}</span>
                    <span>{{ $visitLastLabel }}</span>
                </div>
            @else
                <p class="mt-2 text-sm text-ink-soft">Belum ada data pada periode ini.</p>
            @endif
        </x-ui.card>

        <x-ui.card title="Tren Pembayaran">
            @if (count($paymentTrend))
                <p class="mb-2 text-xs text-ink-soft">
                    Puncak harian <span class="font-semibold text-navy">{{ format_currency_id($paymentTrendMax) }}</span>
                    &middot; Total periode <span class="font-semibold text-navy">{{ format_currency_id($paymentTrendTotal) }}</span>
                </p>
                <div class="flex items-end gap-1" style="height: 140px;">
                    @foreach ($paymentTrend as $point)
                        <div class="group relative flex flex-1 flex-col items-center justify-end" title="{{ $point['label'] }}: {{ format_currency_id($point['count']) }}">
                            <span class="pointer-events-none absolute -top-5 z-10 hidden whitespace-nowrap rounded bg-navy px-1.5 py-0.5 text-[10px] font-semibold text-white group-hover:block">{{ format_currency_id($point['count']) }}</span>
                            <div class="w-full rounded-t bg-success transition-colors group-hover:opacity-80" style="height: {{ max(2, (int) round(($point['count'] / $paymentTrendMax) * 100)) }}%"></div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-1 flex justify-between text-[10px] text-ink-muted">
                    <span>{{ $paymentFirstLabel }}</span>
                    <span>{{ $paymentLastLabel }}</span>
                </div>
            @else
                <p class="mt-2 text-sm text-ink-soft">Belum ada data pada periode ini.</p>
            @endif
        </x-ui.card>
    </div>

    {{-- Branch performance --}}
    <x-ui.card title="Performa Cabang">
        <x-ui.table>
            <thead class="bg-navy-50">
                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">
                    <th class="px-3 py-2">Cabang</th>
                    <th class="px-3 py-2 text-right">Kunjungan</th>
                    <th class="px-3 py-2 text-right">Pasien Baru</th>
                    <th class="px-3 py-2 text-right">Pendapatan</th>
                    <th class="px-3 py-2 text-right">Piutang</th>
                    <th class="px-3 py-2 text-right">Follow-up Due</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($branchPerformance as $row)
                    <tr class="hover:bg-navy-50">
                        <td class="px-3 py-2 font-medium text-navy">{{ $row['branch_name'] }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-ink">{{ format_number_id($row['visits']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-ink">{{ format_number_id($row['new_patients']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums font-medium text-navy">{{ format_currency_id($row['revenue']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-ink">{{ format_currency_id($row['receivable']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-ink">{{ format_number_id($row['follow_up_due']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-3 py-4 text-center text-ink-soft">Belum ada data pada periode ini.</td></tr>
                @endforelse
            </tbody>
        </x-ui.table>
    </x-ui.card>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        {{-- Latest receivables (privacy-safe: no KTP/NIK) --}}
        <x-ui.card title="Piutang Terbaru">
            <x-ui.table>
                <thead class="bg-navy-50">
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">
                        <th class="px-3 py-2">Pasien</th>
                        <th class="px-3 py-2">Cabang</th>
                        <th class="px-3 py-2">Tgl Kunjungan</th>
                        <th class="px-3 py-2 text-right">Sisa</th>
                        <th class="px-3 py-2">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($topUnpaid as $row)
                        <tr class="hover:bg-navy-50">
                            <td class="px-3 py-2 font-medium text-navy">{{ $row['patient_name'] }}</td>
                            <td class="px-3 py-2 text-ink">{{ $row['branch_name'] }}</td>
                            <td class="px-3 py-2 text-ink">{{ optional($row['visit_date'])->translatedFormat('d M Y') ?? '-' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums font-medium text-navy">{{ format_currency_id($row['remaining']) }}</td>
                            <td class="px-3 py-2"><x-ui.badge :tone="$receivableTone($row['status'])">{{ $row['status'] }}</x-ui.badge></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-4 text-center text-ink-soft">Belum ada data pada periode ini.</td></tr>
                    @endforelse
                </tbody>
            </x-ui.table>
        </x-ui.card>

        {{-- Operational alerts --}}
        <x-ui.card title="Peringatan Operasional">
            <ul class="space-y-3">
                @forelse ($alerts as $alert)
                    @php $tone = $alertToneClasses[$alert['severity']] ?? $alertToneClasses['info']; @endphp
                    <li class="flex items-start gap-3 rounded-lg border p-3 {{ $tone['wrap'] }}">
                        <span class="mt-0.5 inline-flex h-6 min-w-6 items-center justify-center rounded-full px-2 text-xs font-semibold {{ $tone['chip'] }}">
                            {{ format_number_id($alert['count']) }}
                        </span>
                        <div>
                            <p class="text-sm font-semibold text-navy">{{ $alert['label'] }}</p>
                            <p class="text-xs text-ink-soft">{{ $alert['text'] }}</p>
                        </div>
                    </li>
                @empty
                    <li class="rounded-lg border border-success-100 bg-success-50 p-3 text-sm text-success-700">
                        Tidak ada peringatan operasional pada periode ini.
                    </li>
                @endforelse
            </ul>
        </x-ui.card>
    </div>
</section>
