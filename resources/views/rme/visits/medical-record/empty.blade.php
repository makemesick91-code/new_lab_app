<x-settings-shell title="Rekam Medis">
    {{--
        Sprint 64.0 zero-MR fix — patient-centric RM workspace empty state.

        The patient has visits but no RM sheet yet, so there is no $medicalRecord
        to render. Instead of 404ing we show a safe workspace shell: one patient =
        one buku RM, and this book is still empty. Users who may manage RME get a
        "Buat Lembar RM Pertama" control that creates the first sheet for the
        source visit (the one the doctor came from) or the canonical visit. KTP /
        NIK is never rendered.
    --}}
    <div class="space-y-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Rekam Medis Elektronik</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">Buku RM Pasien</h2>
            <p class="mt-1 text-sm text-gray-500">{{ $addSheetVisit->visit_number }} &mdash; {{ $addSheetVisit->visit_date?->format('d/m/Y') }}</p>
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

        <x-ui.card title="Buku RM Pasien">
            <div class="rounded-lg border border-dashed border-amber-300 bg-amber-50 px-4 py-8 text-center">
                <p class="text-sm font-medium text-amber-800">
                    Belum ada lembar RM tersimpan untuk pasien ini.
                </p>

                @if ($canEdit)
                    <p class="mt-2 text-xs text-amber-700">
                        Buat lembar RM pertama untuk mulai mencatat rekam medis pasien pada
                        Kunjungan #{{ $addSheetVisit->visit_number }}.
                    </p>
                    <form method="POST" action="{{ route('rme.visits.medical-record.store', $addSheetVisit) }}" class="mt-4">
                        @csrf
                        <x-ui.button type="submit" variant="primary">
                            Buat Lembar RM Pertama
                        </x-ui.button>
                    </form>
                @else
                    <p class="mt-2 text-xs text-amber-700">
                        Lembar RM akan tampil di sini setelah dokter membuat rekam medis pasien.
                    </p>
                @endif
            </div>
        </x-ui.card>

        <div>
            <a href="{{ route('rme.visits.show', $addSheetVisit) }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali ke detail kunjungan</a>
        </div>
    </div>
</x-settings-shell>
