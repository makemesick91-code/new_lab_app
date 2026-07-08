<x-settings-shell title="Ubah Role">
    <div class="space-y-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">Access Control</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">Ubah Role</h2>
            <p class="mt-1 text-sm text-gray-500">Perbarui nama role dan permission yang sudah ditetapkan.</p>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <form method="POST" action="{{ route('settings.roles.update', $role) }}" class="p-6 space-y-6">
                @csrf @method('PUT')
                @include('settings.roles._form', [
                    'role' => $role,
                    'permissionGroups' => $permissionGroups,
                    'assignedPermissions' => $assignedPermissions,
                ])

                <div class="flex flex-wrap items-center gap-3 border-t border-gray-100 pt-4">
                    <button type="submit" class="inline-flex items-center rounded-lg bg-brand-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                        Perbarui Role
                    </button>
                    <a href="{{ route('settings.roles.index') }}" class="text-sm font-medium text-gray-600 hover:text-gray-900">
                        Batal
                    </a>
                </div>
            </form>
        </div>
    </div>
</x-settings-shell>
