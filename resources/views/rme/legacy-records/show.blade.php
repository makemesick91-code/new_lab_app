{{--
    LEGACY-RME-PDF-1C — read-only viewer for a PUBLISHED legacy RME record.

    There is deliberately NO edit, delete or republish action: a published
    legacy record is immutable clinical evidence. The only supported correction
    is a VOID plus a fresh import.

    Every file link points at the policy-gated streaming route; no storage path
    is ever rendered, and KTP/NIK never appears.
--}}
<x-settings-shell title="Arsip RME Lama">
    <div class="space-y-6">
        <x-ui.page-header
            title="Arsip RME Lama"
            :subtitle="'Tanggal dokumen: '.($record->rme_date?->format('d-m-Y') ?? '—')"
        >
            <x-slot:breadcrumb>RME / Riwayat Pasien / Arsip RME Lama</x-slot:breadcrumb>

            <x-slot:actions>
                <x-ui.button
                    variant="secondary"
                    :href="route('rme.legacy-records.source', $record->getKey())"
                    target="_blank"
                    rel="noopener"
                >
                    Buka PDF Arsip
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        @if (session('status'))
            <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
        @endif

        @if ($record->isVoided())
            <x-ui.alert variant="danger" title="Arsip dibatalkan (VOID)">
                Arsip ini sudah dibatalkan dan tidak lagi menjadi bagian dari riwayat aktif pasien.
                @if ($record->void_reason)
                    <p class="mt-1">Alasan: {{ $record->void_reason }}</p>
                @endif
            </x-ui.alert>
        @else
            <x-ui.alert variant="info" title="Rekam medis historis">
                Dokumen ini adalah <strong>arsip RME lama</strong> milik pasien, bukan kunjungan pada sistem ini.
                Arsip bersifat final dan hanya dapat dibaca — tidak menghasilkan kunjungan, tagihan, pembayaran,
                maupun order lab.
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
                    <dt class="text-xs uppercase tracking-wide text-ink-muted">Status</dt>
                    <dd class="mt-1"><x-ui.badge :status="$record->status">{{ $record->status }}</x-ui.badge></dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-muted">Judul Arsip</dt>
                    <dd class="mt-1 text-ink">{{ $record->title ?? 'Arsip RME Lama' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-muted">Cabang Asal</dt>
                    <dd class="mt-1 text-ink">{{ $record->originBranch?->name ?? 'Tidak dicatat' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-muted">Jumlah Halaman</dt>
                    <dd class="mt-1 text-ink">{{ $record->page_count }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-muted">Dipublikasikan</dt>
                    <dd class="mt-1 text-ink">{{ $record->published_at?->format('d-m-Y H:i') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-muted">Dipublikasikan Oleh</dt>
                    <dd class="mt-1 text-ink">{{ $record->publishedBy?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-ink-muted">Keterangan</dt>
                    <dd class="mt-1 text-ink">{{ $record->description ?? '—' }}</dd>
                </div>
            </dl>
        </x-ui.card>

        <x-ui.card :title="'Halaman ('.$pages->count().')'">
            @if ($pages->isEmpty())
                <x-ui.empty-state
                    title="Belum ada halaman"
                    description="Arsip ini tidak memiliki halaman hasil render."
                />
            @else
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                    @foreach ($pages as $page)
                        <a
                            href="{{ route('rme.legacy-records.pages.show', [$record->getKey(), $page->page_number]) }}"
                            target="_blank"
                            rel="noopener"
                            class="group block overflow-hidden rounded-lg border border-hairline bg-surface transition hover:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500"
                        >
                            <img
                                src="{{ route('rme.legacy-records.pages.show', [$record->getKey(), $page->page_number]).'?variant=thumbnail' }}"
                                alt="Pratinjau halaman {{ $page->page_number }}"
                                loading="lazy"
                                class="h-40 w-full bg-navy-50 object-contain"
                            >
                            <div class="flex items-center justify-between px-3 py-2 text-xs">
                                <span class="font-semibold text-navy">Halaman {{ $page->page_number }}</span>
                                <span class="text-ink-muted">{{ $page->width }}×{{ $page->height }}</span>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    </div>
</x-settings-shell>
