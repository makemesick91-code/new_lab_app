<x-settings-shell title="Rekam Medis">
    {{--
        Sprint 64.0 / 64.0.2 — patient-centric RM workspace empty state.

        The patient has visits but no RM sheet yet. The handwriting RM book is
        always anchored to the canonical (first) visit. Users who may manage RME
        get "Buat Halaman RM Pertama" which creates the canonical medical
        record. KTP / NIK is never rendered.
    --}}
    <div class="space-y-6">
        <x-ui.page-header
            title="Buku RM Tulisan Tangan Pasien"
            :subtitle="$workspaceVisit->visit_number.' — '.($workspaceVisit->visit_date?->format('d/m/Y') ?? '-')"
        >
            <x-slot:breadcrumb>Rekam Medis Elektronik</x-slot:breadcrumb>
        </x-ui.page-header>

        @if ($sourceVisit && $sourceVisit->id !== $workspaceVisit->id)
            <x-ui.alert variant="info">
                Dibuka dari Kunjungan #{{ $sourceVisit->visit_number }}. Buku RM tulisan tangan ditambahkan ke kunjungan pertama pasien.
            </x-ui.alert>
        @endif

        {{-- LEGACY-RME-DOCTOR-WORKSPACE-1 — a migrated patient may hold a
             published legacy archive while having no native RM sheet yet. That
             archive is the ONLY RME this patient has, so the document rail
             belongs above the empty-state shell too, not only below it. --}}
        @include('rme.visits.partials.rme-workspace-documents', [
            'workspaceDocuments' => $workspaceDocuments ?? collect(),
        ])

        {{-- LEGACY-RME-DOCTOR-WORKSPACE-1A — this patient has no native RM sheet
             yet, but the published archive IS their record. It is rendered here
             as real pages driven by the SAME navigator and the same `?rm_page=`
             sequence used once a native sheet exists, so the archive is never
             something the doctor has to go and find somewhere else. --}}
        @php
            $workspacePages = $workspacePages ?? collect();
            $activePageNumber = $activePageNumber ?? 1;
            $totalRmPages = $totalRmPages ?? 1;
            $prevPage = $activePageNumber > 1 ? $activePageNumber - 1 : null;
            $nextNavPage = $activePageNumber < $totalRmPages ? $activePageNumber + 1 : null;
            $rmPageUrl = fn ($page) => route('rme.visits.medical-record.show', [$workspaceVisit, 'rm_page' => $page]);
            $prevSwipeUrl = $prevPage ? $rmPageUrl($prevPage) : '';
            $nextSwipeUrl = $nextNavPage ? $rmPageUrl($nextNavPage) : '';
        @endphp

        @if ($workspacePages->isNotEmpty() && ($activeWorkspacePage ?? null))
            <x-ui.card title="RME Lama (Arsip)"
                       description="Pasien ini belum memiliki lembar RM tulisan tangan. Arsip RME lama di bawah bersifat hanya baca — gunakan navigasi halaman atau geser kiri/kanan untuk membaca.">
                <div id="rm-handwriting-swipe" class="mb-4 touch-pan-y"
                     data-rm-swipe-zone
                     @if ($prevSwipeUrl) data-prev-url="{{ $prevSwipeUrl }}" @endif
                     @if ($nextSwipeUrl) data-next-url="{{ $nextSwipeUrl }}" @endif
                >
                    @include('rme.visits.medical-record.partials.rm-page-navigator', ['canEditHandwriting' => false])

                    <p class="mb-3 text-xs text-gray-500">Geser kiri/kanan pada area halaman untuk berpindah halaman.</p>

                    <div id="rm-page-previews" class="touch-pan-y" data-active-page-type="legacy">
                        @include('rme.visits.medical-record.partials.legacy-archive-page')
                    </div>
                </div>

                @include('rme.visits.medical-record.partials.rm-page-swipe-script')
            </x-ui.card>
        @endif

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
            @if ($canEdit)
                <x-ui.empty-state
                    :icon="false"
                    title="Belum ada halaman RM tulisan tangan untuk pasien ini."
                    :description="'Buat halaman RM pertama pada kunjungan pertama pasien (Kunjungan #'.$workspaceVisit->visit_number.').'"
                >
                    <x-slot:action>
                        <form method="POST" action="{{ route('rme.visits.medical-record.store', $workspaceVisit) }}">
                            @csrf
                            @if ($sourceVisit && $sourceVisit->id !== $workspaceVisit->id)
                                <input type="hidden" name="source_visit_id" value="{{ $sourceVisit->id }}">
                            @endif
                            <x-ui.button type="submit" variant="primary">
                                Buat Halaman RM Pertama
                            </x-ui.button>
                        </form>
                    </x-slot:action>
                </x-ui.empty-state>
            @else
                <x-ui.empty-state
                    :icon="false"
                    title="Belum ada halaman RM tulisan tangan untuk pasien ini."
                    description="Halaman RM tulisan tangan akan tampil di sini setelah dokter membuat rekam medis pasien."
                />
            @endif
        </x-ui.card>

        {{-- LEGACY-RME-PDF-HISTORY-1 — a patient may already hold a published
             legacy archive while having no native RM sheet yet. That archive IS
             the patient's clinical history, so it stays visible here. --}}
        @include('rme.visits.partials.patient-rme-clinical-history', [
            'clinicalHistory' => $clinicalHistory ?? collect(),
        ])

        <div>
            <a href="{{ route('rme.visits.show', $sourceVisit ?? $workspaceVisit) }}" class="text-sm text-ink-soft hover:text-ink">&larr; Kembali ke detail kunjungan</a>
        </div>
    </div>
</x-settings-shell>
