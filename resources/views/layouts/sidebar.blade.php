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
            Dashboard
        </a>

        {{-- Settings: each item appears only if the user holds the matching permission (TASK-0106). --}}
        @canany(['manage users', 'manage roles', 'manage permissions'])
            <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400">Settings</p>
            @can('manage users')
                <a href="{{ route('settings.users.index') }}"
                   class="block px-3 py-2 rounded-md {{ request()->routeIs('settings.users.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">Users</a>
            @endcan
            @can('manage roles')
                <a href="{{ route('settings.roles.index') }}"
                   class="block px-3 py-2 rounded-md {{ request()->routeIs('settings.roles.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">Roles</a>
            @endcan
            @can('manage permissions')
                <a href="{{ route('settings.permissions.index') }}"
                   class="block px-3 py-2 rounded-md {{ request()->routeIs('settings.permissions.*') ? 'bg-gray-100 text-gray-900' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' }}">Permissions</a>
            @endcan
        @endcanany

        @hasanyrole('Super Admin|Admin Lab')
            <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400">Master Data</p>
            <a href="#" class="block px-3 py-2 rounded-md text-gray-600 hover:bg-gray-50 hover:text-gray-900">Clinics &amp; Doctors <span class="text-xs text-gray-400">(Sprint 2)</span></a>
            <a href="#" class="block px-3 py-2 rounded-md text-gray-600 hover:bg-gray-50 hover:text-gray-900">Lab Services <span class="text-xs text-gray-400">(Sprint 2)</span></a>

            <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400">Operations</p>
            <a href="#" class="block px-3 py-2 rounded-md text-gray-600 hover:bg-gray-50 hover:text-gray-900">Lab Orders <span class="text-xs text-gray-400">(Sprint 3)</span></a>
            <a href="#" class="block px-3 py-2 rounded-md text-gray-600 hover:bg-gray-50 hover:text-gray-900">Assignments <span class="text-xs text-gray-400">(Sprint 4)</span></a>
        @endhasanyrole

        @role('Technician')
            <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400">Production</p>
            <a href="#" class="block px-3 py-2 rounded-md text-gray-600 hover:bg-gray-50 hover:text-gray-900">My Assignments <span class="text-xs text-gray-400">(Sprint 4)</span></a>
        @endrole

        @role('Quality Control')
            <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400">Quality</p>
            <a href="#" class="block px-3 py-2 rounded-md text-gray-600 hover:bg-gray-50 hover:text-gray-900">Quality Control <span class="text-xs text-gray-400">(Sprint 5)</span></a>
        @endrole

        @role('Courier')
            <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400">Delivery</p>
            <a href="#" class="block px-3 py-2 rounded-md text-gray-600 hover:bg-gray-50 hover:text-gray-900">My Deliveries <span class="text-xs text-gray-400">(Sprint 6)</span></a>
        @endrole

        @role('Finance')
            <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400">Finance</p>
            <a href="#" class="block px-3 py-2 rounded-md text-gray-600 hover:bg-gray-50 hover:text-gray-900">Invoices <span class="text-xs text-gray-400">(Sprint 7)</span></a>
            <a href="#" class="block px-3 py-2 rounded-md text-gray-600 hover:bg-gray-50 hover:text-gray-900">Payments <span class="text-xs text-gray-400">(Sprint 7)</span></a>
        @endrole

        @role('Doctor')
            <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400">My Lab</p>
            <a href="#" class="block px-3 py-2 rounded-md text-gray-600 hover:bg-gray-50 hover:text-gray-900">My Orders <span class="text-xs text-gray-400">(Sprint 3)</span></a>
        @endrole

        @hasanyrole('Super Admin|Admin Lab|Finance')
            <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400">Insights</p>
            <a href="#" class="block px-3 py-2 rounded-md text-gray-600 hover:bg-gray-50 hover:text-gray-900">Reports <span class="text-xs text-gray-400">(Sprint 8)</span></a>
        @endhasanyrole

    </nav>
</aside>
