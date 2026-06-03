<x-settings-shell title="Create Technician">
    <div class="bg-white shadow-sm sm:rounded-lg">
        <form method="POST" action="{{ route('settings.technicians.store') }}" class="p-6 space-y-6">
            @csrf
            @include('settings.technicians._form', ['technician' => null, 'users' => $users])
            <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Save Technician</button>
                <a href="{{ route('settings.technicians.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
            </div>
        </form>
    </div>
</x-settings-shell>
