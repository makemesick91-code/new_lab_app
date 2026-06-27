@php
    /**
     * Sprint 62.0 — Executive Owner KPI Dashboard block.
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

    $kpiCards = [
        ['key' => 'total_visits', 'label' => 'Total Kunjungan', 'value' => format_number_id($metrics['total_visits'] ?? 0)],
        ['key' => 'new_patients', 'label' => 'Pasien Baru', 'value' => format_number_id($metrics['new_patients'] ?? 0)],
        ['key' => 'total_revenue', 'label' => 'Total Pendapatan', 'value' => format_currency_id($metrics['total_revenue'] ?? 0)],
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

    $rangeButtons = [
        'today' => 'Hari ini',
        '7d' => '7 hari',
        'month' => 'Bulan ini',
        '30d' => '30 hari',
    ];
@endphp

<section aria-labelledby="owner-kpi-heading" class="space-y-6">
    <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0 flex-1">
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Dashboard KPI Owner</p>
                <h3 id="owner-kpi-heading" class="mt-1 text-lg font-semibold text-gray-900">Kesehatan klinik &amp; performa bisnis</h3>
                <p class="mt-2 max-w-3xl text-sm text-gray-600">
                    Ringkasan eksekutif lintas cabang. Periode: <span class="font-semibold">{{ $period['label'] ?? 'Bulan ini' }}</span>.
                    Data agregat read-only; tidak menampilkan KTP/NIK, dokumen scan, atau catatan medis mentah.
                </p>
            </div>
        </div>

        <form method="GET" action="{{ route('dashboard') }}" class="mt-4 flex flex-wrap items-end gap-3">
            <div class="flex flex-wrap gap-2">
                @foreach ($rangeButtons as $value => $label)
                    <a href="{{ route('dashboard', array_filter(['range' => $value, 'branch_id' => $selectedBranchId])) }}"
                       class="rounded-md border px-3 py-2 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2 {{ $currentRange === $value ? 'border-teal-600 bg-teal-600 text-white' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            <div>
                <label for="owner-kpi-branch" class="block text-xs font-semibold uppercase tracking-wide text-gray-500">Cabang</label>
                <select id="owner-kpi-branch" name="branch_id" onchange="this.form.submit()"
                        class="mt-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">
                    <option value="">Semua cabang</option>
                    @foreach ($activeBranches as $branch)
                        <option value="{{ $branch->id }}" @selected($selectedBranchId === $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>

            <input type="hidden" name="range" value="custom">
            <div>
                <label for="owner-kpi-from" class="block text-xs font-semibold uppercase tracking-wide text-gray-500">Dari</label>
                <input type="date" id="owner-kpi-from" name="date_from" value="{{ optional($period['from'] ?? null)->toDateString() }}"
                       class="mt-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">
            </div>
            <div>
                <label for="owner-kpi-to" class="block text-xs font-semibold uppercase tracking-wide text-gray-500">Sampai</label>
                <input type="date" id="owner-kpi-to" name="date_to" value="{{ optional($period['to'] ?? null)->toDateString() }}"
                       class="mt-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">
            </div>
            <button type="submit" class="rounded-md bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-900 focus:ring-offset-2">
                Terapkan rentang
            </button>
        </form>
    </div>

    @unless ($hasAnyData)
        <div class="rounded-lg border border-dashed border-gray-300 bg-white p-6 text-center text-sm text-gray-500">
            Belum ada data pada periode ini.
        </div>
    @endunless

    {{-- KPI cards --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($kpiCards as $card)
            @php $href = $links[$card['key']] ?? null; @endphp
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <div class="flex items-start justify-between gap-2">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $card['label'] }}</p>
                    @if ($href)
                        <a href="{{ $href }}" class="text-xs font-medium text-teal-700 hover:text-teal-900">Lihat</a>
                    @endif
                </div>
                <p class="mt-2 text-2xl font-semibold text-gray-900">{{ $card['value'] }}</p>
            </div>
        @endforeach
    </div>

    {{-- Trends --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <h4 class="text-base font-semibold text-gray-900">Tren Kunjungan</h4>
            @if (count($visitTrend))
                <div class="mt-4 flex items-end gap-1" style="height: 120px;">
                    @foreach ($visitTrend as $point)
                        <div class="flex flex-1 flex-col items-center justify-end" title="{{ $point['label'] }}: {{ format_number_id($point['count']) }}">
                            <div class="w-full rounded-t bg-teal-500" style="height: {{ max(2, (int) round(($point['count'] / $visitTrendMax) * 100)) }}%"></div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="mt-4 text-sm text-gray-500">Belum ada data pada periode ini.</p>
            @endif
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <h4 class="text-base font-semibold text-gray-900">Tren Pembayaran</h4>
            @if (count($paymentTrend))
                <div class="mt-4 flex items-end gap-1" style="height: 120px;">
                    @foreach ($paymentTrend as $point)
                        <div class="flex flex-1 flex-col items-center justify-end" title="{{ $point['label'] }}: {{ format_currency_id($point['count']) }}">
                            <div class="w-full rounded-t bg-emerald-500" style="height: {{ max(2, (int) round(($point['count'] / $paymentTrendMax) * 100)) }}%"></div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="mt-4 text-sm text-gray-500">Belum ada data pada periode ini.</p>
            @endif
        </div>
    </div>

    {{-- Branch performance --}}
    <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
        <h4 class="text-base font-semibold text-gray-900">Performa Cabang</h4>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
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
                        <tr>
                            <td class="px-3 py-2 font-medium text-gray-900">{{ $row['branch_name'] }}</td>
                            <td class="px-3 py-2 text-right text-gray-700">{{ format_number_id($row['visits']) }}</td>
                            <td class="px-3 py-2 text-right text-gray-700">{{ format_number_id($row['new_patients']) }}</td>
                            <td class="px-3 py-2 text-right text-gray-700">{{ format_currency_id($row['revenue']) }}</td>
                            <td class="px-3 py-2 text-right text-gray-700">{{ format_currency_id($row['receivable']) }}</td>
                            <td class="px-3 py-2 text-right text-gray-700">{{ format_number_id($row['follow_up_due']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-4 text-center text-gray-500">Belum ada data pada periode ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        {{-- Latest receivables (privacy-safe: no KTP/NIK) --}}
        <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <h4 class="text-base font-semibold text-gray-900">Piutang Terbaru</h4>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <th class="px-3 py-2">Pasien</th>
                            <th class="px-3 py-2">Cabang</th>
                            <th class="px-3 py-2">Tgl Kunjungan</th>
                            <th class="px-3 py-2 text-right">Sisa</th>
                            <th class="px-3 py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($topUnpaid as $row)
                            <tr>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $row['patient_name'] }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $row['branch_name'] }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ optional($row['visit_date'])->translatedFormat('d M Y') ?? '-' }}</td>
                                <td class="px-3 py-2 text-right text-gray-900">{{ format_currency_id($row['remaining']) }}</td>
                                <td class="px-3 py-2 text-gray-700">{{ $row['status'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-4 text-center text-gray-500">Belum ada data pada periode ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Operational alerts --}}
        <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <h4 class="text-base font-semibold text-gray-900">Peringatan Operasional</h4>
            <ul class="mt-4 space-y-3">
                @forelse ($alerts as $alert)
                    <li class="flex items-start gap-3 rounded-md border p-3 {{ $alert['severity'] === 'warning' ? 'border-amber-200 bg-amber-50' : 'border-sky-200 bg-sky-50' }}">
                        <span class="mt-0.5 inline-flex h-6 min-w-6 items-center justify-center rounded-full px-2 text-xs font-semibold {{ $alert['severity'] === 'warning' ? 'bg-amber-500 text-white' : 'bg-sky-500 text-white' }}">
                            {{ format_number_id($alert['count']) }}
                        </span>
                        <div>
                            <p class="text-sm font-semibold text-gray-900">{{ $alert['label'] }}</p>
                            <p class="text-xs text-gray-600">{{ $alert['text'] }}</p>
                        </div>
                    </li>
                @empty
                    <li class="rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
                        Tidak ada peringatan operasional pada periode ini.
                    </li>
                @endforelse
            </ul>
        </div>
    </div>
</section>
