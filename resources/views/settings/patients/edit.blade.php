<x-settings-shell title="Ubah Pasien">
    <div class="bg-white shadow-sm sm:rounded-lg">
        <form method="POST" action="{{ route('settings.patients.update', $patient) }}" class="p-6 space-y-6">
            @csrf @method('PUT')
            @include('settings.patients._form', ['patient' => $patient, 'clinics' => $clinics, 'doctors' => $doctors, 'rmeBranches' => $rmeBranches])
            <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">Perbarui Pasien</button>
                <a href="{{ route('settings.patients.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Batal</a>
            </div>
        </form>
    </div>

    {{-- Sprint 61.1 — stored KTP scan (private document). Kept outside the
         update form to avoid nested forms. --}}
    @php($ktpDocument = $patient->ktpDocument)
    @if ($ktpDocument)
        <div class="mt-6 bg-white shadow-sm sm:rounded-lg">
            <div class="p-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700 mb-2">Dokumen KTP Tersimpan</p>
                <div class="flex flex-wrap items-center gap-3">
                    <a href="{{ route('settings.patients.documents.show', [$patient, $ktpDocument]) }}" target="_blank" rel="noopener"
                        class="inline-flex items-center rounded-md border border-indigo-300 bg-white px-3 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-50">
                        Lihat KTP
                    </a>
                    <form method="POST" action="{{ route('settings.patients.documents.destroy', [$patient, $ktpDocument]) }}"
                        onsubmit="return confirm('Hapus dokumen KTP pasien ini?');">
                        @csrf @method('DELETE')
                        <button type="submit"
                            class="inline-flex items-center rounded-md border border-rose-300 bg-white px-3 py-2 text-sm font-medium text-rose-700 hover:bg-rose-50">
                            Hapus KTP
                        </button>
                    </form>
                </div>
                <p class="mt-2 text-xs text-gray-400">Dokumen identitas privat. Tidak ditampilkan di laporan/cetak dan tidak diekspor.</p>
            </div>
        </div>
    @endif
</x-settings-shell>
