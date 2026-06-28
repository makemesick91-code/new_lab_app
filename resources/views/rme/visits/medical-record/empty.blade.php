<x-settings-shell title="Rekam Medis">
    {{--
        Sprint 64.0 / 64.0.2 — patient-centric RM workspace empty state.

        The patient has visits but no RM sheet yet. The handwriting RM book is
        always anchored to the canonical (first) visit. Users who may manage RME
        get "Buat Halaman RM Pertama" which creates the canonical medical
        record. KTP / NIK is never rendered.
    --}}
    <div class="space-y-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Rekam Medis Elektronik</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">Buku RM Tulisan Tangan Pasien</h2>
            <p class="mt-1 text-sm text-gray-500">{{ $workspaceVisit->visit_number }} &mdash; {{ $workspaceVisit->visit_date?->format('d/m/Y') }}</p>
            @if ($sourceVisit && $sourceVisit->id !== $workspaceVisit->id)
                <p class="mt-2 text-sm text-sky-800">
                    Dibuka dari Kunjungan #{{ $sourceVisit->visit_number }}. Buku RM tulisan tangan ditambahkan ke kunjungan pertama pasien.
                </p>
            @endif
        </div>

        <x-ui.card title="Informasi Pasien">
            @php
                $bioDash = '-';
                $genderLabels = ['Male' => 'Laki-laki', 'Female' => 'Perempuan', 'Other' => 'Lainnya'];

                if ($patient?->date_of_birth) {
                    $ttlUmur = $patient->date_of_birth->format('d-m-Y');
                    $patientAge = $patient->age();
                    if ($patientAge !== null) {
                        $ttlUmur .= ' / '.$patientAge.' tahun';
                    }
                } else {
                    $ttlUmur = $bioDash;
                }
            @endphp
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Nama</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $patient?->name ?: $bioDash }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">TTL / Umur</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $ttlUmur }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Jenis Kelamin</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $patient?->gender ? ($genderLabels[$patient->gender] ?? $patient->gender) : $bioDash }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">No. RM</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $patient?->medical_record_number ?: $bioDash }}</dd>
                </div>
            </dl>
        </x-ui.card>

        <x-ui.card title="Buku RM Tulisan Tangan">
            <div class="rounded-lg border border-dashed border-amber-300 bg-amber-50 px-4 py-8 text-center">
                <p class="text-sm font-medium text-amber-800">
                    Belum ada halaman RM tulisan tangan untuk pasien ini.
                </p>

                @if ($canEdit)
                    <p class="mt-2 text-xs text-amber-700">
                        Buat halaman RM pertama pada kunjungan pertama pasien
                        (Kunjungan #{{ $workspaceVisit->visit_number }}).
                    </p>
                    <form method="POST" action="{{ route('rme.visits.medical-record.store', $workspaceVisit) }}" class="mt-4">
                        @csrf
                        @if ($sourceVisit && $sourceVisit->id !== $workspaceVisit->id)
                            <input type="hidden" name="source_visit_id" value="{{ $sourceVisit->id }}">
                        @endif
                        <x-ui.button type="submit" variant="primary">
                            Buat Halaman RM Pertama
                        </x-ui.button>
                    </form>
                @else
                    <p class="mt-2 text-xs text-amber-700">
                        Halaman RM tulisan tangan akan tampil di sini setelah dokter membuat rekam medis pasien.
                    </p>
                @endif
            </div>
        </x-ui.card>

        <div>
            <a href="{{ route('rme.visits.show', $sourceVisit ?? $workspaceVisit) }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali ke detail kunjungan</a>
        </div>
    </div>
</x-settings-shell>
