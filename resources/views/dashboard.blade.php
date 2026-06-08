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
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    {{ $showBranchAdminDashboard ? 'Dasbor Admin Cabang' : 'Dasbor Owner' }}
                </h2>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $showBranchAdminDashboard ? 'Ringkasan operasional harian untuk cabang aktif.' : 'Ringkasan eksekutif untuk operasional pilot DaengtisiaMS.' }}
                </p>
            </div>
            <div class="text-left sm:text-right">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Login sebagai</p>
                <p class="text-sm font-medium text-gray-900">{{ $user?->name }}</p>
            </div>
        </div>
    </x-slot>

    <div class="flex">
        @include('layouts.sidebar')

        <main class="min-w-0 flex-1 bg-gray-50 px-4 py-6 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-7xl space-y-6">
                @if ($showBranchAdminDashboard)
                    @include('dashboards.branch-admin')
                @else
                <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Ringkasan Owner</p>
                            <h1 class="mt-1 text-2xl font-semibold text-gray-900">Kondisi bisnis secara ringkas</h1>
                            <p class="mt-2 max-w-3xl text-sm text-gray-600">
                                Dasbor ini menggunakan tujuan Daengtisia Management System yang sudah tersedia dan empty state yang aman. Metrik live terperinci tetap tersedia melalui Laporan, Persediaan, dan modul operasional sampai layanan data owner khusus terhubung.
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @canany(['view_dashboard', 'manage_report'])
                                <a href="{{ route('reports.dashboard') }}" class="rounded-md bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-900 focus:ring-offset-2">
                                    Buka Laporan
                                </a>
                            @endcanany
                            @canany(['view_inventory', 'manage_inventory', 'manage master data'])
                                <a href="{{ route('inventory.dashboard') }}" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                                    Buka Persediaan
                                </a>
                            @endcanany
                        </div>
                    </div>
                </section>

                <section aria-labelledby="executive-kpis">
                    <div class="mb-3 flex items-center justify-between">
                        <h3 id="executive-kpis" class="text-base font-semibold text-gray-900">Kartu KPI Eksekutif</h3>
                        <p class="text-xs text-gray-500">Pendapatan, beban kerja, risiko kas, dan risiko persediaan</p>
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
                        <div class="rounded-lg border border-dashed border-gray-200 px-4 py-8 text-center">
                            <p class="text-sm font-medium text-gray-900">Belum ada data performa cabang</p>
                            <p class="mt-1 text-sm text-gray-500">Perbandingan cabang membutuhkan data laporan yang dikelompokkan. Halaman laporan tetap tersedia untuk review terperinci.</p>
                        </div>
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
                            <a href="{{ route('reports.orders') }}" class="rounded-lg border border-gray-200 p-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">Laporan Order</a>
                        @endcanany
                        @canany(['view_qc_report', 'manage_report'])
                            <a href="{{ route('reports.qc') }}" class="rounded-lg border border-gray-200 p-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">Laporan QC</a>
                        @endcanany
                        @canany(['view_invoice_report', 'manage_report'])
                            <a href="{{ route('reports.outstanding') }}" class="rounded-lg border border-gray-200 p-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">Invoice Tertunggak</a>
                        @endcanany
                        @canany(['view_inventory', 'manage_inventory', 'manage master data'])
                            <a href="{{ route('inventory.stock.index') }}" class="rounded-lg border border-gray-200 p-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">Stok Persediaan</a>
                        @endcanany
                    </div>
                </x-owner-dashboard.dashboard-section>
                @endif
            </div>
        </main>
    </div>
</x-app-layout>
