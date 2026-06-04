<x-settings-shell title="Supplier Detail">
    <div class="bg-white shadow-sm sm:rounded-lg p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-sm text-gray-500">{{ $supplier->email ?? '-' }}</p>
                <h3 class="text-xl font-semibold text-gray-900">{{ $supplier->name }}</h3>
            </div>
            @include('inventory._status-badge', ['active' => $supplier->is_active])
        </div>
        <dl class="mt-5 grid gap-4 sm:grid-cols-2 text-sm">
            <div><dt class="text-gray-500">Phone</dt><dd class="font-medium text-gray-900">{{ $supplier->phone ?? '-' }}</dd></div>
            <div><dt class="text-gray-500">Email</dt><dd class="font-medium text-gray-900">{{ $supplier->email ?? '-' }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-gray-500">Address</dt><dd class="font-medium text-gray-900">{{ $supplier->address ?? '-' }}</dd></div>
        </dl>
        <div class="mt-6 flex justify-end gap-2">
            <a href="{{ route('inventory.suppliers.index') }}" class="rounded-md bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200">Back</a>
            <a href="{{ route('inventory.suppliers.edit', $supplier) }}" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">Edit</a>
        </div>
    </div>
</x-settings-shell>
