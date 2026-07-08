<x-settings-shell title="Tambah Role">
    <div class="space-y-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">Access Control</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">Tambah Role</h2>
            <p class="mt-1 text-sm text-gray-500">Buat role baru dan tetapkan permission per modul operasional.</p>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <form method="POST" action="{{ route('settings.roles.store') }}" class="p-6 space-y-6">
                @csrf
                @include('settings.roles._form', [
                    'role' => null,
                    'permissionGroups' => $permissionGroups,
                    'assignedPermissions' => [],
                ])

                <div class="flex flex-wrap items-center gap-3 border-t border-gray-100 pt-4">
                    <button type="submit" class="inline-flex items-center rounded-lg bg-brand-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                        Simpan Role
                    </button>
                    <a href="{{ route('settings.roles.index') }}" class="text-sm font-medium text-gray-600 hover:text-gray-900">
                        Batal
                    </a>
                </div>
            </form>
        </div>
    </div>
</x-settings-shell>
