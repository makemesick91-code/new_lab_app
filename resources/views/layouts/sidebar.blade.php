{{--
    Base dashboard sidebar (Sprint 0 — Foundation).

    Visibility is gated using Spatie Permission @can / @canany and @role Blade directives.
    Roles: Super Admin, Admin Lab, Technician, Quality Control, Courier, Finance, Doctor.

    Collapsible groups persist open/closed state in localStorage (adlms-sidebar-groups).
--}}
@php
    $user = auth()->user();
    $showProcurementGroup = $user && (
        $user->can('viewAny', \App\Modules\Inventory\Models\PurchaseRequest::class)
        || $user->can('viewAny', \App\Modules\Inventory\Models\PurchaseOrder::class)
        || $user->can('viewAny', \App\Modules\Inventory\Models\GoodsReceipt::class)
    );

    $sidebarRouteOpen = [
        'settings' => request()->routeIs('settings.users.*', 'settings.roles.*', 'settings.permissions.*'),
        'master-data' => request()->routeIs(
            'settings.clinics.*',
            'settings.doctors.*',
            'settings.patients.*',
            'settings.lab-services.*',
            'settings.technicians.*'
        ),
        'operational' => request()->routeIs('lab-orders.*', 'production.*', 'quality-control.*'),
        'my-work' => request()->routeIs('production.*'),
        'delivery' => request()->routeIs('deliveries.*'),
        'inventory' => request()->routeIs('inventory.*')
            && ! request()->routeIs(
                'inventory.purchase-requests.*',
                'inventory.purchase-orders.*',
                'inventory.goods-receipts.*'
            ),
        'procurement' => request()->routeIs(
            'inventory.purchase-requests.*',
            'inventory.purchase-orders.*',
            'inventory.goods-receipts.*'
        ),
        'finance' => request()->routeIs('invoices.*'),
        'reporting' => request()->routeIs('reports.*'),
    ];

    $linkActive = 'bg-gray-100 text-gray-900 font-medium';
    $linkIdle = 'text-gray-600 hover:bg-gray-50 hover:text-gray-900';
    $groupTitle = 'text-xs font-bold uppercase tracking-wider text-gray-700';
    $groupToggle = 'flex w-full items-center justify-between rounded-md px-3 py-2 ' . $groupTitle . ' hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-1';
@endphp

<aside class="w-64 shrink-0 bg-white border-r border-gray-200 min-h-[calc(100vh-4rem)]">
    <nav class="p-4 space-y-0.5 text-sm" x-data="adlmsSidebar(@js($sidebarRouteOpen))">

        {{-- Dashboard: always visible, not collapsible --}}
        <a href="{{ route('dashboard') }}"
           class="flex items-center px-3 py-2 rounded-md font-medium {{ request()->routeIs('dashboard') ? $linkActive : $linkIdle }}">
            Dasbor
        </a>

        {{-- Settings: each item appears only if the user holds the matching permission (TASK-0106). --}}
        @canany(['manage users', 'manage roles', 'manage permissions'])
            <div class="pt-2">
                <button type="button"
                        @click="toggle('settings')"
                        class="{{ $groupToggle }}"
                        :aria-expanded="isOpen('settings')">
                    <span>Pengaturan</span>
                    <svg class="h-4 w-4 shrink-0 text-gray-500 transition-transform duration-150"
                         :class="{ 'rotate-180': isOpen('settings') }"
                         xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.94a.75.75 0 111.08 1.04l-4.24 4.5a.75.75 0 01-1.08 0l-4.24-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                    </svg>
                </button>
                <div data-sidebar-panel="settings" x-show="isOpen('settings')" class="mt-0.5 space-y-0.5 pl-1">
                    @can('manage users')
                        <a href="{{ route('settings.users.index') }}"
                           class="block px-3 py-2 rounded-md {{ request()->routeIs('settings.users.*') ? $linkActive : $linkIdle }}">Pengguna</a>
                    @endcan
                    @can('manage roles')
                        <a href="{{ route('settings.roles.index') }}"
                           class="block px-3 py-2 rounded-md {{ request()->routeIs('settings.roles.*') ? $linkActive : $linkIdle }}">Role</a>
                    @endcan
                    @can('manage permissions')
                        <a href="{{ route('settings.permissions.index') }}"
                           class="block px-3 py-2 rounded-md {{ request()->routeIs('settings.permissions.*') ? $linkActive : $linkIdle }}">Permission</a>
                    @endcan
                </div>
            </div>
        @endcanany

        {{-- Master Data: each link appears only with the matching permission (TASK-0207). --}}
        @canany(['manage clinics', 'manage doctors', 'manage patients', 'manage lab services', 'manage technicians'])
            <div class="pt-2">
                <button type="button"
                        @click="toggle('master-data')"
                        class="{{ $groupToggle }}"
                        :aria-expanded="isOpen('master-data')">
                    <span>Master Data</span>
                    <svg class="h-4 w-4 shrink-0 text-gray-500 transition-transform duration-150"
                         :class="{ 'rotate-180': isOpen('master-data') }"
                         xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.94a.75.75 0 111.08 1.04l-4.24 4.5a.75.75 0 01-1.08 0l-4.24-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                    </svg>
                </button>
                <div data-sidebar-panel="master-data" x-show="isOpen('master-data')" class="mt-0.5 space-y-0.5 pl-1">
                    @can('manage clinics')
                        <a href="{{ route('settings.clinics.index') }}"
                           class="block px-3 py-2 rounded-md {{ request()->routeIs('settings.clinics.*') ? $linkActive : $linkIdle }}">Klinik</a>
                    @endcan
                    @can('manage doctors')
                        <a href="{{ route('settings.doctors.index') }}"
                           class="block px-3 py-2 rounded-md {{ request()->routeIs('settings.doctors.*') ? $linkActive : $linkIdle }}">Dokter</a>
                    @endcan
                    @can('manage patients')
                        <a href="{{ route('settings.patients.index') }}"
                           class="block px-3 py-2 rounded-md {{ request()->routeIs('settings.patients.*') ? $linkActive : $linkIdle }}">Pasien</a>
                    @endcan
                    @can('manage lab services')
                        <a href="{{ route('settings.lab-services.index') }}"
                           class="block px-3 py-2 rounded-md {{ request()->routeIs('settings.lab-services.*') ? $linkActive : $linkIdle }}">Layanan Lab</a>
                    @endcan
                    @can('manage technicians')
                        <a href="{{ route('settings.technicians.index') }}"
                           class="block px-3 py-2 rounded-md {{ request()->routeIs('settings.technicians.*') ? $linkActive : $linkIdle }}">Teknisi</a>
                    @endcan
                </div>
            </div>
        @endcanany

        @canany(['view_lab_orders', 'manage_lab_orders', 'view_production', 'manage_production', 'view_quality_control', 'manage_quality_control'])
            <div class="pt-2">
                <button type="button"
                        @click="toggle('operational')"
                        class="{{ $groupToggle }}"
                        :aria-expanded="isOpen('operational')">
                    <span>Operasional</span>
                    <svg class="h-4 w-4 shrink-0 text-gray-500 transition-transform duration-150"
                         :class="{ 'rotate-180': isOpen('operational') }"
                         xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.94a.75.75 0 111.08 1.04l-4.24 4.5a.75.75 0 01-1.08 0l-4.24-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                    </svg>
                </button>
                <div data-sidebar-panel="operational" x-show="isOpen('operational')" class="mt-0.5 space-y-0.5 pl-1">
                    @canany(['view_lab_orders', 'manage_lab_orders'])
                        <a href="{{ route('lab-orders.index') }}"
                           class="block px-3 py-2 rounded-md {{ request()->routeIs('lab-orders.*') ? $linkActive : $linkIdle }}">Order Lab</a>
                    @endcanany
                    @canany(['view_production', 'manage_production'])
                        <a href="{{ route('production.board') }}"
                           class="block px-3 py-2 rounded-md {{ request()->routeIs('production.*') ? $linkActive : $linkIdle }}">Produksi</a>
                    @endcanany
                    @canany(['view_quality_control', 'manage_quality_control'])
                        <a href="{{ route('quality-control.queue') }}"
                           class="block px-3 py-2 rounded-md {{ request()->routeIs('quality-control.*') ? $linkActive : $linkIdle }}">QC</a>
                    @endcanany
                </div>
            </div>
        @endcanany

        @role('Technician')
            @can('view_production')
                <div class="pt-2">
                    <button type="button"
                            @click="toggle('my-work')"
                            class="{{ $groupToggle }}"
                            :aria-expanded="isOpen('my-work')">
                        <span>Pekerjaan Saya</span>
                        <svg class="h-4 w-4 shrink-0 text-gray-500 transition-transform duration-150"
                             :class="{ 'rotate-180': isOpen('my-work') }"
                             xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.94a.75.75 0 111.08 1.04l-4.24 4.5a.75.75 0 01-1.08 0l-4.24-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                        </svg>
                    </button>
                    <div data-sidebar-panel="my-work" x-show="isOpen('my-work')" class="mt-0.5 space-y-0.5 pl-1">
                        <a href="{{ route('production.board') }}"
                           class="block px-3 py-2 rounded-md {{ request()->routeIs('production.*') ? $linkActive : $linkIdle }}">Penugasan Saya</a>
                    </div>
                </div>
            @endcan
        @endrole

        @role('Courier')
            <div class="pt-2">
                <button type="button"
                        @click="toggle('delivery')"
                        class="{{ $groupToggle }}"
                        :aria-expanded="isOpen('delivery')">
                    <span>Pengiriman</span>
                    <svg class="h-4 w-4 shrink-0 text-gray-500 transition-transform duration-150"
                         :class="{ 'rotate-180': isOpen('delivery') }"
                         xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.94a.75.75 0 111.08 1.04l-4.24 4.5a.75.75 0 01-1.08 0l-4.24-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                    </svg>
                </button>
                <div data-sidebar-panel="delivery" x-show="isOpen('delivery')" class="mt-0.5 space-y-0.5 pl-1">
                    <a href="{{ route('deliveries.index') }}"
                       class="block px-3 py-2 rounded-md {{ request()->routeIs('deliveries.*') ? $linkActive : $linkIdle }}">Pengiriman Saya</a>
                </div>
            </div>
        @else
            @canany(['view_delivery', 'manage_delivery'])
                <div class="pt-2">
                    <button type="button"
                            @click="toggle('delivery')"
                            class="{{ $groupToggle }}"
                            :aria-expanded="isOpen('delivery')">
                        <span>Pengiriman</span>
                        <svg class="h-4 w-4 shrink-0 text-gray-500 transition-transform duration-150"
                             :class="{ 'rotate-180': isOpen('delivery') }"
                             xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.94a.75.75 0 111.08 1.04l-4.24 4.5a.75.75 0 01-1.08 0l-4.24-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                        </svg>
                    </button>
                    <div data-sidebar-panel="delivery" x-show="isOpen('delivery')" class="mt-0.5 space-y-0.5 pl-1">
                        <a href="{{ route('deliveries.index') }}"
                           class="block px-3 py-2 rounded-md {{ request()->routeIs('deliveries.*') ? $linkActive : $linkIdle }}">Pengiriman</a>
                    </div>
                </div>
            @endcanany
        @endrole

        @canany(['view_inventory', 'manage_inventory', 'manage master data'])
            <div class="pt-2">
                <button type="button"
                        @click="toggle('inventory')"
                        class="{{ $groupToggle }}"
                        :aria-expanded="isOpen('inventory')">
                    <span>Persediaan</span>
                    <svg class="h-4 w-4 shrink-0 text-gray-500 transition-transform duration-150"
                         :class="{ 'rotate-180': isOpen('inventory') }"
                         xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.94a.75.75 0 111.08 1.04l-4.24 4.5a.75.75 0 01-1.08 0l-4.24-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                    </svg>
                </button>
                <div data-sidebar-panel="inventory" x-show="isOpen('inventory')" class="mt-0.5 space-y-0.5 pl-1">
                    <a href="{{ route('inventory.dashboard') }}"
                       class="block px-3 py-2 rounded-md {{ request()->routeIs('inventory.dashboard') ? $linkActive : $linkIdle }}">Dasbor</a>
                    <a href="{{ route('inventory.products.index') }}"
                       class="block px-3 py-2 rounded-md {{ request()->routeIs('inventory.products.*') ? $linkActive : $linkIdle }}">Produk</a>
                    <a href="{{ route('inventory.locations.index') }}"
                       class="block px-3 py-2 rounded-md {{ request()->routeIs('inventory.locations.*') ? $linkActive : $linkIdle }}">Lokasi Persediaan</a>
                    <a href="{{ route('inventory.suppliers.index') }}"
                       class="block px-3 py-2 rounded-md {{ request()->routeIs('inventory.suppliers.*') ? $linkActive : $linkIdle }}">Pemasok</a>
                    <a href="{{ route('inventory.stock.index') }}"
                       class="block px-3 py-2 rounded-md {{ request()->routeIs('inventory.stock.*') ? $linkActive : $linkIdle }}">Stok</a>
                    @can('viewAny', \App\Modules\Inventory\Models\StockOpname::class)
                        <a href="{{ route('inventory.stock-opnames.index') }}"
                           class="block px-3 py-2 rounded-md {{ request()->routeIs('inventory.stock-opnames.*') ? $linkActive : $linkIdle }}">Stok Opname</a>
                    @endcan
                    @can('viewAny', \App\Modules\Inventory\Models\InventoryBatch::class)
                        <a href="{{ route('inventory.batches.index') }}"
                           class="block px-3 py-2 rounded-md {{ request()->routeIs('inventory.batches.*') ? $linkActive : $linkIdle }}">Batch & Lot</a>
                    @endcan
                    @can('viewAny', \App\Modules\Inventory\Models\InventoryMovement::class)
                        <a href="{{ route('inventory.alerts.index') }}"
                           class="block px-3 py-2 rounded-md {{ request()->routeIs('inventory.alerts.*') ? $linkActive : $linkIdle }}">Peringatan Stok</a>
                        <a href="{{ route('inventory.analytics.index') }}"
                           class="block px-3 py-2 rounded-md {{ request()->routeIs('inventory.analytics.*') ? $linkActive : $linkIdle }}">Analitik Persediaan</a>
                    @endcan
                    @can('viewAny', \App\Modules\Inventory\Models\StockTransfer::class)
                        <a href="{{ route('inventory.stock-transfers.index') }}"
                           class="block px-3 py-2 rounded-md {{ request()->routeIs('inventory.stock-transfers.*') ? $linkActive : $linkIdle }}">Transfer Stok</a>
                    @endcan
                </div>
            </div>
        @endcanany

        @if ($showProcurementGroup)
            <div class="pt-2">
                <button type="button"
                        @click="toggle('procurement')"
                        class="{{ $groupToggle }}"
                        :aria-expanded="isOpen('procurement')">
                    <span>Pengadaan</span>
                    <svg class="h-4 w-4 shrink-0 text-gray-500 transition-transform duration-150"
                         :class="{ 'rotate-180': isOpen('procurement') }"
                         xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.94a.75.75 0 111.08 1.04l-4.24 4.5a.75.75 0 01-1.08 0l-4.24-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                    </svg>
                </button>
                <div data-sidebar-panel="procurement" x-show="isOpen('procurement')" class="mt-0.5 space-y-0.5 pl-1">
                    @can('viewAny', \App\Modules\Inventory\Models\PurchaseRequest::class)
                        <a href="{{ route('inventory.purchase-requests.index') }}"
                           class="block px-3 py-2 rounded-md {{ request()->routeIs('inventory.purchase-requests.*') ? $linkActive : $linkIdle }}">Permintaan Pembelian</a>
                    @endcan
                    @can('viewAny', \App\Modules\Inventory\Models\PurchaseOrder::class)
                        <a href="{{ route('inventory.purchase-orders.index') }}"
                           class="block px-3 py-2 rounded-md {{ request()->routeIs('inventory.purchase-orders.*') ? $linkActive : $linkIdle }}">Pesanan Pembelian</a>
                    @endcan
                    @can('viewAny', \App\Modules\Inventory\Models\GoodsReceipt::class)
                        <a href="{{ route('inventory.goods-receipts.index') }}"
                           class="block px-3 py-2 rounded-md {{ request()->routeIs('inventory.goods-receipts.*') ? $linkActive : $linkIdle }}">Penerimaan Barang</a>
                    @endcan
                </div>
            </div>
        @endif

        @canany(['view_invoice', 'manage_invoice', 'view_payment', 'manage_payment'])
            <div class="pt-2">
                <button type="button"
                        @click="toggle('finance')"
                        class="{{ $groupToggle }}"
                        :aria-expanded="isOpen('finance')">
                    <span>Keuangan</span>
                    <svg class="h-4 w-4 shrink-0 text-gray-500 transition-transform duration-150"
                         :class="{ 'rotate-180': isOpen('finance') }"
                         xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.94a.75.75 0 111.08 1.04l-4.24 4.5a.75.75 0 01-1.08 0l-4.24-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                    </svg>
                </button>
                <div data-sidebar-panel="finance" x-show="isOpen('finance')" class="mt-0.5 space-y-0.5 pl-1">
                    @canany(['view_invoice', 'manage_invoice'])
                        <a href="{{ route('invoices.index') }}"
                           class="block px-3 py-2 rounded-md {{ request()->routeIs('invoices.*') ? $linkActive : $linkIdle }}">Invoice</a>
                    @endcanany
                </div>
            </div>
        @endcanany

        {{-- Reports (Sprint 8) — permission-aware menu. --}}
        @canany(['view_dashboard', 'view_order_report', 'view_production_report', 'view_qc_report', 'view_delivery_report', 'view_invoice_report', 'view_payment_report', 'manage_report'])
            <div class="pt-2">
                <button type="button"
                        @click="toggle('reporting')"
                        class="{{ $groupToggle }}"
                        :aria-expanded="isOpen('reporting')">
                    <span>Laporan</span>
                    <svg class="h-4 w-4 shrink-0 text-gray-500 transition-transform duration-150"
                         :class="{ 'rotate-180': isOpen('reporting') }"
                         xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.94a.75.75 0 111.08 1.04l-4.24 4.5a.75.75 0 01-1.08 0l-4.24-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                    </svg>
                </button>
                <div data-sidebar-panel="reporting" x-show="isOpen('reporting')" class="mt-0.5 space-y-0.5 pl-1">
                    @php($reportActive = fn ($name) => request()->routeIs($name) ? $linkActive : $linkIdle)
                    @can('reporting.dashboard')
                        <a href="{{ route('reports.dashboard') }}" class="block px-3 py-2 rounded-md {{ $reportActive('reports.dashboard') }}">Dasbor</a>
                    @endcan
                    @can('reporting.orders')
                        <a href="{{ route('reports.orders') }}" class="block px-3 py-2 rounded-md {{ $reportActive('reports.orders') }}">Order</a>
                    @endcan
                    @can('reporting.production')
                        <a href="{{ route('reports.production') }}" class="block px-3 py-2 rounded-md {{ $reportActive('reports.production') }}">Produksi</a>
                    @endcan
                    @can('reporting.qc')
                        <a href="{{ route('reports.qc') }}" class="block px-3 py-2 rounded-md {{ $reportActive('reports.qc') }}">QC</a>
                    @endcan
                    @can('reporting.delivery')
                        <a href="{{ route('reports.delivery') }}" class="block px-3 py-2 rounded-md {{ $reportActive('reports.delivery') }}">Pengiriman</a>
                    @endcan
                    @can('reporting.invoices')
                        <a href="{{ route('reports.invoices') }}" class="block px-3 py-2 rounded-md {{ $reportActive('reports.invoices') }}">Invoice</a>
                    @endcan
                    @can('reporting.payments')
                        <a href="{{ route('reports.payments') }}" class="block px-3 py-2 rounded-md {{ $reportActive('reports.payments') }}">Pembayaran</a>
                    @endcan
                    @can('reporting.invoices')
                        <a href="{{ route('reports.outstanding') }}" class="block px-3 py-2 rounded-md {{ $reportActive('reports.outstanding') }}">Tertunggak</a>
                        <a href="{{ route('reports.revenue') }}" class="block px-3 py-2 rounded-md {{ $reportActive('reports.revenue') }}">Pendapatan</a>
                    @endcan
                </div>
            </div>
        @endcanany

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
