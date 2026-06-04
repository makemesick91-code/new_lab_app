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
            'label' => 'Revenue This Month',
            'value' => 'Rp 0.00',
            'secondary' => 'No revenue data connected on this page yet.',
            'severity' => 'neutral',
            'href' => $canAny(['view_invoice_report', 'manage_report']) ? route('reports.revenue') : null,
        ],
        [
            'label' => 'Active Orders',
            'value' => '0',
            'secondary' => 'Open lab orders or reports for live counts.',
            'severity' => 'neutral',
            'href' => $canAny(['view_lab_orders', 'manage_lab_orders']) ? route('lab-orders.index') : null,
        ],
        [
            'label' => 'Overdue Orders',
            'value' => '0',
            'secondary' => 'No overdue data supplied to this view.',
            'severity' => 'success',
            'href' => $canAny(['view_order_report', 'manage_report']) ? route('reports.orders') : null,
        ],
        [
            'label' => 'Outstanding Invoices',
            'value' => 'Rp 0.00',
            'secondary' => 'Use invoice reports for detailed aging.',
            'severity' => 'neutral',
            'href' => $canAny(['view_invoice_report', 'manage_report']) ? route('reports.outstanding') : null,
        ],
        [
            'label' => 'Inventory Value',
            'value' => 'Rp 0.00',
            'secondary' => 'Ledger-derived value from Inventory Core.',
            'severity' => 'neutral',
            'href' => $canAny(['view_inventory', 'manage_inventory', 'manage master data']) ? route('inventory.dashboard') : null,
        ],
        [
            'label' => 'Low Stock Count',
            'value' => '0',
            'secondary' => 'Low-stock alerts appear when data is supplied.',
            'severity' => 'success',
            'href' => $canAny(['view_inventory', 'manage_inventory', 'manage master data']) ? route('inventory.stock.index') : null,
        ],
    ];

    $pipelineStages = $pipelineStages ?? [
        [
            'label' => 'Received',
            'count' => 0,
            'percent' => 0,
            'oldestAge' => 'No aging data',
            'severity' => 'neutral',
            'href' => $canAny(['view_lab_orders', 'manage_lab_orders']) ? route('lab-orders.index') : null,
        ],
        [
            'label' => 'In Production',
            'count' => 0,
            'percent' => 0,
            'oldestAge' => 'No aging data',
            'severity' => 'neutral',
            'href' => $canAny(['view_production', 'manage_production']) ? route('production.board') : null,
        ],
        [
            'label' => 'QC Pending',
            'count' => 0,
            'percent' => 0,
            'oldestAge' => 'No aging data',
            'severity' => 'neutral',
            'href' => $canAny(['view_quality_control', 'manage_quality_control']) ? route('quality-control.queue') : null,
        ],
        [
            'label' => 'QC Failed',
            'count' => 0,
            'percent' => 0,
            'oldestAge' => 'No aging data',
            'severity' => 'neutral',
            'href' => $canAny(['view_qc_report', 'manage_report']) ? route('reports.qc') : null,
        ],
        [
            'label' => 'Ready Delivery',
            'count' => 0,
            'percent' => 0,
            'oldestAge' => 'No aging data',
            'severity' => 'neutral',
            'href' => $canAny(['view_delivery', 'manage_delivery']) ? route('deliveries.index') : null,
        ],
        [
            'label' => 'Delivered',
            'count' => 0,
            'percent' => 0,
            'oldestAge' => 'No aging data',
            'severity' => 'neutral',
            'href' => $canAny(['view_delivery_report', 'manage_report']) ? route('reports.delivery') : null,
        ],
        [
            'label' => 'Completed',
            'count' => 0,
            'percent' => 0,
            'oldestAge' => 'No aging data',
            'severity' => 'neutral',
            'href' => $canAny(['view_order_report', 'manage_report']) ? route('reports.orders') : null,
        ],
    ];

    $ownerAlerts = $ownerAlerts ?? [];
    $branchPerformance = $branchPerformance ?? [];
    $recentActivity = $recentActivity ?? [];

    $branchSummaryCards = $branchSummaryCards ?? [
        [
            'label' => 'Arrived Today',
            'value' => '0',
            'context' => 'No arrivals data supplied.',
            'severity' => 'neutral',
            'href' => $canAny(['view_lab_orders', 'manage_lab_orders']) ? route('lab-orders.index') : null,
        ],
        [
            'label' => 'Needs Assignment',
            'value' => '0',
            'context' => 'All visible work is assigned.',
            'severity' => 'success',
            'href' => $canAny(['view_production', 'manage_production']) ? route('production.board') : null,
        ],
        [
            'label' => 'Stuck / Overdue',
            'value' => '0',
            'context' => 'No stuck work supplied.',
            'severity' => 'success',
            'href' => $canAny(['view_lab_orders', 'manage_lab_orders']) ? route('lab-orders.index') : null,
        ],
        [
            'label' => 'Needs QC',
            'value' => '0',
            'context' => 'QC queue is clear.',
            'severity' => 'success',
            'href' => $canAny(['view_quality_control', 'manage_quality_control']) ? route('quality-control.queue') : null,
        ],
        [
            'label' => 'Ready Delivery',
            'value' => '0',
            'context' => 'No ready delivery data supplied.',
            'severity' => 'neutral',
            'href' => $canAny(['view_delivery', 'manage_delivery']) ? route('deliveries.index') : null,
        ],
        [
            'label' => 'Low Stock',
            'value' => '0',
            'context' => 'No low stock alerts supplied.',
            'severity' => 'success',
            'href' => $canAny(['view_inventory', 'manage_inventory', 'manage master data']) ? route('inventory.stock.index') : null,
        ],
        [
            'label' => 'Unpaid Invoices',
            'value' => '0',
            'context' => 'No unpaid invoice data supplied.',
            'severity' => 'neutral',
            'href' => $canAny(['view_invoice', 'manage_invoice']) ? route('invoices.index') : null,
        ],
    ];

    $branchQueues = $branchQueues ?? [
        [
            'title' => 'Arrived Today',
            'items' => [],
            'empty' => 'No new orders today.',
        ],
        [
            'title' => 'Needs Assignment',
            'items' => [],
            'empty' => 'All new orders are assigned.',
        ],
        [
            'title' => 'Needs QC',
            'items' => [],
            'empty' => 'QC queue is clear.',
        ],
        [
            'title' => 'Ready Delivery',
            'items' => [],
            'empty' => 'No orders waiting for delivery.',
        ],
        [
            'title' => 'Finance Follow-up',
            'items' => [],
            'empty' => 'No unpaid invoices needing follow-up.',
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
            'label' => 'Create Lab Order',
            'href' => $canAny(['create_lab_orders', 'manage_lab_orders']) ? route('lab-orders.create') : null,
        ],
        [
            'label' => 'Open Production Board',
            'href' => $canAny(['view_production', 'manage_production']) ? route('production.board') : null,
        ],
        [
            'label' => 'Open QC Queue',
            'href' => $canAny(['view_quality_control', 'manage_quality_control']) ? route('quality-control.queue') : null,
        ],
        [
            'label' => 'Open Delivery Queue',
            'href' => $canAny(['view_delivery', 'manage_delivery']) ? route('deliveries.index') : null,
        ],
        [
            'label' => 'Inventory Stock',
            'href' => $canAny(['view_inventory', 'manage_inventory', 'manage master data']) ? route('inventory.stock.index') : null,
        ],
        [
            'label' => 'Invoices',
            'href' => $canAny(['view_invoice', 'manage_invoice']) ? route('invoices.index') : null,
        ],
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    {{ $showBranchAdminDashboard ? 'Branch Admin Dashboard' : 'Owner Dashboard' }}
                </h2>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $showBranchAdminDashboard ? 'Daily operations overview for the active branch.' : 'Executive overview for ADLMS pilot operations.' }}
                </p>
            </div>
            <div class="text-left sm:text-right">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Signed in as</p>
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
                            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Owner Overview</p>
                            <h1 class="mt-1 text-2xl font-semibold text-gray-900">Business health at a glance</h1>
                            <p class="mt-2 max-w-3xl text-sm text-gray-600">
                                This dashboard uses existing ADLMS destinations and safe empty states. Detailed live metrics remain available through Reports, Inventory, and operational modules until a dedicated owner data service is connected.
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @canany(['view_dashboard', 'manage_report'])
                                <a href="{{ route('reports.dashboard') }}" class="rounded-md bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-900 focus:ring-offset-2">
                                    Open Reports
                                </a>
                            @endcanany
                            @canany(['view_inventory', 'manage_inventory', 'manage master data'])
                                <a href="{{ route('inventory.dashboard') }}" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                                    Open Inventory
                                </a>
                            @endcanany
                        </div>
                    </div>
                </section>

                <section aria-labelledby="executive-kpis">
                    <div class="mb-3 flex items-center justify-between">
                        <h3 id="executive-kpis" class="text-base font-semibold text-gray-900">Executive KPI Cards</h3>
                        <p class="text-xs text-gray-500">Revenue, workload, cash risk, and inventory risk</p>
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
                        title="Operational Pipeline"
                        period-label="Received through completion"
                    />

                    <x-owner-dashboard.alert-panel
                        :alerts="$ownerAlerts"
                        title="Alert Center"
                        empty-title="No urgent alerts"
                        empty-body="Overdue orders, low stock, unpaid invoices, and QC issues will appear here when owner metrics are supplied."
                    />
                </div>

                <x-owner-dashboard.dashboard-section
                    title="Branch Performance"
                    description="Revenue, order load, and completion time by branch."
                    :action-href="$canAny(['view_dashboard', 'manage_report']) ? route('reports.dashboard') : null"
                    action-label="Open reporting dashboard"
                >
                    @if (collect($branchPerformance)->isEmpty())
                        <div class="rounded-lg border border-dashed border-gray-200 px-4 py-8 text-center">
                            <p class="text-sm font-medium text-gray-900">No branch performance data</p>
                            <p class="mt-1 text-sm text-gray-500">Branch comparison needs grouped reporting data. Existing report pages remain available for detailed review.</p>
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
                    title="Recent Activity Timeline"
                    empty-title="No recent activity"
                />

                <x-owner-dashboard.dashboard-section title="Available Drill-downs" description="Use existing ADLMS modules for live operational details." density="compact">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        @canany(['view_order_report', 'manage_report'])
                            <a href="{{ route('reports.orders') }}" class="rounded-lg border border-gray-200 p-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">Order Reports</a>
                        @endcanany
                        @canany(['view_qc_report', 'manage_report'])
                            <a href="{{ route('reports.qc') }}" class="rounded-lg border border-gray-200 p-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">QC Reports</a>
                        @endcanany
                        @canany(['view_invoice_report', 'manage_report'])
                            <a href="{{ route('reports.outstanding') }}" class="rounded-lg border border-gray-200 p-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">Outstanding Invoices</a>
                        @endcanany
                        @canany(['view_inventory', 'manage_inventory', 'manage master data'])
                            <a href="{{ route('inventory.stock.index') }}" class="rounded-lg border border-gray-200 p-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">Inventory Stock</a>
                        @endcanany
                    </div>
                </x-owner-dashboard.dashboard-section>
                @endif
            </div>
        </main>
    </div>
</x-app-layout>
