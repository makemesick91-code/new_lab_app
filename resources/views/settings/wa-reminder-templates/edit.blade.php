<x-settings-shell title="Ubah Template Reminder WA">
    <div class="bg-white shadow-sm sm:rounded-lg">
        <form method="POST" action="{{ route('settings.wa-reminder-templates.update', $template) }}" class="p-6 space-y-6">
            @csrf @method('PUT')
            @include('settings.wa-reminder-templates._form', ['template' => $template])
            <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Perbarui Template</button>
                <a href="{{ route('settings.wa-reminder-templates.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Batal</a>
            </div>
        </form>
    </div>
</x-settings-shell>
