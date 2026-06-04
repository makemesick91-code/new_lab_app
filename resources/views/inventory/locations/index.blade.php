<x-settings-shell title="Inventory Locations">
    <div class="bg-white shadow-sm sm:rounded-lg">
        <div class="p-6 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <form method="GET" action="{{ route('inventory.locations.index') }}" class="flex flex-wrap items-center gap-2">
                    <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Search location"
                           class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <select name="type" class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">All types</option>
                        @foreach ($types as $type)
                            <option value="{{ $type }}" @selected(($filters['type'] ?? null) === $type)>{{ str_replace('_', ' ', $type) }}</option>
                        @endforeach
                    </select>
                    <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Search</button>
                    <a href="{{ route('inventory.locations.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Reset</a>
                </form>
                <a href="{{ route('inventory.locations.create') }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Create Location</a>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="px-3 py-2 font-medium">Code</th>
                            <th class="px-3 py-2 font-medium">Name</th>
                            <th class="px-3 py-2 font-medium">Type</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 font-medium text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($locations as $location)
                            <tr>
                                <td class="px-3 py-2 text-gray-600">{{ $location->code ?? '-' }}</td>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $location->name }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ str_replace('_', ' ', $location->type) }}</td>
                                <td class="px-3 py-2">@include('inventory._status-badge', ['active' => $location->is_active])</td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('inventory.locations.show', $location) }}" class="text-gray-600 hover:text-gray-900">View</a>
                                        <a href="{{ route('inventory.locations.edit', $location) }}" class="text-indigo-600 hover:text-indigo-500">Edit</a>
                                        @if ($location->is_active)
                                            <form method="POST" action="{{ route('inventory.locations.destroy', $location) }}">
                                                @csrf @method('DELETE')
                                                <button class="text-amber-600 hover:text-amber-500">Deactivate</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-6 text-center text-gray-400">No inventory locations found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $locations->links() }}</div>
        </div>
    </div>
</x-settings-shell>
