<x-settings-shell title="Inventory Location">
    <div class="bg-white shadow-sm sm:rounded-lg p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-sm text-gray-500">{{ $location->code ?? '-' }}</p>
                <h3 class="text-xl font-semibold text-gray-900">{{ $location->name }}</h3>
            </div>
            @include('inventory._status-badge', ['active' => $location->is_active])
        </div>
        <dl class="mt-5 grid gap-4 sm:grid-cols-2 text-sm">
            <div><dt class="text-gray-500">Type</dt><dd class="font-medium text-gray-900">{{ str_replace('_', ' ', $location->type) }}</dd></div>
            <div><dt class="text-gray-500">Description</dt><dd class="font-medium text-gray-900">{{ $location->description ?? '-' }}</dd></div>
        </dl>
        <div class="mt-6 flex justify-end gap-2">
            <a href="{{ route('inventory.locations.index') }}" class="rounded-md bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200">Back</a>
            <a href="{{ route('inventory.locations.edit', $location) }}" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">Edit</a>
        </div>
    </div>
</x-settings-shell>
