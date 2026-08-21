{{--
    FIX-04b — the read-only viewer for a PUBLISHED legacy odontogram record.

    There is no edit form and no delete button anywhere on this page, because
    there is no route behind either one: a published archive is immutable, and
    the only supported correction is VOID plus a fresh import.

    Every page image is REFERENCED through the policy-gated page route, so the
    browser re-requests each one with the reader's own session and every request
    passes the same policy. KTP/NIK is never rendered.
--}}
<x-settings-shell title="Arsip Odontogram Lama">
    <div class="space-y-6">
        <x-ui.page-header
            title="Arsip Odontogram Lama"
            subtitle="Dokumen odontogram historis pasien. Bersifat permanen dan hanya dapat dibaca."
        >
            <x-slot:breadcrumb>RME / Arsip Odontogram Lama</x-slot:breadcrumb>

            <x-slot:actions>
                <x-ui.button
                    variant="secondary"
                    :href="route('rme.legacy-odontograms.source', $record->getKey())"
                >Buka PDF Sumber</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        @if (session('status'))
            <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
        @endif

        @error('void_reason')
            <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
        @enderror

        @if ($record->isVoided())
            <x-ui.alert variant="danger" title="Arsip dibatalkan (VOID)">
                Arsip ini telah ditarik dan bukan lagi bagian dari riwayat aktif pasien. Dokumennya tetap tersimpan
                sebagai bukti audit, tetapi halamannya tidak dapat dibuka.
                <span class="mt-1 block text-xs">
                    Dibatalkan oleh {{ $record->voidedBy?->name ?? '—' }}
                    pada {{ $record->voided_at?->format('d-m-Y H:i') ?? '—' }}.
                </span>
            </x-ui.alert>
        @endif

        <x-ui.card>
            <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-muted">Pasien</dt>
                    <dd class="mt-1 font-semibold text-navy">{{ $record->patient?->name ?? '—' }}</dd>
                    <dd class="text-xs text-ink-muted">{{ $record->patient?->medical_record_number ?? 'Belum ada RM' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-muted">Tanggal Odontogram</dt>
                    <dd class="mt-1 text-ink">{{ $record->odontogram_date?->format('d-m-Y') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-muted">Cabang</dt>
                    <dd class="mt-1 text-ink">{{ $record->branch?->name ?? 'Tidak dicatat' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-muted">Status</dt>
                    <dd class="mt-1"><x-ui.badge :status="$record->status">{{ $record->status }}</x-ui.badge></dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-muted">Jumlah Halaman</dt>
                    <dd class="mt-1 text-ink">{{ $record->page_count }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-muted">Dipublikasikan Oleh</dt>
                    <dd class="mt-1 text-ink">{{ $record->publishedBy?->name ?? '—' }}</dd>
                    <dd class="text-xs text-ink-muted">{{ $record->published_at?->format('d-m-Y H:i') ?? '—' }}</dd>
                </div>
                @if (filled($record->title))
                    <div class="sm:col-span-2">
                        <dt class="text-xs uppercase tracking-wide text-ink-muted">Judul</dt>
                        <dd class="mt-1 text-ink">{{ $record->title }}</dd>
                    </div>
                @endif
            </dl>
        </x-ui.card>

        @if (filled($record->description))
            <x-ui.card title="Keterangan">
                <p class="text-sm text-ink">{{ $record->description }}</p>
            </x-ui.card>
        @endif

        <x-ui.card title="Halaman Arsip">
            @if ($record->isVoided())
                <x-ui.empty-state
                    title="Halaman tidak tersedia"
                    description="Arsip yang dibatalkan tidak lagi menampilkan halaman dokumennya."
                />
            @elseif ($pages->isEmpty())
                <x-ui.empty-state title="Tidak ada halaman" description="Arsip ini tidak memiliki halaman tersimpan." />
            @else
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($pages as $page)
                        <figure class="overflow-hidden rounded-xl border border-hairline bg-surface">
                            <img
                                src="{{ route('rme.legacy-odontograms.pages.show', [$record->getKey(), $page->page_number]) }}"
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

        @can('void', $record)
            <form
                method="POST"
                action="{{ route('rme.legacy-odontograms.void', $record->getKey()) }}"
                onsubmit="return confirm('Batalkan arsip odontogram lama ini? Tindakan ini tidak dapat dibatalkan.');"
            >
                @csrf
                <x-ui.card
                    title="Batalkan Arsip (VOID)"
                    description="Koreksi dilakukan dengan membatalkan arsip disertai alasan, lalu mengunggah ulang dokumen yang benar. Arsip tidak pernah diubah di tempat dan tidak pernah dihapus."
                >
                    <x-ui.textarea
                        name="void_reason"
                        label="Alasan Pembatalan"
                        rows="3"
                        required
                    >{{ old('void_reason') }}</x-ui.textarea>

                    <x-slot:actions>
                        <x-ui.button type="submit" variant="danger">Batalkan Arsip</x-ui.button>
                    </x-slot:actions>
                </x-ui.card>
            </form>
        @endcan
    </div>
</x-settings-shell>
