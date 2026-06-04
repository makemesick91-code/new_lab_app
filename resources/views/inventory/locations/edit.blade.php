<x-settings-shell title="Edit Inventory Location">
    <div class="bg-white shadow-sm sm:rounded-lg p-6">
        <form method="POST" action="{{ route('inventory.locations.update', $location) }}" class="space-y-5">
            @csrf
            @method('PUT')
            @include('inventory.locations._form', ['location' => $location])
            <div class="flex items-center justify-end gap-2">
                <a href="{{ route('inventory.locations.show', $location) }}" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
                <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Update Location</button>
            </div>
        </form>
    </div>
</x-settings-shell>
