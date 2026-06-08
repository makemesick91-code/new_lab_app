<x-settings-shell title="Tambah Tarif">
    <div class="bg-white shadow-sm sm:rounded-lg">
        <form method="POST" action="{{ route('settings.tariffs.store') }}" class="p-6 space-y-6">
            @csrf
            @include('settings.tariffs._form', ['tariff' => null, 'treatments' => $treatments])
            <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Simpan Tarif</button>
                <a href="{{ route('settings.tariffs.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Batal</a>
            </div>
        </form>
    </div>
</x-settings-shell>
