{{--
    FIX-04b — review one staged legacy odontogram chart before publishing.

    Every page image is REFERENCED through the policy-gated staging page route,
    never embedded and never given a public URL. KTP/NIK is never rendered.
--}}
<x-settings-shell title="Detail Impor Arsip Odontogram Lama">
    @php
        $isReady = $import->status === \App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus::READY_FOR_REVIEW;
        $isReviewed = $import->status === \App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus::REVIEWED;
        $isFailed = $import->status === \App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus::FAILED;
    @endphp

    <div class="space-y-6">
        <x-ui.page-header
            title="Detail Impor Arsip Odontogram Lama"
            subtitle="Periksa halaman hasil render dan tanggal dokumen sebelum arsip dipublikasikan."
        >
            <x-slot:breadcrumb>Master Data RME / Impor Arsip Odontogram Lama / Detail</x-slot:breadcrumb>

            <x-slot:actions>
                <x-ui.button variant="secondary" :href="route('settings.rme.legacy-odontograms.index')">Kembali</x-ui.button>

                @can('review', $import)
                    @if ($isReady)
                        <form method="POST" action="{{ route('settings.rme.legacy-odontograms.review', $import->getKey()) }}">
                            @csrf
                            <x-ui.button type="submit" variant="primary">Tandai Sudah Ditinjau</x-ui.button>
                        </form>
                    @endif
                @endcan

                @can('retry', $import)
                    @if ($isFailed || $import->status === \App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus::PROCESSING)
                        <form method="POST" action="{{ route('settings.rme.legacy-odontograms.retry', $import->getKey()) }}">
                            @csrf
                            <x-ui.button type="submit" variant="warning">Proses Ulang</x-ui.button>
                        </form>
                    @endif
                @endcan

                @can('cancel', $import)
                    <form
                        method="POST"
                        action="{{ route('settings.rme.legacy-odontograms.cancel', $import->getKey()) }}"
                        onsubmit="return confirm('Batalkan impor arsip ini?');"
                    >
                        @csrf
                        <x-ui.button type="submit" variant="danger">Batalkan</x-ui.button>
                    </form>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>

        @if (session('status'))
            <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
        @endif

        @error('status')
            <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
        @enderror
        @error('selected_odontogram_date')
            <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
        @enderror
        @error('patient_id')
            <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
        @enderror

        <x-ui.card>
            <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-muted">Pasien</dt>
                    <dd class="mt-1 font-semibold text-navy">{{ $import->patient?->name ?? '—' }}</dd>
                    <dd class="text-xs text-ink-muted">{{ $import->patient?->medical_record_number ?? 'Belum ada RM' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-muted">Status</dt>
                    <dd class="mt-1"><x-ui.badge :status="$import->status">{{ $import->status }}</x-ui.badge></dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-muted">Tanggal Odontogram Lama</dt>
                    <dd class="mt-1 text-ink">{{ $import->selected_odontogram_date?->format('d-m-Y') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-muted">Odontogram Pertama di Sistem</dt>
                    <dd class="mt-1 text-ink">{{ $import->earliest_native_odontogram_date_snapshot?->format('d-m-Y') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-muted">Cabang Asal</dt>
                    <dd class="mt-1 text-ink">{{ $import->originBranch?->name ?? 'Tidak dicatat' }}</dd>
                    <dd class="text-xs text-ink-muted">Diturunkan dari Nomor RM pasien.</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-muted">Jumlah Halaman</dt>
                    <dd class="mt-1 text-ink">{{ $import->page_count ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-muted">Nama Berkas</dt>
                    <dd class="mt-1 text-ink">{{ $import->original_filename ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-muted">Diunggah Oleh</dt>
                    <dd class="mt-1 text-ink">{{ $import->uploadedBy?->name ?? '—' }}</dd>
                </div>
            </dl>
        </x-ui.card>

        @if ($isFailed && filled($import->failure_message))
            <x-ui.alert variant="danger" title="Pemrosesan gagal">{{ $import->failure_message }}</x-ui.alert>
        @endif

        @if ($record !== null)
            <x-ui.alert variant="success" title="Sudah dipublikasikan">
                Arsip ini sudah menjadi bukti klinis permanen.
                <a class="font-semibold text-brand-700 underline" href="{{ route('rme.legacy-odontograms.show', $record->getKey()) }}">
                    Buka arsip
                </a>
            </x-ui.alert>
        @endif

        <x-ui.card title="Halaman Hasil Render" description="Periksa setiap halaman sebelum mempublikasikan.">
            @if ($pages->isEmpty())
                <x-ui.empty-state
                    title="Belum ada halaman"
                    description="Dokumen belum selesai diproses, atau pemrosesan gagal."
                />
            @else
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($pages as $page)
                        <figure class="overflow-hidden rounded-xl border border-hairline bg-surface">
                            <img
                                src="{{ route('settings.rme.legacy-odontograms.pages.show', [$import->getKey(), $page->page_number]) }}"
                                alt="Halaman {{ $page->page_number }}"
                                class="w-full"
                                loading="lazy"
                            >
                            <figcaption class="border-t border-hairline px-3 py-2 text-xs text-ink-soft">
                                Halaman {{ $page->page_number }}
                            </figcaption>
                        </figure>
                    @endforeach
                </div>
            @endif
        </x-ui.card>

        @can('publish', $import)
            @if ($isReviewed)
                <form method="POST" action="{{ route('settings.rme.legacy-odontograms.publish', $import->getKey()) }}">
                    @csrf
                    <x-ui.card
                        title="Publikasikan Arsip"
                        description="Setelah dipublikasikan, arsip bersifat permanen dan hanya dapat dikoreksi melalui pembatalan (VOID) disertai alasan."
                    >
                        <div class="space-y-4">
                            <x-ui.input name="title" label="Judul Arsip (opsional)" :value="old('title')" />
                            <x-ui.textarea name="description" label="Keterangan (opsional)" rows="3">{{ old('description') }}</x-ui.textarea>
                        </div>

                        <x-slot:actions>
                            <x-ui.button type="submit" variant="success">Publikasikan</x-ui.button>
                        </x-slot:actions>
                    </x-ui.card>
                </form>
            @endif
        @endcan
    </div>
</x-settings-shell>
