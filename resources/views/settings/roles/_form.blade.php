@php($role = $role ?? null)
@php($assignedPermissions = $assignedPermissions ?? [])

<div class="space-y-6">
    <div>
        <label class="block text-sm font-medium text-gray-700">Role Name</label>
        <input type="text" name="name" value="{{ old('name', $role?->name) }}"
               class="mt-1 block w-full max-w-md rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
    </div>

    <div>
        <span class="block text-sm font-medium text-gray-700">Permissions</span>
        <p class="text-xs text-gray-400 mb-2">Select the permissions granted to this role.</p>
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
            @foreach ($permissions as $permission)
                <label class="flex items-center gap-2 rounded-md border border-gray-200 px-3 py-2 text-sm">
                    <input type="checkbox" name="permissions[]" value="{{ $permission->name }}"
                           @checked(in_array($permission->name, old('permissions', $assignedPermissions), true))
                           class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                    <span class="text-gray-700">{{ $permission->name }}</span>
                </label>
            @endforeach
        </div>
    </div>
</div>
