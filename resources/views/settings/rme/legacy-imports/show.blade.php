{{--
    LEGACY-RME-PDF-1B — processing status and rendered result of one staged
    document.
    LEGACY-RME-PDF-1C — review-before-publish, and the link to the published
    archive once this import has produced one.

    Review and publish are two separate, separately permissioned actions: the
    1A transition map only allows PUBLISHED from REVIEWED, so an operator must
    have looked at the rendered pages before the archive becomes permanent. The
    buttons below are convenience only — the route middleware, the policy and
    the service each re-check independently.

    Every file link points at the policy-gated streaming route; no storage path
    is ever rendered, and KTP/NIK never appears.
--}}
@php
    $isProcessing = in_array($import->status, ['UPLOADED', 'QUEUED', 'PROCESSING'], true);
    $isReady = $import->status === 'READY_FOR_REVIEW';
    $isReviewed = $import->status === 'REVIEWED';
    $isFailed = $import->status === 'FAILED';
    $isPublished = $import->status === 'PUBLISHED';
    $publishedRecord = $import->record;
@endphp

<x-settings-shell title="Arsip RME Lama">
    <div
        class="space-y-6"
        @if ($isProcessing)
            x-data="{
                statusUrl: '{{ route('settings.rme.legacy-imports.status', $import->getKey()) }}',
                poll() {
                    fetch(this.statusUrl, { headers: { 'Accept': 'application/json' } })
                        .then((response) => response.ok ? response.json() : null)
                        .then((data) => {
                            if (data && (data.is_ready || data.is_failed)) {
                                window.location.reload();
                            }
                        })
                        .catch(() => {});
                },
            }"
            x-init="setInterval(() => poll(), 4000)"
        @endif
    >
        <x-ui.page-header
            title="Arsip RME Lama"
            :subtitle="'Tanggal dokumen: '.($import->selected_rme_date?->format('d-m-Y') ?? '—')"
        >
            <x-slot:breadcrumb>Master Data RME / Impor Arsip RME Lama / Detail</x-slot:breadcrumb>

            <x-slot:actions>
                <x-ui.button variant="secondary" :href="route('settings.rme.legacy-imports.index')">Kembali</x-ui.button>

                @if ($isPublished && $publishedRecord)
                    <x-ui.button variant="primary" :href="route('rme.legacy-records.show', $publishedRecord->getKey())">
                        Lihat Arsip Final
                    </x-ui.button>
                @endif

                @can('review', $import)
                    @if ($isReady && ! $separationBlocksReview)
                        <form method="POST" action="{{ route('settings.rme.legacy-imports.review', $import->getKey()) }}">
                            @csrf
                            <x-ui.button type="submit" variant="primary">Tandai Sudah Ditinjau</x-ui.button>
                        </form>
                    @endif
                @endcan

                @can('retry', $import)
                    @if ($isFailed || $import->status === 'PROCESSING')
                        <form method="POST" action="{{ route('settings.rme.legacy-imports.retry', $import->getKey()) }}">
                            @csrf
                            <x-ui.button type="submit" variant="warning">Proses Ulang</x-ui.button>
                        </form>
                    @endif
                @endcan

                @can('cancel', $import)
                    <form
                        method="POST"
                        action="{{ route('settings.rme.legacy-imports.cancel', $import->getKey()) }}"
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
                    <dt class="text-xs uppercase tracking-wide text-ink-muted">Cabang Asal</dt>
                    <dd class="mt-1 text-ink">{{ $import->originBranch?->name ?? 'Tidak dicatat' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-muted">RME Pertama di Sistem</dt>
                    <dd class="mt-1 text-ink">{{ $import->earliest_native_rme_date_snapshot?->format('d-m-Y') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-muted">Nama Berkas</dt>
                    <dd class="mt-1 break-all text-ink">{{ $import->original_filename ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-muted">Ukuran</dt>
                    <dd class="mt-1 text-ink">
                        {{ $import->size_bytes ? number_format($import->size_bytes / 1024, 0, ',', '.').' KB' : '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-muted">Jumlah Halaman</dt>
                    <dd class="mt-1 text-ink">{{ $import->page_count ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-muted">Durasi Proses</dt>
                    <dd class="mt-1 text-ink">
                        @if ($import->processing_started_at && $import->processing_completed_at)
                            {{ $import->processing_started_at->diffInSeconds($import->processing_completed_at) }} detik
                        @else
                            —
                        @endif
                    </dd>
                </div>
            </dl>
        </x-ui.card>

        @if ($isProcessing)
            <x-ui.alert variant="info" title="Dokumen sedang diproses">
                Pemrosesan berjalan di latar belakang. Halaman ini akan menyegarkan sendiri setelah selesai.
            </x-ui.alert>
        @endif

        @if ($isFailed)
            <x-ui.alert variant="danger" title="Pemrosesan gagal">
                <p>{{ $import->failure_message ?? 'Pemrosesan arsip RME lama gagal.' }}</p>
                @if ($import->failure_code)
                    <p class="mt-1 text-xs uppercase tracking-wide">Kode: {{ $import->failure_code }}</p>
                @endif
            </x-ui.alert>
        @endif

        @if ($isReady)
            <x-ui.alert variant="success" title="Siap ditinjau">
                Dokumen berhasil dirender. Periksa seluruh halaman dan tanggal dokumen di bawah, lalu tandai
                <strong>Sudah Ditinjau</strong>. Arsip ini <strong>belum</strong> menjadi rekam medis final pasien.
            </x-ui.alert>
        @endif

        @if ($isPublished)
            <x-ui.alert variant="success" title="Arsip sudah dipublikasikan">
                Dokumen ini sudah menjadi arsip RME final dan muncul pada riwayat rekam medis pasien.
                Arsip final bersifat permanen — koreksi dilakukan dengan membatalkan (VOID) arsip lalu mengimpor ulang.
            </x-ui.alert>
        @endif

        {{--
            LEGACY-RME-SOD-1 — separation of duties, explained rather than
            silently hidden. This is presentation only: the server refuses the
            same POST twice (lifecycle gate 5, then again under the staging
            row's lock), so removing this block would change what the operator
            SEES and nothing about what they can DO.
        --}}
        @if ($separationBlocksReview || $separationBlocksPublish)
            <x-ui.alert variant="warning" title="Pemisahan tugas (maker/checker)">
                {{ $separationMessage }}
                Mintalah pemeriksa lain yang berwenang untuk meninjau dan menerbitkan dokumen ini.
            </x-ui.alert>
        @endif

        @if ($isReviewed && ! $separationBlocksPublish)
            @can('publish', $import)
                <x-ui.card title="Publikasikan Arsip">
                    <x-ui.alert variant="warning" title="Tindakan permanen">
                        Setelah dipublikasikan, arsip menjadi rekam medis historis pasien yang bersifat
                        <strong>final dan tidak dapat diubah</strong>. Publikasi tidak membuat kunjungan, tagihan,
                        pembayaran, maupun order lab.
                    </x-ui.alert>

                    <form
                        method="POST"
                        action="{{ route('settings.rme.legacy-imports.publish', $import->getKey()) }}"
                        class="mt-4 space-y-4"
                        onsubmit="return confirm('Publikasikan arsip ini ke riwayat rekam medis pasien? Tindakan ini tidak dapat dibatalkan.');"
                    >
                        @csrf

                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-ui.input
                                name="title"
                                label="Judul Arsip"
                                :value="old('title', 'Arsip RME Lama')"
                                maxlength="150"
                            />
                            <x-ui.input
                                name="description"
                                label="Keterangan (opsional)"
                                :value="old('description')"
                                maxlength="2000"
                            />
                        </div>

                        <x-ui.button type="submit" variant="primary">Publikasikan Arsip</x-ui.button>
                    </form>
                </x-ui.card>
            @endcan
        @endif

        <x-ui.card title="Dokumen Sumber">
            <x-ui.button variant="secondary" :href="route('settings.rme.legacy-imports.source', $import->getKey())" target="_blank" rel="noopener">
                Buka PDF Sumber
            </x-ui.button>
        </x-ui.card>

        <x-ui.card :title="'Halaman ('.$pages->count().')'">
            @if ($pages->isEmpty())
                <x-ui.empty-state
                    title="Belum ada halaman"
                    description="Halaman akan muncul setelah pemrosesan selesai."
                />
            @else
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                    @foreach ($pages as $page)
                        <a
                            href="{{ route('settings.rme.legacy-imports.pages.show', [$import->getKey(), $page->page_number]) }}"
                            target="_blank"
                            rel="noopener"
                            class="group block overflow-hidden rounded-lg border border-hairline bg-surface transition hover:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500"
                        >
                            <img
                                src="{{ route('settings.rme.legacy-imports.pages.show', [$import->getKey(), $page->page_number]).'?variant=thumbnail' }}"
                                alt="Pratinjau halaman {{ $page->page_number }}"
                                loading="lazy"
                                class="h-40 w-full bg-navy-50 object-contain"
                            >
                            <div class="flex items-center justify-between px-3 py-2 text-xs">
                                <span class="font-semibold text-navy">Halaman {{ $page->page_number }}</span>
                                <x-ui.badge :status="$page->status">{{ $page->status }}</x-ui.badge>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    </div>
</x-settings-shell>
