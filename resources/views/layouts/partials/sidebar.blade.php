{{--
    DaengtisiaMS sidebar — permission-aware navigation (TailAdmin-inspired shell).

    Visibility is gated using Spatie Permission @can / @canany and @role Blade directives.
    Collapsible groups persist open/closed state in localStorage (adlms-sidebar-groups).
--}}
@php
    $user = auth()->user();
    // Sprint 58.2: Admin Warehouse main Dashboard points to inventory.dashboard (navigation only).
    $isAdminWarehouse = $user?->hasRole('Admin Warehouse');
    $showInventoryOperationsGroup = $user && (
        $user->can('viewAny', \App\Modules\Inventory\Models\Product::class)
        || $user->can('viewAny', \App\Modules\Inventory\Models\StockOpname::class)
        || $user->can('viewAny', \App\Modules\Inventory\Models\StockTransfer::class)
        || $user->can('viewAny', \App\Modules\Inventory\Models\LocationProductMinimum::class)
    );

    $showInventoryReportsGroup = $user && (
        $user->can('viewAny', \App\Modules\Inventory\Models\InventoryMovement::class)
        || $user->can('viewAlerts', \App\Modules\Inventory\Models\InventoryMovement::class)
        || $user->can('viewAnalytics', \App\Modules\Inventory\Models\InventoryMovement::class)
        || $user->can('viewExecutiveDashboard', \App\Modules\Inventory\Models\InventoryMovement::class)
        || $user->can('viewAny', \App\Modules\Inventory\Models\InventoryActivityLog::class)
    );

    $showProcurementGroup = $user && (
        $user->can('viewAny', \App\Modules\Inventory\Models\PurchaseRequest::class)
        || $user->can('viewAny', \App\Modules\Inventory\Models\PurchaseOrder::class)
        || $user->can('viewAny', \App\Modules\Inventory\Models\GoodsReceipt::class)
    );

    $showInventoryMasterDataGroup = $user && (
        $user->can('viewAny', \App\Modules\Inventory\Models\Product::class)
        || $user->can('viewAny', \App\Modules\Inventory\Models\ProductCategory::class)
        || $user->can('viewAny', \App\Modules\Inventory\Models\ProductUnit::class)
        || $user->can('viewAny', \App\Modules\Inventory\Models\InventoryBatch::class)
    );

    $inventoryMasterDataRoutes = [
        'inventory.products.*',
        'inventory.product-categories.*',
        'inventory.product-units.*',
        'inventory.locations.*',
        'inventory.suppliers.*',
        'inventory.batches.*',
    ];

    $inventoryOperationsRoutes = [
        'inventory.stock.*',
        'inventory.stock-opnames.*',
        'inventory.stock-transfers.*',
        'inventory.location-minimums.*',
        'inventory.products.adjust-in.*',
        'inventory.products.adjust-out.*',
    ];

    $inventoryReportsRoutes = [
        'inventory.dashboard',
        'inventory.reports.*',
        'inventory.analytics.*',
        'inventory.alerts.*',
        'inventory.executive-dashboard',
        'inventory.activity-logs.*',
    ];

    $inventoryProcurementRoutes = [
        'inventory.purchase-requests.*',
        'inventory.purchase-orders.*',
        'inventory.goods-receipts.*',
    ];

    // UIX-19 — information-architecture section visibility (presentation-only).
    // Each boolean mirrors the exact union of its section's child @can/@canany guards,
    // so a section label renders only when at least one of its links is visible. This
    // is navigation clarity only — server-side route middleware/policy stays authoritative.
    $showLabGroup = $user && (
        $user->canAny(['view_lab_orders', 'manage_lab_orders', 'view_production', 'manage_production', 'view_quality_control', 'manage_quality_control', 'view_delivery', 'manage_delivery', 'create_lab_branch_requests', 'manage_lab_pickups'])
        || $user->hasRole('Courier')
    );

    $showFinanceReportingGroup = $user && $user->canAny([
        'view_invoice', 'manage_invoice', 'view_payment', 'manage_payment', 'view_payment_report', 'manage_report',
        'view_dashboard', 'view_order_report', 'view_production_report', 'view_qc_report', 'view_delivery_report', 'view_invoice_report',
    ]);

    $showAdminGroup = $user && (
        ($user->canAny(['manage doctors', 'manage patients', 'manage lab services', 'manage technicians', 'view_clinic_master_data', 'manage_clinic_master_data', 'view_branch_master_data', 'manage_branch_master_data']) && ! $user->hasRole('Admin Klinik'))
        || $user->canAny(['manage users', 'manage roles', 'manage permissions'])
        || (config('developer_console.enabled', true) && $user->can('view_developer_console'))
    );

    $tabQuery = request('tab');
    $tabAliases = [
        'current-stock' => 'current_stock',
        'stock-card' => 'stock_card',
        'low-stock' => 'low_stock',
        'mutation' => 'mutation',
        'valuation' => 'valuation',
        'room-stock' => 'room_stock',
    ];
    $activeReportTab = is_string($tabQuery) && isset($tabAliases[$tabQuery])
        ? $tabAliases[$tabQuery]
        : request('report_tab', 'current_stock');

    $sidebarRouteOpen = [
        'rme' => request()->routeIs('rme.*'),
        'lab' => request()->routeIs('lab-orders.*', 'lab-case-candidates.*', 'lab-workflow-requests.*', 'lab-pickup-tasks.*', 'lab-v2-orders.*', 'lab-external-labs.*', 'lab-delivery-tasks.*', 'lab-workflow-dashboard.*'),
        'production' => request()->routeIs('production.*'),
        'qc' => request()->routeIs('quality-control.*'),
        'my-work' => request()->routeIs('production.*'),
        'delivery' => request()->routeIs('deliveries.*'),
        'inventory' => request()->routeIs(...$inventoryOperationsRoutes),
        'inventory-master-data' => request()->routeIs(...$inventoryMasterDataRoutes),
        'inventory-reports-analytics' => request()->routeIs(...$inventoryReportsRoutes),
        'procurement' => request()->routeIs(...$inventoryProcurementRoutes),
        'finance' => request()->routeIs('invoices.*', 'reports.payments'),
        'reporting' => request()->routeIs('reports.*'),
        'master-data' => request()->routeIs(
            'settings.doctors.*',
            'settings.patients.*',
            'settings.lab-services.*',
            'settings.technicians.*',
            'settings.clinic-rooms.*',
            'settings.treatment-categories.*',
            'settings.treatments.*',
            'settings.tariffs.*',
            'settings.payment-methods.*',
            'settings.wa-reminder-templates.*',
            'settings.branches.*'
        ),
        'settings' => request()->routeIs('settings.users.*', 'settings.roles.*', 'settings.permissions.*'),
    ];

    $linkActive = 'menu-subitem-active';
    $linkIdle = 'menu-subitem-inactive';
    $groupToggle = 'menu-item menu-item-inactive w-full justify-between';
@endphp

<aside
    class="fixed top-0 left-0 z-50 flex h-screen w-[290px] flex-col border-r border-hairline bg-white transition-transform duration-300 ease-in-out"
    :class="{
        'translate-x-0': $store.sidebar.isMobileOpen,
        '-translate-x-full': ! $store.sidebar.isMobileOpen,
        'xl:translate-x-0': $store.sidebar.isExpanded,
        'xl:-translate-x-full': ! $store.sidebar.isExpanded,
    }"
>
    <div class="flex items-center border-b border-hairline px-4 py-5">
        <a href="{{ route($isAdminWarehouse ? 'inventory.dashboard' : 'dashboard') }}" class="min-w-0">
            <p class="text-sm font-bold text-navy">DaengtisiaMS</p>
            <p class="text-xs text-ink-soft">Klinik Gigi Daengtisia</p>
        </a>
    </div>

    <nav
        class="flex-1 overflow-y-auto p-4 text-sm"
        x-data="adlmsSidebar(@js($sidebarRouteOpen))"
    >
        <div class="space-y-1">
            @if ($isAdminWarehouse)
                <a href="{{ route('inventory.dashboard') }}"
                   class="menu-item {{ request()->routeIs('inventory.dashboard') ? 'menu-item-active' : 'menu-item-inactive' }}">
                    <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 12l9-7 9 7M5 10v9a1 1 0 001 1h4v-5h4v5h4a1 1 0 001-1v-9" />
                    </svg>
                    <span>Dashboard</span>
                </a>
            @else
                @canany(['view dashboard', 'view_owner_dashboard'])
                    <a href="{{ route('dashboard') }}"
                       class="menu-item {{ request()->routeIs('dashboard') ? 'menu-item-active' : 'menu-item-inactive' }}">
                        <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 12l9-7 9 7M5 10v9a1 1 0 001 1h4v-5h4v5h4a1 1 0 001-1v-9" />
                        </svg>
                        <span>{{ $user?->can('view_owner_dashboard') ? 'Dashboard Owner' : 'Dashboard' }}</span>
                    </a>
                @endcanany
            @endif

            {{-- LAB-WORKFLOW-V2 Phase 5 — in-app notification inbox (all authed users) --}}
            @php
                $unreadNotificationCount = $user?->unreadNotifications()->count() ?? 0;
            @endphp
            <a href="{{ route('notifications.index') }}"
               class="menu-item {{ request()->routeIs('notifications.*') ? 'menu-item-active' : 'menu-item-inactive' }}">
                <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                </svg>
                <span>Notifikasi</span>
                @if ($unreadNotificationCount > 0)
                    <span class="ml-auto rounded-full bg-brand-500 px-2 py-0.5 text-xs font-semibold text-white">{{ $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount }}</span>
                @endif
            </a>

            @canany(['view_clinic_visits', 'manage_clinic_visits'])
                <div class="pt-2">
                    <p class="menu-group-title">Klinik &amp; RME</p>
                    <button type="button" @click="toggle('rme')" class="{{ $groupToggle }}" :aria-expanded="isOpen('rme')">
                        <span class="flex items-center gap-3">
                            <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                            </svg>
                            <span>Dashboard RME</span>
                        </span>
                        <svg class="h-4 w-4 shrink-0 text-ink-muted transition-transform duration-150" :class="{ 'rotate-180': isOpen('rme') }" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.94a.75.75 0 111.08 1.04l-4.24 4.5a.75.75 0 01-1.08 0l-4.24-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                        </svg>
                    </button>
                    <div data-sidebar-panel="rme" x-show="isOpen('rme')" class="mt-1 space-y-0.5 pl-8">
                        @unless($user?->hasRole('Doctor'))
                            <a href="{{ route('rme.dashboard') }}"
                               class="menu-subitem {{ request()->routeIs('rme.dashboard') ? $linkActive : $linkIdle }}">Dasbor RME</a>
                        @endunless
                        @unless($user?->hasRole('Doctor') || $user?->hasRole('Kasir'))
                            <a href="{{ route('rme.visits.index') }}"
                               class="menu-subitem {{ (request()->routeIs('rme.visits.*') && ! request()->routeIs('rme.visits.medical-record*')) ? $linkActive : $linkIdle }}">Kunjungan</a>
                            <a href="{{ route('rme.patient-queue.index') }}"
                               class="menu-subitem {{ request()->routeIs('rme.patient-queue.*') ? $linkActive : $linkIdle }}">Antrian Pasien</a>
                        @endunless
                        @unless($user?->hasRole('Kasir'))
                            <a href="{{ route('rme.medical-records.index') }}"
                               class="menu-subitem {{ request()->routeIs('rme.medical-records.*', 'rme.visits.medical-record*') ? $linkActive : $linkIdle }}">Rekam Medis</a>
                        @endunless
                        @can('view_treatment_worklist')
                            <a href="{{ route('rme.treatment-room-worklist.index') }}"
                               class="menu-subitem {{ request()->routeIs('rme.treatment-room-worklist.*') ? $linkActive : $linkIdle }}">Ruang Perawatan</a>
                        @endcan
                        @can('manage_rme_billing')
                            @unless($user?->hasRole('Admin Klinik'))
                                <a href="{{ route('rme.cashier.handoff') }}"
                                   class="menu-subitem {{ request()->routeIs('rme.cashier.handoff') ? $linkActive : $linkIdle }}">Sinkronisasi Dokter–Kasir</a>
                                <a href="{{ route('rme.cashier.index') }}"
                                   class="menu-subitem {{ request()->routeIs('rme.cashier.index', 'rme.cashier.create', 'rme.cashier.store', 'rme.cashier.show', 'rme.cashier.payment.*', 'rme.cashier.receipt.*') ? $linkActive : $linkIdle }}">Kasir RME</a>
                                <a href="{{ route('rme.cashier.receivables') }}"
                                   class="menu-subitem {{ request()->routeIs('rme.cashier.receivables') ? $linkActive : $linkIdle }}">Piutang RME</a>
                            @endunless
                        @endcan
                        @can('view_rme_patient_reports')
                            <a href="{{ route('rme.reports.patients') }}"
                               class="menu-subitem {{ request()->routeIs('rme.reports.patients') ? $linkActive : $linkIdle }}">Laporan Pasien RME</a>
                        @endcan
                        @can('view_rme_payment_reports')
                            <a href="{{ route('rme.reports.payments') }}"
                               class="menu-subitem {{ request()->routeIs('rme.reports.payments') ? $linkActive : $linkIdle }}">Laporan Pembayaran RME</a>
                        @endcan
                        @canany(['view_doctor_performance_report', 'view_own_doctor_performance_report'])
                            <a href="{{ route('rme.reports.doctor-performance') }}"
                               class="menu-subitem {{ request()->routeIs('rme.reports.doctor-performance') ? $linkActive : $linkIdle }}">Kinerja &amp; Pendapatan Dokter</a>
                        @endcanany
                        @canany(['view_rme_patient_reports', 'manage patients'])
                            @unless($user?->hasRole('Admin Klinik'))
                                <a href="{{ route('rme.patients.audit') }}"
                                   class="menu-subitem {{ request()->routeIs('rme.patients.audit') ? $linkActive : $linkIdle }}">Audit Data Pasien</a>
                            @endunless
                        @endcanany
                    </div>
                </div>
            @endcanany

            {{-- SATUSEHAT-1 — controlled submission filter + mapping/identifier governance. Independently permission-gated. --}}
            @canany(['view_satusehat_submissions', 'review_satusehat_submissions', 'send_satusehat_submissions', 'manage_satusehat_mappings', 'manage_satusehat_settings'])
                <div>
                    <p class="menu-group-title pt-2">SATUSEHAT</p>
                    <div class="mt-1 space-y-1">
                        @canany(['view_satusehat_submissions', 'review_satusehat_submissions', 'send_satusehat_submissions'])
                            <a href="{{ route('satusehat.submissions.index') }}"
                               class="menu-subitem {{ request()->routeIs('satusehat.submissions.*') ? $linkActive : $linkIdle }}">Filter Pengiriman</a>
                        @endcanany
                        @can('manage_satusehat_mappings')
                            <a href="{{ route('satusehat.mappings.index') }}"
                               class="menu-subitem {{ request()->routeIs('satusehat.mappings.*') ? $linkActive : $linkIdle }}">Mapping Kode</a>
                        @endcan
                        @can('manage_satusehat_settings')
                            <a href="{{ route('satusehat.identifiers.index') }}"
                               class="menu-subitem {{ request()->routeIs('satusehat.identifiers.*') ? $linkActive : $linkIdle }}">Identifier IHS</a>
                        @endcan
                    </div>
                </div>
            @endcanany

            {{-- Sprint 23 Phase 23.5 — RME report viewers without clinical access still get their report links --}}
            @if ($user && $user->cannot('view_clinic_visits') && $user->cannot('manage_clinic_visits'))
                @canany(['view_rme_patient_reports', 'view_rme_payment_reports'])
                    <div class="pt-2">
                        <p class="menu-group-title">Laporan RME</p>
                        @can('view_rme_patient_reports')
                            <a href="{{ route('rme.reports.patients') }}"
                               class="menu-item {{ request()->routeIs('rme.reports.patients') ? 'menu-item-active' : 'menu-item-inactive' }}">
                                <span>Laporan Pasien RME</span>
                            </a>
                        @endcan
                        @can('view_rme_payment_reports')
                            <a href="{{ route('rme.reports.payments') }}"
                               class="menu-item {{ request()->routeIs('rme.reports.payments') ? 'menu-item-active' : 'menu-item-inactive' }}">
                                <span>Laporan Pembayaran RME</span>
                            </a>
                        @endcan
                        @can('view_doctor_performance_report')
                            <a href="{{ route('rme.reports.doctor-performance') }}"
                               class="menu-item {{ request()->routeIs('rme.reports.doctor-performance') ? 'menu-item-active' : 'menu-item-inactive' }}">
                                <span>Kinerja &amp; Pendapatan Dokter</span>
                            </a>
                        @endcan
                    </div>
                @endcanany
            @endif

            @if ($showLabGroup)
                <p class="menu-group-title pt-2">Laboratorium</p>
            @endif

            {{-- LAB-WORKFLOW-V2 Phase 8/9 — operational dashboard + SLA baseline --}}
            @canany(['view_lab_orders', 'manage_lab_orders'])
                <a href="{{ route('lab-workflow-dashboard.index') }}"
                   class="menu-item {{ request()->routeIs('lab-workflow-dashboard.*') ? 'menu-item-active' : 'menu-item-inactive' }}">
                    <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 12h4l3 8 4-16 3 8h4" />
                    </svg>
                    <span>Dasbor Operasional Lab</span>
                </a>
            @endcanany

            {{-- LAB-PROD-2 — Operational Analytics & KPI --}}
            @canany(['view_lab_operational_analytics', 'view_own_lab_operational_analytics', 'manage_lab_orders'])
                <a href="{{ route('lab-analytics.operational-kpi.index') }}"
                   class="menu-item {{ request()->routeIs('lab-analytics.operational-kpi.*') ? 'menu-item-active' : 'menu-item-inactive' }}">
                    <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 3v18h18M8 13v5m5-9v9m5-13v13" />
                    </svg>
                    <span>Analitik &amp; KPI Lab</span>
                </a>
            @endcanany

            {{-- LAB-PROD-3 — Technician Capacity Planning --}}
            @canany(['view_lab_technician_capacity', 'view_own_lab_technician_capacity', 'manage_lab_technician_capacity'])
                <a href="{{ route('lab-capacity-planning.index') }}"
                   class="menu-item {{ request()->routeIs('lab-capacity-planning.*') ? 'menu-item-active' : 'menu-item-inactive' }}">
                    <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6 0a3 3 0 10-2.4-4.8" />
                    </svg>
                    <span>Kapasitas &amp; Perencanaan Teknisi</span>
                </a>
            @endcanany

            @canany(['view_lab_orders', 'manage_lab_orders'])
                <a href="{{ route('lab-orders.index') }}"
                   class="menu-item {{ request()->routeIs('lab-orders.*') ? 'menu-item-active' : 'menu-item-inactive' }}">
                    <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12h6m-6 4h6M9 8h6M5 6h14a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2z" />
                    </svg>
                    <span>Order Lab</span>
                </a>
            @endcanany

            {{-- Sprint 21 Phase 21.3 — RME → Lab candidate queue (read-only) --}}
            @canany(['view_lab_orders', 'manage_lab_orders'])
                <a href="{{ route('lab-case-candidates.index') }}"
                   class="menu-item {{ request()->routeIs('lab-case-candidates.*') ? 'menu-item-active' : 'menu-item-inactive' }}">
                    <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                    </svg>
                    <span>Kandidat Lab RME</span>
                </a>
            @endcanany

            {{-- LAB-WORKFLOW-V2 Phase 2 — Cabang lab request workspace --}}
            @canany(['create_lab_branch_requests', 'manage_lab_orders'])
                <a href="{{ route('lab-workflow-requests.index') }}"
                   class="menu-item {{ request()->routeIs('lab-workflow-requests.*') ? 'menu-item-active' : 'menu-item-inactive' }}">
                    <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>Permintaan Lab Cabang</span>
                </a>
            @endcanany

            {{-- LAB-WORKFLOW-V2 Phase 2 — courier pickup queue --}}
            @canany(['manage_lab_pickups', 'manage_lab_orders'])
                <a href="{{ route('lab-pickup-tasks.index') }}"
                   class="menu-item {{ request()->routeIs('lab-pickup-tasks.*') ? 'menu-item-active' : 'menu-item-inactive' }}">
                    <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                    </svg>
                    <span>Pickup Lab</span>
                </a>
            @endcanany

            {{-- LAB-WORKFLOW-V2 Phase 3 — lab-side V2 pipeline hub --}}
            @canany(['view_lab_orders', 'manage_lab_orders'])
                <a href="{{ route('lab-v2-orders.index') }}"
                   class="menu-item {{ request()->routeIs('lab-v2-orders.*', 'lab-external-labs.*') ? 'menu-item-active' : 'menu-item-inactive' }}">
                    <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2" />
                    </svg>
                    <span>Pipeline Lab V2</span>
                </a>
            @endcanany

            {{-- LAB-WORKFLOW-V2 Phase 4 — delivery queue (lab -> branch) --}}
            @canany(['view_delivery', 'manage_delivery', 'manage_lab_orders'])
                <a href="{{ route('lab-delivery-tasks.index') }}"
                   class="menu-item {{ request()->routeIs('lab-delivery-tasks.*') ? 'menu-item-active' : 'menu-item-inactive' }}">
                    <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0" />
                    </svg>
                    <span>Pengiriman Lab V2</span>
                </a>
            @endcanany

            @canany(['view_production', 'manage_production'])
                <a href="{{ route('production.board') }}"
                   class="menu-item {{ request()->routeIs('production.*') ? 'menu-item-active' : 'menu-item-inactive' }}">
                    <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    <span>Produksi</span>
                </a>
            @endcanany

            @canany(['view_quality_control', 'manage_quality_control'])
                <a href="{{ route('quality-control.queue') }}"
                   class="menu-item {{ request()->routeIs('quality-control.*') ? 'menu-item-active' : 'menu-item-inactive' }}">
                    <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                    <span>QC</span>
                </a>
            @endcanany

            @role('Technician')
                @can('view_production')
                    <div class="pt-2">
                        <button type="button" @click="toggle('my-work')" class="{{ $groupToggle }}" :aria-expanded="isOpen('my-work')">
                            <span>Pekerjaan Saya</span>
                            <svg class="h-4 w-4 shrink-0 text-ink-muted transition-transform duration-150" :class="{ 'rotate-180': isOpen('my-work') }" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.94a.75.75 0 111.08 1.04l-4.24 4.5a.75.75 0 01-1.08 0l-4.24-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                            </svg>
                        </button>
                        <div data-sidebar-panel="my-work" x-show="isOpen('my-work')" class="mt-1 space-y-0.5 pl-8">
                            <a href="{{ route('production.board') }}"
                               class="menu-subitem {{ request()->routeIs('production.*') ? $linkActive : $linkIdle }}">Penugasan Saya</a>
                        </div>
                    </div>
                @endcan
            @endrole

            @role('Courier')
                <a href="{{ route('deliveries.index') }}"
                   class="menu-item {{ request()->routeIs('deliveries.*') ? 'menu-item-active' : 'menu-item-inactive' }}">
                    <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10m10 0h4m-4 0a2 2 0 01-2 2H5a2 2 0 01-2-2m12-2h2.586a1 1 0 01.707.293l2.414 2.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1" />
                    </svg>
                    <span>Pengiriman</span>
                </a>
            @else
                @canany(['view_delivery', 'manage_delivery'])
                    <a href="{{ route('deliveries.index') }}"
                       class="menu-item {{ request()->routeIs('deliveries.*') ? 'menu-item-active' : 'menu-item-inactive' }}">
                        <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10m10 0h4m-4 0a2 2 0 01-2 2H5a2 2 0 01-2-2m12-2h2.586a1 1 0 01.707.293l2.414 2.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1" />
                        </svg>
                        <span>Pengiriman</span>
                    </a>
                @endcanany
            @endrole

            @if ($showInventoryMasterDataGroup || $showInventoryOperationsGroup || $showInventoryReportsGroup || $showProcurementGroup)
                <p class="menu-group-title pt-2">Inventaris &amp; Pembelian</p>
            @endif

            @if ($showInventoryMasterDataGroup)
                <div class="pt-2">
                    <button type="button" @click="toggle('inventory-master-data')" class="{{ $groupToggle }}" :aria-expanded="isOpen('inventory-master-data')">
                        <span class="flex items-center gap-3">
                            <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
                            </svg>
                            <span>Master Data</span>
                        </span>
                        <svg class="h-4 w-4 shrink-0 text-ink-muted transition-transform duration-150" :class="{ 'rotate-180': isOpen('inventory-master-data') }" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.94a.75.75 0 111.08 1.04l-4.24 4.5a.75.75 0 01-1.08 0l-4.24-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                        </svg>
                    </button>
                    <div data-sidebar-panel="inventory-master-data" x-show="isOpen('inventory-master-data')" class="mt-1 space-y-0.5 pl-8">
                        @can('viewAny', \App\Modules\Inventory\Models\Product::class)
                            <a href="{{ route('inventory.products.index') }}"
                               class="menu-subitem {{ request()->routeIs('inventory.products.*') && ! request()->routeIs('inventory.products.adjust-in.*', 'inventory.products.adjust-out.*') ? $linkActive : $linkIdle }}">Produk</a>
                        @endcan
                        @can('viewAny', \App\Modules\Inventory\Models\ProductCategory::class)
                            <a href="{{ route('inventory.product-categories.index') }}"
                               class="menu-subitem {{ request()->routeIs('inventory.product-categories.*') ? $linkActive : $linkIdle }}">Kategori Produk</a>
                        @endcan
                        @can('viewAny', \App\Modules\Inventory\Models\ProductUnit::class)
                            <a href="{{ route('inventory.product-units.index') }}"
                               class="menu-subitem {{ request()->routeIs('inventory.product-units.*') ? $linkActive : $linkIdle }}">Satuan Produk</a>
                        @endcan
                        @can('viewAny', \App\Modules\Inventory\Models\Product::class)
                            <a href="{{ route('inventory.locations.index') }}"
                               class="menu-subitem {{ request()->routeIs('inventory.locations.*') ? $linkActive : $linkIdle }}">Lokasi Persediaan</a>
                            <a href="{{ route('inventory.suppliers.index') }}"
                               class="menu-subitem {{ request()->routeIs('inventory.suppliers.*') ? $linkActive : $linkIdle }}">Pemasok</a>
                        @endcan
                        @can('viewAny', \App\Modules\Inventory\Models\InventoryBatch::class)
                            <a href="{{ route('inventory.batches.index') }}"
                               class="menu-subitem {{ request()->routeIs('inventory.batches.*') ? $linkActive : $linkIdle }}">Batch & Lot</a>
                        @endcan
                    </div>
                </div>
            @endif

            @if ($showInventoryOperationsGroup)
                <div class="pt-2">
                    <button type="button" @click="toggle('inventory')" class="{{ $groupToggle }}" :aria-expanded="isOpen('inventory')">
                        <span class="flex items-center gap-3">
                            <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                            </svg>
                            <span>Operasional Stok</span>
                        </span>
                        <svg class="h-4 w-4 shrink-0 text-ink-muted transition-transform duration-150" :class="{ 'rotate-180': isOpen('inventory') }" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.94a.75.75 0 111.08 1.04l-4.24 4.5a.75.75 0 01-1.08 0l-4.24-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                        </svg>
                    </button>
                    <div data-sidebar-panel="inventory" x-show="isOpen('inventory')" class="mt-1 space-y-0.5 pl-8">
                        @can('viewAny', \App\Modules\Inventory\Models\Product::class)
                            <a href="{{ route('inventory.stock.index') }}"
                               class="menu-subitem {{ request()->routeIs('inventory.stock.*') ? $linkActive : $linkIdle }}">Stok Saat Ini</a>
                        @endcan
                        @can('viewAny', \App\Modules\Inventory\Models\StockTransfer::class)
                            <a href="{{ route('inventory.stock-transfers.index') }}"
                               class="menu-subitem {{ request()->routeIs('inventory.stock-transfers.*') ? $linkActive : $linkIdle }}">Transfer Stok</a>
                        @endcan
                        @can('viewAny', \App\Modules\Inventory\Models\StockOpname::class)
                            <a href="{{ route('inventory.stock-opnames.index') }}"
                               class="menu-subitem {{ request()->routeIs('inventory.stock-opnames.*') ? $linkActive : $linkIdle }}">Stok Opname</a>
                        @endcan
                        @can('viewAny', \App\Modules\Inventory\Models\InventoryBatchDisposalRequest::class)
                            <a href="{{ route('inventory.batch-disposal-requests.index') }}"
                               class="menu-subitem {{ request()->routeIs('inventory.batch-disposal-requests.*') ? $linkActive : $linkIdle }}">Disposal/Adjustment Batch</a>
                        @endcan
                        @can('viewAny', \App\Modules\Inventory\Models\LocationProductMinimum::class)
                            <a href="{{ route('inventory.location-minimums.index') }}"
                               class="menu-subitem {{ request()->routeIs('inventory.location-minimums.*') ? $linkActive : $linkIdle }}">Minimum Stok Ruangan</a>
                        @endcan
                    </div>
                </div>
            @endif

            @if ($showInventoryReportsGroup)
                <div class="pt-2">
                    <button type="button" @click="toggle('inventory-reports-analytics')" class="{{ $groupToggle }}" :aria-expanded="isOpen('inventory-reports-analytics')">
                        <span class="flex items-center gap-3">
                            <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                            </svg>
                            <span>Laporan & Analitik</span>
                        </span>
                        <svg class="h-4 w-4 shrink-0 text-ink-muted transition-transform duration-150" :class="{ 'rotate-180': isOpen('inventory-reports-analytics') }" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.94a.75.75 0 111.08 1.04l-4.24 4.5a.75.75 0 01-1.08 0l-4.24-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                        </svg>
                    </button>
                    <div data-sidebar-panel="inventory-reports-analytics" x-show="isOpen('inventory-reports-analytics')" class="mt-1 space-y-0.5 pl-8">
                        @can('viewAny', \App\Modules\Inventory\Models\Product::class)
                            @unless ($isAdminWarehouse)
                                <a href="{{ route('inventory.dashboard') }}"
                                   class="menu-subitem {{ request()->routeIs('inventory.dashboard') ? $linkActive : $linkIdle }}">Dashboard Inventory</a>
                            @endunless
                        @endcan
                        @can('viewAny', \App\Modules\Inventory\Models\InventoryMovement::class)
                            <a href="{{ route('inventory.reports.index') }}"
                               class="menu-subitem {{ request()->routeIs('inventory.reports.index', 'inventory.reports.export', 'inventory.reports.room-stock.*') && ! request()->routeIs('inventory.reports.batch-disposals.*', 'inventory.reports.batch-monthly-closing.*') ? $linkActive : $linkIdle }}">Laporan Inventory</a>
                            <a href="{{ route('inventory.reports.batch-disposals.index') }}"
                               class="menu-subitem {{ request()->routeIs('inventory.reports.batch-disposals.*') ? $linkActive : $linkIdle }}">Disposal & Adjustment Batch</a>
                            <a href="{{ route('inventory.reports.batch-monthly-closing.index') }}"
                               class="menu-subitem {{ request()->routeIs('inventory.reports.batch-monthly-closing.*') ? $linkActive : $linkIdle }}">Closing Bulanan Batch</a>
                            <a href="{{ route('inventory.reports.index', ['report_tab' => 'stock_card']) }}"
                               class="menu-subitem {{ request()->routeIs('inventory.reports.*') && $activeReportTab === 'stock_card' ? $linkActive : $linkIdle }}">Kartu Stok</a>
                            <a href="{{ route('inventory.reports.index', ['report_tab' => 'low_stock']) }}"
                               class="menu-subitem {{ request()->routeIs('inventory.reports.*') && $activeReportTab === 'low_stock' ? $linkActive : $linkIdle }}">Low Stock</a>
                            <a href="{{ route('inventory.reports.index', ['report_tab' => 'mutation']) }}"
                               class="menu-subitem {{ request()->routeIs('inventory.reports.*') && $activeReportTab === 'mutation' ? $linkActive : $linkIdle }}">Mutasi Stok</a>
                            <a href="{{ route('inventory.reports.index', ['report_tab' => 'valuation']) }}"
                               class="menu-subitem {{ request()->routeIs('inventory.reports.*') && $activeReportTab === 'valuation' ? $linkActive : $linkIdle }}">Nilai Persediaan</a>
                        @endcan
                        @can('viewAlerts', \App\Modules\Inventory\Models\InventoryMovement::class)
                            <a href="{{ route('inventory.alerts.index') }}"
                               class="menu-subitem {{ request()->routeIs('inventory.alerts.*') ? $linkActive : $linkIdle }}">Peringatan Stok</a>
                        @endcan
                        @can('viewAnalytics', \App\Modules\Inventory\Models\InventoryMovement::class)
                            <a href="{{ route('inventory.analytics.index') }}"
                               class="menu-subitem {{ request()->routeIs('inventory.analytics.*') ? $linkActive : $linkIdle }}">Analitik Persediaan</a>
                        @endcan
                        @can('viewExecutiveDashboard', \App\Modules\Inventory\Models\InventoryMovement::class)
                            <a href="{{ route('inventory.executive-dashboard') }}"
                               class="menu-subitem {{ request()->routeIs('inventory.executive-dashboard') ? $linkActive : $linkIdle }}">Dasbor Eksekutif</a>
                        @endcan
                        @can('viewAny', \App\Modules\Inventory\Models\InventoryActivityLog::class)
                            <a href="{{ route('inventory.activity-logs.index') }}"
                               class="menu-subitem {{ request()->routeIs('inventory.activity-logs.*') ? $linkActive : $linkIdle }}">Log Aktivitas</a>
                        @endcan
                    </div>
                </div>
            @endif

            @if ($showProcurementGroup)
                <div class="pt-2">
                    <button type="button" @click="toggle('procurement')" class="{{ $groupToggle }}" :aria-expanded="isOpen('procurement')">
                        <span class="flex items-center gap-3">
                            <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                            <span>Pembelian</span>
                        </span>
                        <svg class="h-4 w-4 shrink-0 text-ink-muted transition-transform duration-150" :class="{ 'rotate-180': isOpen('procurement') }" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.94a.75.75 0 111.08 1.04l-4.24 4.5a.75.75 0 01-1.08 0l-4.24-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                        </svg>
                    </button>
                    <div data-sidebar-panel="procurement" x-show="isOpen('procurement')" class="mt-1 space-y-0.5 pl-8">
                        @can('viewAny', \App\Modules\Inventory\Models\PurchaseRequest::class)
                            <a href="{{ route('inventory.purchase-requests.workflow') }}"
                               class="menu-subitem {{ request()->routeIs('inventory.purchase-requests.workflow') ? $linkActive : $linkIdle }}">Alur PR Cabang</a>
                            <a href="{{ route('inventory.purchase-requests.index') }}"
                               class="menu-subitem {{ request()->routeIs('inventory.purchase-requests.index') ? $linkActive : $linkIdle }}">Permintaan Pembelian</a>
                        @endcan
                        @can('viewAny', \App\Modules\Inventory\Models\PurchaseOrder::class)
                            <a href="{{ route('inventory.purchase-orders.index') }}"
                               class="menu-subitem {{ request()->routeIs('inventory.purchase-orders.*') ? $linkActive : $linkIdle }}">Pesanan Pembelian</a>
                        @endcan
                        @can('viewAny', \App\Modules\Inventory\Models\GoodsReceipt::class)
                            <a href="{{ route('inventory.goods-receipts.index') }}"
                               class="menu-subitem {{ request()->routeIs('inventory.goods-receipts.*') ? $linkActive : $linkIdle }}">Penerimaan Barang</a>
                        @endcan
                    </div>
                </div>
            @endif

            @if ($showFinanceReportingGroup)
                <p class="menu-group-title pt-2">Keuangan &amp; Laporan</p>
            @endif

            @canany(['view_invoice', 'manage_invoice', 'view_payment', 'manage_payment', 'view_payment_report', 'manage_report'])
                <div class="pt-2">
                    <button type="button" @click="toggle('finance')" class="{{ $groupToggle }}" :aria-expanded="isOpen('finance')">
                        <span class="flex items-center gap-3">
                            <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span>Keuangan</span>
                        </span>
                        <svg class="h-4 w-4 shrink-0 text-ink-muted transition-transform duration-150" :class="{ 'rotate-180': isOpen('finance') }" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.94a.75.75 0 111.08 1.04l-4.24 4.5a.75.75 0 01-1.08 0l-4.24-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                        </svg>
                    </button>
                    <div data-sidebar-panel="finance" x-show="isOpen('finance')" class="mt-1 space-y-0.5 pl-8">
                        @canany(['view_invoice', 'manage_invoice'])
                            <a href="{{ route('invoices.index') }}"
                               class="menu-subitem {{ request()->routeIs('invoices.*') ? $linkActive : $linkIdle }}">Invoice</a>
                        @endcanany
                        @canany(['view_payment_report', 'manage_report'])
                            <a href="{{ route('reports.payments') }}"
                               class="menu-subitem {{ request()->routeIs('reports.payments') ? $linkActive : $linkIdle }}">Pembayaran</a>
                        @endcanany
                    </div>
                </div>
            @endcanany

            @canany(['view_dashboard', 'view_order_report', 'view_production_report', 'view_qc_report', 'view_delivery_report', 'view_invoice_report', 'view_payment_report', 'manage_report'])
                <div class="pt-2">
                    <button type="button" @click="toggle('reporting')" class="{{ $groupToggle }}" :aria-expanded="isOpen('reporting')">
                        <span class="flex items-center gap-3">
                            <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                            </svg>
                            <span>Laporan</span>
                        </span>
                        <svg class="h-4 w-4 shrink-0 text-ink-muted transition-transform duration-150" :class="{ 'rotate-180': isOpen('reporting') }" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.94a.75.75 0 111.08 1.04l-4.24 4.5a.75.75 0 01-1.08 0l-4.24-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                        </svg>
                    </button>
                    <div data-sidebar-panel="reporting" x-show="isOpen('reporting')" class="mt-1 space-y-0.5 pl-8">
                        @php($reportActive = fn ($name) => request()->routeIs($name) ? $linkActive : $linkIdle)
                        @can('reporting.dashboard')
                            <a href="{{ route('reports.dashboard') }}" class="menu-subitem {{ $reportActive('reports.dashboard') }}">Dashboard Lab</a>
                        @endcan
                        @can('reporting.orders')
                            <a href="{{ route('reports.orders') }}" class="menu-subitem {{ $reportActive('reports.orders') }}">Order</a>
                        @endcan
                        @can('reporting.production')
                            <a href="{{ route('reports.production') }}" class="menu-subitem {{ $reportActive('reports.production') }}">Produksi</a>
                        @endcan
                        @can('reporting.qc')
                            <a href="{{ route('reports.qc') }}" class="menu-subitem {{ $reportActive('reports.qc') }}">QC</a>
                        @endcan
                        @can('reporting.delivery')
                            <a href="{{ route('reports.delivery') }}" class="menu-subitem {{ $reportActive('reports.delivery') }}">Pengiriman</a>
                        @endcan
                        @can('reporting.invoices')
                            <a href="{{ route('reports.invoices') }}" class="menu-subitem {{ $reportActive('reports.invoices') }}">Invoice</a>
                        @endcan
                        @can('reporting.payments')
                            <a href="{{ route('reports.payments') }}" class="menu-subitem {{ $reportActive('reports.payments') }}">Pembayaran</a>
                        @endcan
                        @can('reporting.invoices')
                            <a href="{{ route('reports.outstanding') }}" class="menu-subitem {{ $reportActive('reports.outstanding') }}">Tertunggak</a>
                            <a href="{{ route('reports.revenue') }}" class="menu-subitem {{ $reportActive('reports.revenue') }}">Pendapatan</a>
                        @endcan
                    </div>
                </div>
            @endcanany

            @if ($showAdminGroup)
                <p class="menu-group-title pt-2">Administrasi Sistem</p>
            @endif

            @canany(['manage doctors', 'manage patients', 'manage lab services', 'manage technicians', 'view_clinic_master_data', 'manage_clinic_master_data', 'view_branch_master_data', 'manage_branch_master_data'])
                @unless($user?->hasRole('Admin Klinik'))
                <div class="pt-2">
                    <button type="button" @click="toggle('master-data')" class="{{ $groupToggle }}" :aria-expanded="isOpen('master-data')">
                        <span class="flex items-center gap-3">
                            <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 7v10c0 1.105 3.582 2 8 2s8-.895 8-2V7M4 7c0 1.105 3.582 2 8 2s8-.895 8-2M4 7c0-1.105 3.582-2 8-2s8 .895 8 2m0 5c0 1.105-3.582 2-8 2s-8-.895-8-2" />
                            </svg>
                            <span>Master Data RME</span>
                        </span>
                        <svg class="h-4 w-4 shrink-0 text-ink-muted transition-transform duration-150" :class="{ 'rotate-180': isOpen('master-data') }" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.94a.75.75 0 111.08 1.04l-4.24 4.5a.75.75 0 01-1.08 0l-4.24-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                        </svg>
                    </button>
                    <div data-sidebar-panel="master-data" x-show="isOpen('master-data')" class="mt-1 space-y-0.5 pl-8">
                        @can('manage doctors')
                            <a href="{{ route('settings.doctors.index') }}"
                               class="menu-subitem {{ request()->routeIs('settings.doctors.*') ? $linkActive : $linkIdle }}">Dokter</a>
                        @endcan
                        @can('manage patients')
                            <a href="{{ route('settings.patients.index') }}"
                               class="menu-subitem {{ request()->routeIs('settings.patients.*') && ! request()->routeIs('settings.patients.import.*') ? $linkActive : $linkIdle }}">Pasien</a>
                            <a href="{{ route('settings.patients.import.index') }}"
                               class="menu-subitem {{ request()->routeIs('settings.patients.import.*') ? $linkActive : $linkIdle }}">Impor Pasien Legacy</a>
                        @endcan
                        @can('manage lab services')
                            <a href="{{ route('settings.lab-services.index') }}"
                               class="menu-subitem {{ request()->routeIs('settings.lab-services.*') ? $linkActive : $linkIdle }}">Layanan Lab</a>
                        @endcan
                        @can('manage technicians')
                            <a href="{{ route('settings.technicians.index') }}"
                               class="menu-subitem {{ request()->routeIs('settings.technicians.*') ? $linkActive : $linkIdle }}">Teknisi</a>
                        @endcan
                        @canany(['view_clinic_master_data', 'manage_clinic_master_data'])
                            <a href="{{ route('settings.clinic-rooms.index') }}"
                               class="menu-subitem {{ request()->routeIs('settings.clinic-rooms.*') ? $linkActive : $linkIdle }}">Master Ruangan</a>
                            <a href="{{ route('settings.treatment-categories.index') }}"
                               class="menu-subitem {{ request()->routeIs('settings.treatment-categories.*') ? $linkActive : $linkIdle }}">Master Kategori Perawatan</a>
                            <a href="{{ route('settings.treatments.index') }}"
                               class="menu-subitem {{ request()->routeIs('settings.treatments.*') ? $linkActive : $linkIdle }}">Master Perawatan</a>
                            <a href="{{ route('settings.tariffs.index') }}"
                               class="menu-subitem {{ request()->routeIs('settings.tariffs.*') ? $linkActive : $linkIdle }}">Master Tarif</a>
                            <a href="{{ route('settings.payment-methods.index') }}"
                               class="menu-subitem {{ request()->routeIs('settings.payment-methods.*') ? $linkActive : $linkIdle }}">Master Metode Pembayaran</a>
                            <a href="{{ route('settings.wa-reminder-templates.index') }}"
                               class="menu-subitem {{ request()->routeIs('settings.wa-reminder-templates.*') ? $linkActive : $linkIdle }}">Template Reminder WA</a>
                        @endcanany
                        @canany(['view_branch_master_data', 'manage_branch_master_data'])
                            <a href="{{ route('settings.branches.index') }}"
                               class="menu-subitem {{ request()->routeIs('settings.branches.*') ? $linkActive : $linkIdle }}">Master Cabang RME</a>
                        @endcanany
                    </div>
                </div>
                @endunless
            @endcanany

            @canany(['manage users', 'manage roles', 'manage permissions'])
                <div class="pt-2">
                    <button type="button" @click="toggle('settings')" class="{{ $groupToggle }}" :aria-expanded="isOpen('settings')">
                        <span class="flex items-center gap-3">
                            <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            <span>Pengaturan</span>
                        </span>
                        <svg class="h-4 w-4 shrink-0 text-ink-muted transition-transform duration-150" :class="{ 'rotate-180': isOpen('settings') }" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.94a.75.75 0 111.08 1.04l-4.24 4.5a.75.75 0 01-1.08 0l-4.24-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                        </svg>
                    </button>
                    <div data-sidebar-panel="settings" x-show="isOpen('settings')" class="mt-1 space-y-0.5 pl-8">
                        @can('manage users')
                            <a href="{{ route('settings.users.index') }}"
                               class="menu-subitem {{ request()->routeIs('settings.users.*') ? $linkActive : $linkIdle }}">Pengguna</a>
                        @endcan
                        @can('manage roles')
                            <a href="{{ route('settings.roles.index') }}"
                               class="menu-subitem {{ request()->routeIs('settings.roles.*') ? $linkActive : $linkIdle }}">Role</a>
                        @endcan
                        @can('manage permissions')
                            <a href="{{ route('settings.permissions.index') }}"
                               class="menu-subitem {{ request()->routeIs('settings.permissions.*') ? $linkActive : $linkIdle }}">Permission</a>
                        @endcan
                    </div>
                </div>
            @endcanany

            {{-- ENT-7 — Developer Assistance Console (read-only, audited) --}}
            @if (config('developer_console.enabled', true))
                @can('view_developer_console')
                    <a href="{{ route('developer-console.index') }}"
                       class="menu-item {{ request()->routeIs('developer-console.*') ? 'menu-item-active' : 'menu-item-inactive' }}">
                        <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M8 9l3 3-3 3m5 0h3M4 5h16a1 1 0 011 1v12a1 1 0 01-1 1H4a1 1 0 01-1-1V6a1 1 0 011-1z" />
                        </svg>
                        <span>Developer Console</span>
                    </a>
                @endcan
            @endif
        </div>

        <script>
            (function () {
                var routeOpen = @json($sidebarRouteOpen);
                var storageKey = 'adlms-sidebar-groups';
                var stored = {};

                try {
                    stored = JSON.parse(localStorage.getItem(storageKey) || '{}');
                } catch (e) {
                    stored = {};
                }

                document.querySelectorAll('[data-sidebar-panel]').forEach(function (el) {
                    var group = el.getAttribute('data-sidebar-panel');
                    var open = !!routeOpen[group] || !!stored[group];
                    el.style.display = open ? '' : 'none';
                });
            })();
        </script>
    </nav>

</aside>
