<x-settings-shell title="Create Lab Service">
    <div class="bg-white shadow-sm sm:rounded-lg">
        <form method="POST" action="{{ route('settings.lab-services.store') }}" class="p-6 space-y-6">
            @csrf
            @include('settings.lab-services._form', ['labService' => null])
            <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Save Lab Service</button>
                <a href="{{ route('settings.lab-services.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
            </div>
        </form>
    </div>
</x-settings-shell>
