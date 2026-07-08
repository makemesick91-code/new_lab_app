<x-settings-shell title="Ubah Tarif">
    <div class="rounded-xl border border-hairline bg-surface shadow-card">
        <form method="POST" action="{{ route('settings.tariffs.update', $tariff) }}" class="p-6 space-y-6">
            @csrf @method('PUT')
            @include('settings.tariffs._form', ['tariff' => $tariff, 'treatments' => $treatments])
            <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex items-center rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-500">Perbarui Tarif</button>
                <a href="{{ route('settings.tariffs.index') }}" class="text-sm text-ink-soft hover:text-navy">Batal</a>
            </div>
        </form>
    </div>
</x-settings-shell>
