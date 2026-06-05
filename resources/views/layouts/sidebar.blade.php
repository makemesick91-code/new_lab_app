{{--
    Base dashboard sidebar (Sprint 0 — Foundation).

    Menu items are ROLE-BASED PLACEHOLDERS only. All links point to "#" because
    the actual CRUD / feature pages are delivered in later sprints
    (Sprint 1: User & Access, Sprint 2: Master Data, Sprint 3+: Lab Order, etc.).

    Visibility is gated using Spatie Permission's @role Blade directive.
    Roles: Super Admin, Admin Lab, Technician, Quality Control, Courier, Finance, Doctor.
--}}
<aside class="w-64 shrink-0 bg-white border-r border-gray-200 min-h-[calc(100vh-4rem)]">
    <nav class="p-4 space-y-1 text-sm">

        {{-- Visible to every authenticated user --}}
        <a href="{{ route('dashboard') }}"
           class="flex items-center px-3 py-2 rounded-md font-medium {{ request()->routeIs('dashboard') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">
            Dasbor
        </a>

        {{-- Settings: each item appears only if the user holds the matching permission (TASK-0106). --}}
        @canany(['manage users', 'manage roles', 'manage permissions'])
            <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400">Pengaturan</p>
            @can('manage users')
                <a href="{{ route('settings.users.index') }}"
                   class="block px-3 py-2 rounded-md {{ request()->routeIs('settings.users.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">Pengguna</a>
            @endcan
            @can('manage roles')
                <a href="{{ route('settings.roles.index') }}"
                   class="block px-3 py-2 rounded-md {{ request()->routeIs('settings.roles.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">Role</a>
            @endcan
            @can('manage permissions')
                <a href="{{ route('settings.permissions.index') }}"
                   class="block px-3 py-2 rounded-md {{ request()->routeIs('settings.permissions.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">Permission</a>
            @endcan
        @endcanany

        {{-- Master Data: each link appears only with the matching permission (TASK-0207). --}}
        @canany(['manage clinics', 'manage doctors', 'manage patients', 'manage lab services', 'manage technicians'])
            <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400">Master Data</p>
            @can('manage clinics')
                <a href="{{ route('settings.clinics.index') }}"
                   class="block px-3 py-2 rounded-md {{ request()->routeIs('settings.clinics.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">Klinik</a>
            @endcan
            @can('manage doctors')
                <a href="{{ route('settings.doctors.index') }}"
                   class="block px-3 py-2 rounded-md {{ request()->routeIs('settings.doctors.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">Dokter</a>
            @endcan
            @can('manage patients')
                <a href="{{ route('settings.patients.index') }}"
                   class="block px-3 py-2 rounded-md {{ request()->routeIs('settings.patients.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">Pasien</a>
            @endcan
            @can('manage lab services')
                <a href="{{ route('settings.lab-services.index') }}"
                   class="block px-3 py-2 rounded-md {{ request()->routeIs('settings.lab-services.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">Layanan Lab</a>
            @endcan
            @can('manage technicians')
                <a href="{{ route('settings.technicians.index') }}"
                   class="block px-3 py-2 rounded-md {{ request()->routeIs('settings.technicians.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">Teknisi</a>
            @endcan
        @endcanany

        @canany(['view_lab_orders', 'manage_lab_orders', 'view_production', 'manage_production', 'view_quality_control', 'manage_quality_control', 'view_delivery', 'manage_delivery'])
            <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400">Operasional</p>
            @canany(['view_lab_orders', 'manage_lab_orders'])
                <a href="{{ route('lab-orders.index') }}"
                   class="block px-3 py-2 rounded-md {{ request()->routeIs('lab-orders.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">Order Lab</a>
            @endcanany
            @canany(['view_production', 'manage_production'])
                <a href="{{ route('production.board') }}"
                   class="block px-3 py-2 rounded-md {{ request()->routeIs('production.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">Produksi</a>
            @endcanany
            @canany(['view_quality_control', 'manage_quality_control'])
                <a href="{{ route('quality-control.queue') }}"
                   class="block px-3 py-2 rounded-md {{ request()->routeIs('quality-control.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">Quality Control</a>
            @endcanany
            @canany(['view_delivery', 'manage_delivery'])
                <a href="{{ route('deliveries.index') }}"
                   class="block px-3 py-2 rounded-md {{ request()->routeIs('deliveries.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">Pengiriman</a>
            @endcanany
        @endcanany

        @role('Technician')
            @can('view_production')
                <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400">My Work</p>
                <a href="{{ route('production.board') }}" class="block px-3 py-2 rounded-md text-gray-600 hover:bg-gray-50 hover:text-gray-900">My Assignments</a>
            @endcan
        @endrole

        @role('Quality Control')
            <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400">Quality</p>
            <a href="#" class="block px-3 py-2 rounded-md text-gray-600 hover:bg-gray-50 hover:text-gray-900">Quality Control <span class="text-xs text-gray-400">(Sprint 5)</span></a>
        @endrole

        @role('Courier')
            <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400">Delivery</p>
            <a href="{{ route('deliveries.index') }}" class="block px-3 py-2 rounded-md {{ request()->routeIs('deliveries.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">My Deliveries</a>
        @endrole

        @canany(['view_inventory', 'manage_inventory', 'manage master data'])
            <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400">Persediaan</p>
            <a href="{{ route('inventory.dashboard') }}"
               class="block px-3 py-2 rounded-md {{ request()->routeIs('inventory.dashboard') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">Dasbor</a>
            <a href="{{ route('inventory.products.index') }}"
               class="block px-3 py-2 rounded-md {{ request()->routeIs('inventory.products.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">Produk</a>
            <a href="{{ route('inventory.locations.index') }}"
               class="block px-3 py-2 rounded-md {{ request()->routeIs('inventory.locations.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">Lokasi Persediaan</a>
            <a href="{{ route('inventory.suppliers.index') }}"
               class="block px-3 py-2 rounded-md {{ request()->routeIs('inventory.suppliers.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">Pemasok</a>
            <a href="{{ route('inventory.stock.index') }}"
               class="block px-3 py-2 rounded-md {{ request()->routeIs('inventory.stock.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">Stok</a>
        @endcanany

        @canany(['view_invoice', 'manage_invoice', 'view_payment', 'manage_payment'])
            <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400">Keuangan</p>
            @canany(['view_invoice', 'manage_invoice'])
                <a href="{{ route('invoices.index') }}"
                   class="block px-3 py-2 rounded-md {{ request()->routeIs('invoices.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">Invoice</a>
            @endcanany
        @endcanany

        @role('Doctor')
            <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400">My Lab</p>
            <a href="#" class="block px-3 py-2 rounded-md text-gray-600 hover:bg-gray-50 hover:text-gray-900">My Orders <span class="text-xs text-gray-400">(Sprint 3)</span></a>
        @endrole

        {{-- Reports (Sprint 8) — permission-aware menu. --}}
        @canany(['view_dashboard', 'view_order_report', 'view_production_report', 'view_qc_report', 'view_delivery_report', 'view_invoice_report', 'view_payment_report', 'manage_report'])
            <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400">Laporan</p>
            @php($reportActive = fn ($name) => request()->routeIs($name) ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900')
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
                <a href="{{ route('reports.qc') }}" class="block px-3 py-2 rounded-md {{ $reportActive('reports.qc') }}">Quality Control</a>
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
        @endcanany

    </nav>
</aside>
