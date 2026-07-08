<x-settings-shell title="Tambah Cabang">
    <div class="rounded-xl border border-hairline bg-surface shadow-card">
        <form method="POST" action="{{ route('settings.branches.store') }}" class="p-6 space-y-6">
            @csrf
            @include('settings.branches._form', ['branch' => null])
            <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex items-center rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-500">Simpan Cabang</button>
                <a href="{{ route('settings.branches.index') }}" class="text-sm text-ink-soft hover:text-navy">Batal</a>
            </div>
        </form>
    </div>
</x-settings-shell>
