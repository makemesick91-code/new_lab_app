@php($role = $role ?? null)
@php($assignedPermissions = $assignedPermissions ?? [])
@php($selectedPermissions = old('permissions', $assignedPermissions))

<div class="space-y-6">
    <div>
        <label for="role-name" class="block text-sm font-medium text-gray-700">Nama Role</label>
        <input id="role-name" type="text" name="name" value="{{ old('name', $role?->name) }}"
               class="mt-1 block w-full max-w-md rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500" />
        @error('name')
            <p class="mt-1 text-sm text-danger">{{ $message }}</p>
        @enderror
    </div>

    <div
        x-data="{
            search: '',
            openGroups: @js(collect($permissionGroups)->pluck('key')->mapWithKeys(fn ($key) => [$key => true])->all()),
            matchesSearch(name, description) {
                const term = this.search.trim().toLowerCase();
                if (term === '') return true;
                return name.toLowerCase().includes(term)
                    || (description && description.toLowerCase().includes(term));
            },
            groupHasVisiblePermissions(groupKey, permissions) {
                if (this.search.trim() === '') return true;
                return permissions.some(p => this.matchesSearch(p.name, p.description));
            },
            toggleModule(moduleKey, checked) {
                document.querySelectorAll(`input[name='permissions[]'][data-module='${moduleKey}']`).forEach((el) => {
                    const row = el.closest('[data-permission-row]');
                    if (row && row.offsetParent !== null) {
                        el.checked = checked;
                    }
                });
            },
            syncModuleSelectAll(moduleKey) {
                const boxes = Array.from(document.querySelectorAll(`input[name='permissions[]'][data-module='${moduleKey}']`))
                    .filter((el) => {
                        const row = el.closest('[data-permission-row]');
                        return row && row.offsetParent !== null;
                    });
                const selectAll = document.querySelector(`[data-module-select-all='${moduleKey}']`);
                if (! selectAll || boxes.length === 0) return;
                const checkedCount = boxes.filter((el) => el.checked).length;
                selectAll.checked = checkedCount === boxes.length;
                selectAll.indeterminate = checkedCount > 0 && checkedCount < boxes.length;
            }
        }"
        class="space-y-4"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <span class="block text-base font-semibold text-gray-900">Permission Role</span>
                <p class="text-xs text-gray-500">Kelompok permission berdasarkan modul agar admin lebih mudah memilih akses role.</p>
            </div>
            <div class="w-full max-w-sm">
                <label for="permission-search" class="sr-only">Cari permission</label>
                <input
                    id="permission-search"
                    type="search"
                    x-model="search"
                    placeholder="Cari permission..."
                    class="block w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500"
                />
            </div>
        </div>

        <div class="rounded-lg border border-brand-100 bg-brand-50 px-4 py-3 text-xs text-brand-800">
            <ul class="list-disc space-y-1 pl-4">
                <li>Centang permission yang ingin diberikan pada role ini.</li>
                <li>Permission yang sudah aktif akan tetap tercentang saat edit role.</li>
                <li>Other / Uncategorized berisi permission yang belum masuk klasifikasi modul utama.</li>
            </ul>
        </div>

        @error('permissions')
            <p class="text-sm text-danger">{{ $message }}</p>
        @enderror
        @error('permissions.*')
            <p class="text-sm text-danger">{{ $message }}</p>
        @enderror

        <div class="space-y-3">
            @foreach ($permissionGroups as $group)
                @php($groupPermissionNames = collect($group['permissions'])->pluck('name')->all())
                @php($groupSelectedCount = count(array_intersect($groupPermissionNames, $selectedPermissions)))
                <section
                    x-show="groupHasVisiblePermissions(@js($group['key']), @js($group['permissions']))"
                    x-cloak
                    class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm"
                    data-permission-group="{{ $group['key'] }}"
                >
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 bg-gray-50 px-4 py-3">
                        <button
                            type="button"
                            class="flex min-w-0 flex-1 items-center gap-2 text-left"
                            @click="openGroups[@js($group['key'])] = !openGroups[@js($group['key'])]"
                        >
                            <svg class="h-4 w-4 shrink-0 text-gray-500 transition-transform" :class="openGroups[@js($group['key'])] ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L10.94 10 7.23 6.29a.75.75 0 111.06-1.06l4.25 4.25a.75.75 0 010 1.06l-4.25 4.25a.75.75 0 01-1.06 0z" clip-rule="evenodd" />
                            </svg>
                            <div class="min-w-0">
                                <h3 class="text-sm font-semibold text-gray-900">{{ $group['label'] }}</h3>
                                @if (! empty($group['description']))
                                    <p class="text-xs text-gray-500">{{ $group['description'] }}</p>
                                @endif
                                <p class="mt-0.5 text-xs font-medium text-gray-400">
                                    <span data-group-selected-count="{{ $group['key'] }}">{{ $groupSelectedCount }}</span>/{{ count($group['permissions']) }} permission dipilih
                                </p>
                            </div>
                        </button>

                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input
                                type="checkbox"
                                data-module-select-all="{{ $group['key'] }}"
                                class="rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                                @change="toggleModule(@js($group['key']), $event.target.checked)"
                            />
                            <span>Pilih semua</span>
                        </label>
                    </div>

                    <div x-show="openGroups[@js($group['key'])]" class="divide-y divide-gray-100">
                        @if ($group['key'] === 'other')
                            <p class="bg-warning-50 px-4 py-2 text-xs text-warning-700">
                                Permission di sini belum masuk klasifikasi modul utama. Review sebelum diberikan ke role.
                            </p>
                        @endif
                        @foreach ($group['permissions'] as $permission)
                            <label
                                data-permission-row
                                x-show="matchesSearch(@js($permission['name']), @js($permission['description']))"
                                x-cloak
                                class="flex cursor-pointer items-start gap-3 px-4 py-3 hover:bg-gray-50"
                            >
                                <input
                                    type="checkbox"
                                    name="permissions[]"
                                    value="{{ $permission['name'] }}"
                                    data-module="{{ $group['key'] }}"
                                    @checked(in_array($permission['name'], $selectedPermissions, true))
                                    @change="syncModuleSelectAll(@js($group['key']))"
                                    class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                                />
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium text-gray-900">{{ $permission['name'] }}</span>
                                    @if ($permission['description'])
                                        <span class="mt-0.5 block text-xs text-gray-500">{{ $permission['description'] }}</span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    </div>
</div>
