<x-settings-shell title="Ubah Cabang">
    <div class="bg-white shadow-sm sm:rounded-lg">
        <form method="POST" action="{{ route('settings.branches.update', $branch) }}" class="p-6 space-y-6">
            @csrf @method('PUT')
            @include('settings.branches._form', ['branch' => $branch])
            <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Perbarui Cabang</button>
                <a href="{{ route('settings.branches.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Batal</a>
            </div>
        </form>
    </div>
</x-settings-shell>
