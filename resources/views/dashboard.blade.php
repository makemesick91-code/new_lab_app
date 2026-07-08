@php
    $user = auth()->user();
    $canAny = fn (array $permissions): bool => $user ? $user->canAny($permissions) : false;
    $branchOperationalPermissions = [
        'view_lab_orders',
        'manage_lab_orders',
        'view_production',
        'manage_production',
        'view_quality_control',
        'manage_quality_control',
        'view_delivery',
        'manage_delivery',
        'view_inventory',
        'manage_inventory',
        'view_invoice',
        'manage_invoice',
    ];
    $showBranchAdminDashboard = $canAny($branchOperationalPermissions);
    $showOwnerDashboard = ! $showBranchAdminDashboard
        && ($user?->can('view_owner_dashboard') || $user?->can('manage_report'));

    $ownerKpis = $ownerKpis ?? [
        [
            'label' => 'Pendapatan Bulan Ini',
            'value' => format_currency_id(0),
            'secondary' => 'Belum ada data pendapatan yang terhubung di halaman ini.',
            'severity' => 'neutral',
            'href' => $canAny(['view_invoice_report', 'manage_report']) ? route('reports.revenue') : null,
        ],
        [
            'label' => 'Order Aktif',
            'value' => '0',
            'secondary' => 'Buka Order Lab atau laporan untuk jumlah terbaru.',
            'severity' => 'neutral',
            'href' => $canAny(['view_lab_orders', 'manage_lab_orders']) ? route('lab-orders.index') : null,
        ],
        [
            'label' => 'Order Terlambat',
            'value' => '0',
            'secondary' => 'Belum ada data keterlambatan untuk tampilan ini.',
            'severity' => 'success',
            'href' => $canAny(['view_order_report', 'manage_report']) ? route('reports.orders') : null,
        ],
        [
            'label' => 'Invoice Tertunggak',
            'value' => format_currency_id(0),
            'secondary' => 'Gunakan laporan invoice untuk detail umur piutang.',
            'severity' => 'neutral',
            'href' => $canAny(['view_invoice_report', 'manage_report']) ? route('reports.outstanding') : null,
        ],
        [
            'label' => 'Nilai Persediaan',
            'value' => format_currency_id(0),
            'secondary' => 'Nilai berbasis ledger dari Persediaan Inti.',
            'severity' => 'neutral',
            'href' => $canAny(['view_inventory', 'manage_inventory', 'manage master data']) ? route('inventory.dashboard') : null,
        ],
        [
            'label' => 'Jumlah Stok Menipis',
            'value' => '0',
            'secondary' => 'Peringatan stok menipis tampil saat data tersedia.',
            'severity' => 'success',
            'href' => $canAny(['view_inventory', 'manage_inventory', 'manage master data']) ? route('inventory.stock.index') : null,
        ],
    ];

    $pipelineStages = $pipelineStages ?? [
        [
            'label' => 'Diterima',
            'count' => 0,
            'percent' => 0,
            'oldestAge' => 'Belum ada data umur',
            'severity' => 'neutral',
            'href' => $canAny(['view_lab_orders', 'manage_lab_orders']) ? route('lab-orders.index') : null,
        ],
        [
            'label' => 'Dalam Produksi',
            'count' => 0,
            'percent' => 0,
            'oldestAge' => 'Belum ada data umur',
            'severity' => 'neutral',
            'href' => $canAny(['view_production', 'manage_production']) ? route('production.board') : null,
        ],
        [
            'label' => 'Menunggu QC',
            'count' => 0,
            'percent' => 0,
            'oldestAge' => 'Belum ada data umur',
            'severity' => 'neutral',
            'href' => $canAny(['view_quality_control', 'manage_quality_control']) ? route('quality-control.queue') : null,
        ],
        [
            'label' => 'QC Gagal',
            'count' => 0,
            'percent' => 0,
            'oldestAge' => 'Belum ada data umur',
            'severity' => 'neutral',
            'href' => $canAny(['view_qc_report', 'manage_report']) ? route('reports.qc') : null,
        ],
        [
            'label' => 'Siap Pengiriman',
            'count' => 0,
            'percent' => 0,
            'oldestAge' => 'Belum ada data umur',
            'severity' => 'neutral',
            'href' => $canAny(['view_delivery', 'manage_delivery']) ? route('deliveries.index') : null,
        ],
        [
            'label' => 'Terkirim',
            'count' => 0,
            'percent' => 0,
            'oldestAge' => 'Belum ada data umur',
            'severity' => 'neutral',
            'href' => $canAny(['view_delivery_report', 'manage_report']) ? route('reports.delivery') : null,
        ],
        [
            'label' => 'Selesai',
            'count' => 0,
            'percent' => 0,
            'oldestAge' => 'Belum ada data umur',
            'severity' => 'neutral',
            'href' => $canAny(['view_order_report', 'manage_report']) ? route('reports.orders') : null,
        ],
    ];

    $ownerAlerts = $ownerAlerts ?? [];
    $branchPerformance = $branchPerformance ?? [];
    $recentActivity = $recentActivity ?? [];

    $branchSummaryCards = $branchSummaryCards ?? [
        [
            'label' => 'Masuk Hari Ini',
            'value' => '0',
            'context' => 'Belum ada data order masuk.',
            'severity' => 'neutral',
            'href' => $canAny(['view_lab_orders', 'manage_lab_orders']) ? route('lab-orders.index') : null,
        ],
        [
            'label' => 'Perlu Penugasan',
            'value' => '0',
            'context' => 'Semua pekerjaan terlihat sudah ditugaskan.',
            'severity' => 'success',
            'href' => $canAny(['view_production', 'manage_production']) ? route('production.board') : null,
        ],
        [
            'label' => 'Tertahan / Terlambat',
            'value' => '0',
            'context' => 'Belum ada pekerjaan tertahan.',
            'severity' => 'success',
            'href' => $canAny(['view_lab_orders', 'manage_lab_orders']) ? route('lab-orders.index') : null,
        ],
        [
            'label' => 'Perlu QC',
            'value' => '0',
            'context' => 'Antrean QC kosong.',
            'severity' => 'success',
            'href' => $canAny(['view_quality_control', 'manage_quality_control']) ? route('quality-control.queue') : null,
        ],
        [
            'label' => 'Siap Pengiriman',
            'value' => '0',
            'context' => 'Belum ada data siap kirim.',
            'severity' => 'neutral',
            'href' => $canAny(['view_delivery', 'manage_delivery']) ? route('deliveries.index') : null,
        ],
        [
            'label' => 'Stok Menipis',
            'value' => '0',
            'context' => 'Belum ada peringatan stok menipis.',
            'severity' => 'success',
            'href' => $canAny(['view_inventory', 'manage_inventory', 'manage master data']) ? route('inventory.stock.index') : null,
        ],
        [
            'label' => 'Invoice Belum Dibayar',
            'value' => '0',
            'context' => 'Belum ada data invoice belum dibayar.',
            'severity' => 'neutral',
            'href' => $canAny(['view_invoice', 'manage_invoice']) ? route('invoices.index') : null,
        ],
    ];

    $branchQueues = $branchQueues ?? [
        [
            'title' => 'Masuk Hari Ini',
            'items' => [],
            'empty' => 'Belum ada order baru hari ini.',
        ],
        [
            'title' => 'Perlu Penugasan',
            'items' => [],
            'empty' => 'Semua order baru sudah ditugaskan.',
        ],
        [
            'title' => 'Perlu QC',
            'items' => [],
            'empty' => 'Antrean QC kosong.',
        ],
        [
            'title' => 'Siap Pengiriman',
            'items' => [],
            'empty' => 'Belum ada order menunggu pengiriman.',
        ],
        [
            'title' => 'Tindak Lanjut Keuangan',
            'items' => [],
            'empty' => 'Belum ada invoice belum dibayar yang perlu ditindaklanjuti.',
        ],
    ];
    $branchAlerts = $branchAlerts ?? [];
    $productionWorkload = $productionWorkload ?? [];
    $qcWorkload = $qcWorkload ?? [];
    $deliveryWorkload = $deliveryWorkload ?? [];
    $inventoryAlerts = $inventoryAlerts ?? [];
    $financeAlerts = $financeAlerts ?? [];
    $ownerRmeLabPilot = $ownerRmeLabPilot ?? null;
    $ownerRmeLabBranchSummary = $ownerRmeLabBranchSummary ?? [];
    $ownerRmeLabBranchReceivableSummary = $ownerRmeLabBranchReceivableSummary ?? [];
    $ownerRmeLabActiveBranches = $ownerRmeLabActiveBranches ?? collect();
    $ownerRmeLabSelectedBranchId = $ownerRmeLabSelectedBranchId ?? null;
    $ownerRmeLabDrilldowns = $ownerRmeLabDrilldowns ?? [];
    $ownerRmeLabKpiCards = [];
    $ownerRmeLabFunnel = [];
    $ownerRmeLabAttention = [];

    if (is_array($ownerRmeLabPilot)) {
        $ownerRmeLabKpiCards = [
            [
                'label' => 'Kunjungan RME Hari Ini',
                'value' => format_number_id($ownerRmeLabPilot['visits_today'] ?? 0),
                'secondary' => 'Kunjungan terdaftar hari ini (tidak termasuk dibatalkan).',
                'severity' => ($ownerRmeLabPilot['visits_today'] ?? 0) > 0 ? 'info' : 'neutral',
                'href' => $ownerRmeLabDrilldowns['visits_today'] ?? null,
            ],
            [
                'label' => 'RM Draft',
                'value' => format_number_id($ownerRmeLabPilot['medical_records_draft'] ?? 0),
                'secondary' => 'Rekam medis belum difinalisasi.',
                'severity' => ($ownerRmeLabPilot['medical_records_draft'] ?? 0) > 0 ? 'warning' : 'success',
                'href' => $ownerRmeLabDrilldowns['medical_records_draft'] ?? null,
            ],
            [
                'label' => 'RM Final Hari Ini',
                'value' => format_number_id($ownerRmeLabPilot['medical_records_final_today'] ?? 0),
                'secondary' => 'RM difinalisasi hari ini.',
                'severity' => ($ownerRmeLabPilot['medical_records_final_today'] ?? 0) > 0 ? 'success' : 'neutral',
                'href' => $ownerRmeLabDrilldowns['medical_records_final_today'] ?? null,
            ],
            [
                'label' => 'Menunggu Kasir',
                'value' => format_number_id($ownerRmeLabPilot['visits_cashier_pending'] ?? 0),
                'secondary' => 'Kunjungan status cashier_pending.',
                'severity' => ($ownerRmeLabPilot['visits_cashier_pending'] ?? 0) > 0 ? 'warning' : 'success',
                'href' => $ownerRmeLabDrilldowns['visits_cashier_pending'] ?? null,
            ],
            [
                'label' => 'Invoice RME Belum Dibayar',
                'value' => format_number_id($ownerRmeLabPilot['rme_invoices_unpaid'] ?? 0),
                'secondary' => 'Invoice DRAFT/UNPAID saat ini.',
                'severity' => ($ownerRmeLabPilot['rme_invoices_unpaid'] ?? 0) > 0 ? 'warning' : 'success',
                'href' => $ownerRmeLabDrilldowns['rme_invoices_unpaid'] ?? null,
            ],
            [
                'label' => 'Pembayaran RME Hari Ini',
                'value' => format_number_id($ownerRmeLabPilot['rme_invoices_paid_today'] ?? 0),
                'secondary' => format_currency_id($ownerRmeLabPilot['rme_revenue_paid_today'] ?? 0).' pendapatan dibayar hari ini.',
                'severity' => ($ownerRmeLabPilot['rme_invoices_paid_today'] ?? 0) > 0 ? 'success' : 'neutral',
                'href' => $ownerRmeLabDrilldowns['rme_invoices_paid_today'] ?? null,
            ],
            [
                'label' => 'Kandidat Lab RME Pending',
                'value' => format_number_id($ownerRmeLabPilot['lab_candidates_pending'] ?? 0),
                'secondary' => 'Menunggu review Admin Lab.',
                'severity' => ($ownerRmeLabPilot['lab_candidates_pending'] ?? 0) > 0 ? 'warning' : 'success',
                'href' => $ownerRmeLabDrilldowns['lab_candidates_pending'] ?? null,
            ],
            [
                'label' => 'Kandidat Lab Dikonversi',
                'value' => format_number_id($ownerRmeLabPilot['lab_candidates_converted_today'] ?? 0),
                'secondary' => $ownerRmeLabPilot['conversion_rate_display'] ?? 'Belum ada konversi hari ini.',
                'severity' => ($ownerRmeLabPilot['lab_candidates_converted_today'] ?? 0) > 0 ? 'success' : 'neutral',
                'href' => $ownerRmeLabDrilldowns['lab_candidates_converted_today'] ?? null,
            ],
            [
                'label' => 'Lab Order dari RME Hari Ini',
                'value' => format_number_id($ownerRmeLabPilot['lab_orders_from_rme_today'] ?? 0),
                'secondary' => 'Lab order hasil konversi kandidat RME hari ini.',
                'severity' => ($ownerRmeLabPilot['lab_orders_from_rme_today'] ?? 0) > 0 ? 'success' : 'neutral',
                'href' => $ownerRmeLabDrilldowns['lab_orders_from_rme_today'] ?? null,
            ],
            [
                'label' => 'Sisa Piutang RME',
                'value' => format_currency_id($ownerRmeLabPilot['rme_receivable_total_remaining'] ?? 0),
                'secondary' => 'Sisa tagihan invoice UNPAID + PARTIAL.',
                'severity' => ($ownerRmeLabPilot['rme_receivable_total_remaining'] ?? 0) > 0 ? 'warning' : 'success',
                'href' => $ownerRmeLabDrilldowns['rme_receivables'] ?? null,
            ],
            [
                'label' => 'Invoice Cicilan',
                'value' => format_number_id($ownerRmeLabPilot['rme_receivable_partial_count'] ?? 0),
                'secondary' => 'Invoice status PARTIAL (dibayar sebagian).',
                'severity' => ($ownerRmeLabPilot['rme_receivable_partial_count'] ?? 0) > 0 ? 'warning' : 'success',
                'href' => $ownerRmeLabDrilldowns['rme_receivables'] ?? null,
            ],
            [
                'label' => 'Invoice Belum Dibayar',
                'value' => format_number_id($ownerRmeLabPilot['rme_receivable_unpaid_count'] ?? 0),
                'secondary' => 'Invoice status UNPAID (belum ada pembayaran).',
                'severity' => ($ownerRmeLabPilot['rme_receivable_unpaid_count'] ?? 0) > 0 ? 'warning' : 'success',
                'href' => $ownerRmeLabDrilldowns['rme_receivables'] ?? null,
            ],
            [
                'label' => 'Follow-up Jatuh Tempo',
                'value' => format_number_id($ownerRmeLabPilot['rme_receivable_follow_up_overdue_count'] ?? 0),
                'secondary' => 'Piutang aktif dengan reminder follow-up lewat tanggal.',
                'severity' => ($ownerRmeLabPilot['rme_receivable_follow_up_overdue_count'] ?? 0) > 0 ? 'warning' : 'success',
                'href' => $ownerRmeLabDrilldowns['rme_receivable_follow_up_overdue'] ?? null,
            ],
            [
                'label' => 'Follow-up Hari Ini',
                'value' => format_number_id($ownerRmeLabPilot['rme_receivable_follow_up_today_count'] ?? 0),
                'secondary' => 'Piutang aktif dengan reminder follow-up hari ini.',
                'severity' => ($ownerRmeLabPilot['rme_receivable_follow_up_today_count'] ?? 0) > 0 ? 'warning' : 'neutral',
                'href' => $ownerRmeLabDrilldowns['rme_receivable_follow_up_today'] ?? null,
            ],
            [
                'label' => 'Belum Pernah Follow-up',
                'value' => format_number_id($ownerRmeLabPilot['rme_receivable_never_followed_up_count'] ?? 0),
                'secondary' => 'Piutang aktif tanpa riwayat follow-up.',
                'severity' => ($ownerRmeLabPilot['rme_receivable_never_followed_up_count'] ?? 0) > 0 ? 'warning' : 'success',
                'href' => $ownerRmeLabDrilldowns['rme_receivable_never_followed_up'] ?? null,
            ],
            [
                'label' => 'Follow-up Terjadwal',
                'value' => format_number_id($ownerRmeLabPilot['rme_receivable_follow_up_scheduled_count'] ?? 0),
                'secondary' => 'Piutang aktif dengan reminder follow-up mendatang.',
                'severity' => ($ownerRmeLabPilot['rme_receivable_follow_up_scheduled_count'] ?? 0) > 0 ? 'info' : 'neutral',
                'href' => $ownerRmeLabDrilldowns['rme_receivable_follow_up_scheduled'] ?? null,
            ],
        ];

        $ownerRmeLabFunnel = $ownerRmeLabPilot['funnel_stages'] ?? [];
        $ownerRmeLabAttention = $ownerRmeLabPilot['attention_items'] ?? [];
    }

    $branchQuickActions = $branchQuickActions ?? [
        [
            'label' => 'Buat Order Lab',
            'href' => $canAny(['create_lab_orders', 'manage_lab_orders']) ? route('lab-orders.create') : null,
        ],
        [
            'label' => 'Buka Papan Produksi',
            'href' => $canAny(['view_production', 'manage_production']) ? route('production.board') : null,
        ],
        [
            'label' => 'Buka Antrean QC',
            'href' => $canAny(['view_quality_control', 'manage_quality_control']) ? route('quality-control.queue') : null,
        ],
        [
            'label' => 'Buka Antrean Pengiriman',
            'href' => $canAny(['view_delivery', 'manage_delivery']) ? route('deliveries.index') : null,
        ],
        [
            'label' => 'Stok Persediaan',
            'href' => $canAny(['view_inventory', 'manage_inventory', 'manage master data']) ? route('inventory.stock.index') : null,
        ],
        [
            'label' => 'Invoice',
            'href' => $canAny(['view_invoice', 'manage_invoice']) ? route('invoices.index') : null,
        ],
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-xl font-semibold leading-tight text-navy">
                    @if ($showBranchAdminDashboard)
                        Dashboard Admin Cabang
                    @elseif ($showOwnerDashboard)
                        Dashboard Owner
                    @else
                        Dashboard
                    @endif
                </h2>
                <p class="mt-1 text-sm text-ink-soft">
                    @if ($showBranchAdminDashboard)
                        Ringkasan operasional harian untuk cabang aktif.
                    @elseif ($showOwnerDashboard)
                        Ringkasan eksekutif untuk operasional pilot DaengtisiaMS.
                    @else
                        Ringkasan operasional untuk peran Anda.
                    @endif
                </p>
            </div>
            <div class="text-left sm:text-right">
                <p class="text-xs font-semibold uppercase tracking-wide text-ink-soft">Login sebagai</p>
                <p class="text-sm font-medium text-navy">{{ $user?->name }}</p>
            </div>
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6">
                @if ($showBranchAdminDashboard)
                    @include('dashboards.branch-admin')
                @elseif ($showOwnerDashboard)
                <x-ui.card accent>
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">Ringkasan Owner</p>
                            <h1 class="mt-1 text-2xl font-semibold text-navy">Kondisi bisnis secara ringkas</h1>
                            <p class="mt-2 max-w-3xl text-sm text-ink-soft">
                                Dasbor ini menggunakan tujuan Daengtisia Management System yang sudah tersedia dan empty state yang aman. Metrik live terperinci tetap tersedia melalui Laporan, Persediaan, dan modul operasional sampai layanan data owner khusus terhubung.
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @canany(['view_dashboard', 'manage_report'])
                                <x-ui.button :href="route('reports.dashboard')" variant="primary">Buka Laporan</x-ui.button>
                            @endcanany
                            @canany(['view_inventory', 'manage_inventory', 'manage master data'])
                                <x-ui.button :href="route('inventory.dashboard')" variant="secondary">Buka Persediaan</x-ui.button>
                            @endcanany
                        </div>
                    </div>
                </x-ui.card>

                @if (is_array($ownerKpi ?? null))
                    @include('dashboards.owner-kpi', ['ownerKpi' => $ownerKpi])
                @endif

                @if (is_array($ownerRmeLabPilot))
                    <section aria-labelledby="rme-lab-pilot-monitoring" class="space-y-6">
                        <div class="rounded-xl border border-brand-100 bg-brand-50/60 p-5 shadow-card">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">Monitoring Pilot RME &amp; Lab</p>
                                    <h3 id="rme-lab-pilot-monitoring" class="mt-1 text-lg font-semibold text-navy">Ringkasan operasional klinik pilot</h3>
                                    <p class="mt-2 max-w-3xl text-sm text-ink-soft">
                                        Data bersifat monitoring pilot dan dihitung dari transaksi RME/Lab saat ini.
                                        @if ($ownerRmeLabSelectedBranchId)
                                            Menampilkan cabang: {{ $ownerRmeLabPilot['scope_label'] ?? 'Cabang aktif' }}.
                                        @else
                                            Menampilkan semua cabang aktif.
                                        @endif
                                    </p>
                                    <p class="mt-2 text-xs text-ink-muted">
                                        Dashboard ini hanya monitoring; tidak membuat atau mengubah data RME/Lab.
                                    </p>
                                </div>

                                <form method="GET" action="{{ route('dashboard') }}" class="w-full max-w-xs shrink-0">
                                    <x-ui.select
                                        id="owner-rme-lab-branch-filter"
                                        name="branch_id"
                                        label="Filter Cabang"
                                        onchange="this.form.submit()"
                                    >
                                        <option value="" @selected($ownerRmeLabSelectedBranchId === null)>Semua Cabang</option>
                                        @foreach ($ownerRmeLabActiveBranches as $branch)
                                            <option value="{{ $branch->id }}" @selected($ownerRmeLabSelectedBranchId === $branch->id)>
                                                {{ $branch->name }}
                                            </option>
                                        @endforeach
                                    </x-ui.select>
                                </form>
                            </div>
                        </div>

                        <div>
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <h4 class="text-base font-semibold text-navy">KPI Pilot RME &amp; Lab</h4>
                                <p class="text-xs text-ink-soft">Read-only — tidak membuat atau mengubah transaksi</p>
                            </div>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                @foreach ($ownerRmeLabKpiCards as $kpi)
                                    <x-owner-dashboard.owner-kpi-card
                                        :label="data_get($kpi, 'label')"
                                        :value="data_get($kpi, 'value', '0')"
                                        :secondary="data_get($kpi, 'secondary')"
                                        :severity="data_get($kpi, 'severity', 'neutral')"
                                        :href="data_get($kpi, 'href')"
                                    />
                                @endforeach
                            </div>
                            @if (!empty($ownerRmeLabDrilldowns['rme_receivables']))
                                <div class="mt-4">
                                    <x-ui.button :href="$ownerRmeLabDrilldowns['rme_receivables']" variant="secondary" size="sm">Piutang RME</x-ui.button>
                                </div>
                            @endif
                        </div>

                        <div>
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <h4 class="text-base font-semibold text-navy">Ringkasan Per Cabang</h4>
                                <p class="text-xs text-ink-soft">Perbandingan ringan antar cabang aktif</p>
                            </div>

                            @if (collect($ownerRmeLabBranchSummary)->isEmpty())
                                <x-ui.empty-state
                                    title="Belum ada cabang aktif"
                                    description="Ringkasan per cabang akan tampil setelah ada cabang aktif dengan data pilot."
                                />
                            @else
                                <x-ui.table>
                                    <thead class="bg-navy-50">
                                        <tr>
                                            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Cabang</th>
                                            <th scope="col" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-ink-soft">Kunjungan Hari Ini</th>
                                            <th scope="col" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-ink-soft">Menunggu Kasir</th>
                                            <th scope="col" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-ink-soft">Invoice Belum Dibayar</th>
                                            <th scope="col" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-ink-soft">Kandidat Lab Pending</th>
                                            <th scope="col" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-ink-soft">Dikonversi Hari Ini</th>
                                            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Status Perhatian</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-hairline">
                                        @foreach ($ownerRmeLabBranchSummary as $row)
                                            <tr class="hover:bg-navy-50">
                                                <td class="px-4 py-3 font-medium text-navy">{{ $row['branch_name'] }}</td>
                                                <td class="px-4 py-3 text-right tabular-nums text-ink">{{ format_number_id($row['visits_today'] ?? 0) }}</td>
                                                <td class="px-4 py-3 text-right tabular-nums text-ink">{{ format_number_id($row['cashier_pending'] ?? 0) }}</td>
                                                <td class="px-4 py-3 text-right tabular-nums text-ink">{{ format_number_id($row['unpaid_invoices'] ?? 0) }}</td>
                                                <td class="px-4 py-3 text-right tabular-nums text-ink">{{ format_number_id($row['pending_candidates'] ?? 0) }}</td>
                                                <td class="px-4 py-3 text-right tabular-nums text-ink">{{ format_number_id($row['converted_today'] ?? 0) }}</td>
                                                <td class="px-4 py-3 text-ink">{{ $row['attention_status'] ?? 'Aman' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </x-ui.table>
                            @endif
                        </div>

                        <div>
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <h4 class="text-base font-semibold text-navy">Ringkasan Piutang per Cabang</h4>
                                <p class="text-xs text-ink-soft">Sisa piutang invoice RME aktif (UNPAID + PARTIAL) per cabang. Invoice lunas (sisa nol) tidak dihitung sebagai piutang aktif.</p>
                            </div>
                            <p class="mb-3 text-xs text-ink-soft">Tindak lanjut piutang dilakukan manual oleh operator (tanpa pengiriman WhatsApp otomatis).</p>

                            @if (collect($ownerRmeLabBranchReceivableSummary)->isEmpty())
                                <x-ui.empty-state
                                    title="Belum ada cabang aktif"
                                    description="Ringkasan piutang per cabang akan tampil setelah ada cabang aktif dengan data pilot."
                                />
                            @else
                                <x-ui.table>
                                    <thead class="bg-navy-50">
                                        <tr>
                                            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Cabang</th>
                                            <th scope="col" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-ink-soft">Sisa Piutang</th>
                                            <th scope="col" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-ink-soft">Invoice Cicilan</th>
                                            <th scope="col" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-ink-soft">Invoice Belum Dibayar</th>
                                            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-ink-soft">Tindak Lanjut</th>
                                            <th scope="col" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-ink-soft">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-hairline">
                                        @foreach ($ownerRmeLabBranchReceivableSummary as $row)
                                            <tr class="hover:bg-navy-50">
                                                <td class="px-4 py-3 font-medium text-navy">{{ $row['branch_name'] }}</td>
                                                <td class="px-4 py-3 text-right tabular-nums text-ink">{{ format_currency_id($row['receivable_total_remaining'] ?? 0) }}</td>
                                                <td class="px-4 py-3 text-right tabular-nums text-ink">{{ format_number_id($row['partial_count'] ?? 0) }}</td>
                                                <td class="px-4 py-3 text-right tabular-nums text-ink">{{ format_number_id($row['unpaid_count'] ?? 0) }}</td>
                                                <td class="px-4 py-3 text-xs text-ink-soft">
                                                    <span class="font-medium text-warning-700">{{ format_number_id($row['follow_up_overdue_count'] ?? 0) }}</span> jatuh tempo;
                                                    {{ format_number_id($row['follow_up_today_count'] ?? 0) }} hari ini;
                                                    {{ format_number_id($row['follow_up_scheduled_count'] ?? 0) }} terjadwal;
                                                    {{ format_number_id($row['never_followed_up_count'] ?? 0) }} belum pernah
                                                </td>
                                                <td class="px-4 py-3 text-right">
                                                    @if (!empty($ownerRmeLabDrilldowns['rme_receivables']))
                                                        <x-ui.button :href="route('rme.cashier.receivables', ['branch_id' => $row['branch_id']])" variant="secondary" size="sm">Lihat Piutang</x-ui.button>
                                                    @else
                                                        <span class="text-xs text-ink-muted">—</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </x-ui.table>
                            @endif
                        </div>

                        <x-owner-dashboard.pipeline-card
                            :stages="$ownerRmeLabFunnel"
                            title="Funnel RME ke Lab"
                            period-label="Kunjungan hari ini → kasir → pembayaran → kandidat → lab order"
                        />

                        <x-owner-dashboard.alert-panel
                            :alerts="$ownerRmeLabAttention"
                            title="Perlu Perhatian"
                            empty-title="Tidak ada cabang yang perlu perhatian"
                            empty-body="Kunjungan menunggu kasir, invoice tertunda, kandidat lab pending, dan RM draft akan tampil per cabang saat ada data."
                        />
                    </section>
                @endif

                <section aria-labelledby="executive-kpis">
                    <div class="mb-3 flex items-center justify-between">
                        <h3 id="executive-kpis" class="text-base font-semibold text-navy">Kartu KPI Eksekutif</h3>
                        <p class="text-xs text-ink-soft">Pendapatan, beban kerja, risiko kas, dan risiko persediaan</p>
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-6">
                        @foreach ($ownerKpis as $kpi)
                            <x-owner-dashboard.owner-kpi-card
                                :label="data_get($kpi, 'label')"
                                :value="data_get($kpi, 'value', '0')"
                                :secondary="data_get($kpi, 'secondary')"
                                :trend="data_get($kpi, 'trend')"
                                :severity="data_get($kpi, 'severity', 'neutral')"
                                :href="data_get($kpi, 'href')"
                            />
                        @endforeach
                    </div>
                </section>

                <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <x-owner-dashboard.pipeline-card
                        :stages="$pipelineStages"
                        title="Pipeline Operasional"
                        period-label="Dari diterima sampai selesai"
                    />

                    <x-owner-dashboard.alert-panel
                        :alerts="$ownerAlerts"
                        title="Pusat Peringatan"
                        empty-title="Tidak ada peringatan mendesak"
                        empty-body="Order terlambat, stok menipis, invoice belum dibayar, dan isu QC akan tampil di sini saat metrik owner tersedia."
                    />
                </div>

                <x-owner-dashboard.dashboard-section
                    title="Performa Cabang"
                    description="Pendapatan, beban order, dan waktu penyelesaian per cabang."
                    :action-href="$canAny(['view_dashboard', 'manage_report']) ? route('reports.dashboard') : null"
                    action-label="Buka dasbor laporan"
                >
                    @if (collect($branchPerformance)->isEmpty())
                        <x-ui.empty-state
                            title="Belum ada data performa cabang"
                            description="Perbandingan cabang membutuhkan data laporan yang dikelompokkan. Halaman laporan tetap tersedia untuk review terperinci."
                        />
                    @else
                        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            @foreach ($branchPerformance as $branch)
                                <x-owner-dashboard.branch-performance-card :branch="$branch" />
                            @endforeach
                        </div>
                    @endif
                </x-owner-dashboard.dashboard-section>

                <x-owner-dashboard.activity-timeline
                    :events="$recentActivity"
                    title="Timeline Aktivitas Terbaru"
                    empty-title="Belum ada aktivitas terbaru"
                />

                <x-owner-dashboard.dashboard-section title="Akses Detail Tersedia" description="Gunakan modul DaengtisiaMS yang sudah ada untuk detail operasional live." density="compact">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        @canany(['view_order_report', 'manage_report'])
                            <a href="{{ route('reports.orders') }}" class="rounded-lg border border-hairline p-3 text-sm font-medium text-ink hover:bg-navy-50 hover:border-brand-200 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">Laporan Order</a>
                        @endcanany
                        @canany(['view_qc_report', 'manage_report'])
                            <a href="{{ route('reports.qc') }}" class="rounded-lg border border-hairline p-3 text-sm font-medium text-ink hover:bg-navy-50 hover:border-brand-200 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">Laporan QC</a>
                        @endcanany
                        @canany(['view_invoice_report', 'manage_report'])
                            <a href="{{ route('reports.outstanding') }}" class="rounded-lg border border-hairline p-3 text-sm font-medium text-ink hover:bg-navy-50 hover:border-brand-200 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">Invoice Tertunggak</a>
                        @endcanany
                        @canany(['view_inventory', 'manage_inventory', 'manage master data'])
                            <a href="{{ route('inventory.stock.index') }}" class="rounded-lg border border-hairline p-3 text-sm font-medium text-ink hover:bg-navy-50 hover:border-brand-200 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">Stok Persediaan</a>
                        @endcanany
                    </div>
                </x-owner-dashboard.dashboard-section>
                @else
                <x-ui.card>
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">Dasbor Operasional</p>
                    <h1 class="mt-1 text-2xl font-semibold text-navy">Mulai dari menu modul Anda</h1>
                    <p class="mt-2 max-w-3xl text-sm text-ink-soft">
                        Peran ini tidak memiliki ringkasan eksekutif atau operasional cabang penuh. Gunakan menu samping untuk kunjungan klinik, kasir RME, atau modul lain yang diizinkan.
                    </p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        @canany(['view_clinic_visits', 'manage_clinic_visits'])
                            @if($user?->hasRole('Doctor'))
                                <x-ui.button :href="route('rme.treatment-room-worklist.index')" variant="primary">Buka Ruang Perawatan</x-ui.button>
                            @elseif(! $user?->hasRole('Kasir'))
                                <x-ui.button :href="route('rme.visits.index')" variant="primary">Buka Kunjungan</x-ui.button>
                            @endif
                        @endcanany
                        @can('manage_rme_billing')
                            @unless($user?->hasRole('Admin Klinik'))
                                <x-ui.button :href="route('rme.cashier.index')" variant="secondary">Buka Kasir RME</x-ui.button>
                            @endunless
                        @endcan
                    </div>
                </x-ui.card>
                @endif
    </div>
</x-app-layout>
