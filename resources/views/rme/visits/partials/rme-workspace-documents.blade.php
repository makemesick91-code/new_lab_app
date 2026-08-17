{{--
    LEGACY-RME-DOCTOR-WORKSPACE-1 — the patient's RME document rail.

    WHY IT SITS AT THE TOP. The published legacy archive used to be reachable
    only from the clinical-history card at the very bottom of this workspace,
    below the entire multi-page handwriting canvas and its overlay editor. A
    doctor had to scroll past the surface they were writing on to discover the
    patient's old RME existed. This rail puts the same documents — native RM
    sheets and the published legacy archive — one click away, above the fold.

    IT IS A READ SURFACE, NOT A DATA MODEL MERGE. A legacy document is an
    immutable historical archive; it is never converted into a ClinicVisit or a
    MedicalRecord to appear here. Native and legacy stay explicitly typed and
    visually distinct, and the legacy side is labelled read-only in TEXT (never
    by colour alone).

    NO NAVIGATION, NO DATA LOSS. Opening a legacy document opens an in-page
    overlay. The doctor never leaves the workspace, so unsaved handwriting or
    notes are never discarded by reading the archive. Selecting a native sheet
    reloads the workspace exactly as the existing sheet navigation always has —
    this rail introduces no new route and no new selection mechanism.

    NOTHING LOADS UNTIL ASKED. The viewer body lives inside <template x-if>, so
    no page image and no PDF byte is fetched while the workspace renders. A
    patient with many archived documents costs the initial page nothing.

    AUTHORIZATION IS NOT HERE. Every document listed was already resolved under
    the caller's permission, branch scope and doctor clinical scope, and every
    byte is still fetched through the policy-gated streaming routes, which
    re-authorize independently. A hidden button is not a security boundary and
    is never treated as one.

    KTP/NIK never appears here, and no storage path or hash is ever rendered.
--}}
@php
    $workspaceDocuments = $workspaceDocuments ?? collect();
    $legacyDocuments = $workspaceDocuments->filter(fn ($document) => $document->isLegacy())->values();
    $nativeDocuments = $workspaceDocuments->filter(fn ($document) => $document->isNative())->values();
@endphp

@if ($workspaceDocuments->isNotEmpty())
    <x-ui.card title="Dokumen RME Pasien">
        <div x-data="rmeWorkspaceLegacyViewer()" data-rme-workspace-documents>
            <p class="mb-4 text-sm text-ink-muted">
                Pilih dokumen rekam medis pasien ini. RME aktif dapat diisi seperti biasa;
                dokumen <span class="font-medium text-ink-soft">RME Legacy</span> adalah arsip historis
                dan hanya dapat dibaca.
            </p>

            @if ($legacyDocuments->isEmpty())
                <p class="mb-3 text-xs text-ink-muted" data-rme-workspace-no-legacy>
                    Pasien ini belum memiliki arsip RME lama yang dipublikasikan.
                </p>
            @endif

            <div class="flex flex-wrap gap-2" role="group" aria-label="Dokumen RME pasien">
                {{-- Native RM sheets — selected through the workspace's own ?sheet= URL. --}}
                @foreach ($nativeDocuments as $document)
                    @php
                        $isActive = $document->isCurrent;
                    @endphp
                    <a
                        @if ($document->url) href="{{ $document->url }}" @endif
                        data-rme-workspace-document="native"
                        data-rme-workspace-document-id="{{ $document->id }}"
                        @if ($isActive) aria-current="true" @endif
                        class="group inline-flex min-w-[9rem] flex-col rounded-xl border px-3 py-2 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-1
                            {{ $isActive
                                ? 'border-brand-500 bg-brand-50 text-brand-800'
                                : 'border-hairline bg-surface text-ink hover:bg-navy-50' }}"
                        title="{{ $document->detail }}"
                    >
                        <span class="text-[11px] font-semibold uppercase tracking-wide {{ $isActive ? 'text-brand-700' : 'text-ink-muted' }}">
                            RME Aktif
                        </span>
                        <span class="text-sm font-medium">{{ $document->label }}</span>
                        <span class="text-xs {{ $isActive ? 'text-brand-700' : 'text-ink-muted' }}">
                            {{ $document->clinicalDate?->format('d/m/Y') ?? 'Tanggal tidak tersedia' }}
                        </span>
                    </a>
                @endforeach

                {{-- Published legacy archive — opens the read-only overlay viewer. --}}
                @foreach ($legacyDocuments as $document)
                    <button
                        type="button"
                        data-rme-workspace-document="legacy"
                        data-rme-workspace-document-id="{{ $document->id }}"
                        x-on:click="openDocument({
                            id: {{ $document->id }},
                            label: @js($document->label),
                            dateLabel: @js($document->clinicalDate?->format('d/m/Y')),
                            detail: @js($document->detail),
                            pageCount: {{ $document->pageCount }},
                            pageUrlTemplate: @js($document->hasPages() && Route::has('rme.legacy-records.pages.show')
                                ? route('rme.legacy-records.pages.show', [$document->id, '__PAGE__'])
                                : null),
                            sourceUrl: @js(Route::has('rme.legacy-records.source')
                                ? route('rme.legacy-records.source', $document->id)
                                : null),
                            detailUrl: @js($document->url),
                        })"
                        class="inline-flex min-w-[9rem] flex-col rounded-xl border border-warning-700/30 bg-warning-50 px-3 py-2 text-left text-warning-700 transition hover:bg-warning-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-1"
                        title="Arsip RME lama — hanya dapat dibaca"
                    >
                        <span class="text-[11px] font-semibold uppercase tracking-wide">
                            RME Legacy
                        </span>
                        <span class="text-sm font-medium">
                            {{ $document->clinicalDate?->format('d/m/Y') ?? 'Tanggal tidak tersedia' }}
                        </span>
                        <span class="text-xs">
                            {{ $document->detail }} &middot; Hanya Baca
                        </span>
                    </button>
                @endforeach
            </div>

            {{--
                The read-only overlay viewer.

                <template x-if> and not x-show: the body must not exist in the
                DOM until the doctor asks for it, so no archive byte is fetched
                while the workspace loads.
            --}}
            <template x-if="open">
                <div
                    class="fixed inset-0 z-50 flex items-center justify-center bg-navy/70 p-4"
                    x-bind:class="expanded ? 'p-0' : 'p-4'"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="rme-legacy-viewer-title"
                    x-on:keydown.escape.window="closeDocument()"
                    data-rme-legacy-viewer
                >
                    <div
                        class="flex w-full flex-col overflow-hidden bg-surface shadow-card"
                        x-bind:class="expanded ? 'h-full max-w-none rounded-none' : 'max-h-[90vh] max-w-5xl rounded-xl'"
                        x-on:click.outside="closeDocument()"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-hairline px-4 py-3">
                            <div class="min-w-0">
                                <h2 id="rme-legacy-viewer-title" class="truncate text-base font-semibold text-ink">
                                    RME Legacy &mdash; <span x-text="doc.dateLabel || 'Tanggal tidak tersedia'"></span>
                                </h2>
                                <p class="text-xs text-warning-700">
                                    Dokumen historis &mdash; hanya dapat dibaca. Tidak dapat diubah.
                                </p>
                            </div>
                            <button
                                type="button"
                                x-on:click="closeDocument()"
                                x-ref="closeButton"
                                class="rounded-lg border border-hairline px-3 py-1.5 text-sm font-medium text-ink hover:bg-navy-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                                data-rme-legacy-viewer-close
                            >
                                Tutup
                            </button>
                        </div>

                        {{-- Toolbar: zoom, page navigation, expand. --}}
                        <div class="flex flex-wrap items-center gap-2 border-b border-hairline bg-navy-50 px-4 py-2">
                            <div class="flex items-center gap-1" role="group" aria-label="Perbesar dokumen">
                                <button type="button" x-on:click="zoomOut()" data-rme-legacy-zoom-out
                                    class="rounded-lg border border-hairline bg-surface px-2.5 py-1 text-sm font-medium text-ink hover:bg-navy-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                                    &minus; Perkecil
                                </button>
                                <button type="button" x-on:click="zoomIn()" data-rme-legacy-zoom-in
                                    class="rounded-lg border border-hairline bg-surface px-2.5 py-1 text-sm font-medium text-ink hover:bg-navy-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                                    + Perbesar
                                </button>
                                <button type="button" x-on:click="fitWidth()" data-rme-legacy-fit-width
                                    class="rounded-lg border border-hairline bg-surface px-2.5 py-1 text-sm font-medium text-ink hover:bg-navy-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                                    Sesuaikan Lebar
                                </button>
                                <span class="px-1 text-xs text-ink-muted" x-text="Math.round(zoom * 100) + '%'"></span>
                            </div>

                            <template x-if="mode === 'image' && doc.pageCount > 1">
                                <div class="flex items-center gap-1" role="group" aria-label="Navigasi halaman">
                                    <button type="button" x-on:click="prevPage()" x-bind:disabled="page <= 1"
                                        data-rme-legacy-prev-page
                                        class="rounded-lg border border-hairline bg-surface px-2.5 py-1 text-sm font-medium text-ink disabled:cursor-not-allowed disabled:text-ink-muted hover:bg-navy-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                                        &larr; Halaman
                                    </button>
                                    <span class="px-1 text-xs text-ink-soft">
                                        Halaman <span x-text="page"></span> dari <span x-text="doc.pageCount"></span>
                                    </span>
                                    <button type="button" x-on:click="nextPage()" x-bind:disabled="page >= doc.pageCount"
                                        data-rme-legacy-next-page
                                        class="rounded-lg border border-hairline bg-surface px-2.5 py-1 text-sm font-medium text-ink disabled:cursor-not-allowed disabled:text-ink-muted hover:bg-navy-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                                        Halaman &rarr;
                                    </button>
                                </div>
                            </template>

                            <button type="button" x-on:click="toggleExpanded()" data-rme-legacy-expand
                                class="ml-auto rounded-lg border border-hairline bg-surface px-2.5 py-1 text-sm font-medium text-ink hover:bg-navy-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                                <span x-text="expanded ? 'Perkecil Tampilan' : 'Perbesar Layar Penuh'"></span>
                            </button>
                        </div>

                        {{-- Document body. --}}
                        <div class="flex-1 overflow-auto bg-navy-50 p-4" data-rme-legacy-viewer-body>
                            <template x-if="mode === 'image'">
                                <img
                                    x-bind:src="currentPageUrl()"
                                    x-bind:style="'width:' + (zoom * 100) + '%'"
                                    x-on:error="fallBackToPdf()"
                                    alt="Halaman arsip RME lama"
                                    class="mx-auto block max-w-none bg-surface shadow-card"
                                    data-rme-legacy-page-image
                                />
                            </template>

                            {{--
                                Fallback for an archive with no rendered pages
                                (imported without a working rasterizer). The
                                source PDF already streams inline through the
                                same policy-gated route, so the browser's own
                                viewer renders it with no extra dependency.
                            --}}
                            <template x-if="mode === 'pdf' && doc.sourceUrl">
                                <object
                                    x-bind:data="doc.sourceUrl"
                                    type="application/pdf"
                                    class="mx-auto block h-[70vh] w-full bg-surface"
                                    data-rme-legacy-pdf-object
                                >
                                    <p class="p-4 text-sm text-ink-soft">
                                        Pratinjau tidak dapat ditampilkan di peramban ini.
                                        <a x-bind:href="doc.detailUrl" class="font-medium text-brand-700 underline">
                                            Buka halaman arsip
                                        </a>
                                    </p>
                                </object>
                            </template>
                        </div>

                        <div class="border-t border-hairline px-4 py-2 text-xs text-ink-muted">
                            Arsip RME lama bersifat historis dan tidak dapat diubah dari ruang kerja RME.
                            <template x-if="doc.detailUrl">
                                <a x-bind:href="doc.detailUrl"
                                   class="ml-1 font-medium text-brand-700 underline focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                                    Lihat detail arsip
                                </a>
                            </template>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </x-ui.card>

    @once
        @push('scripts')
            <script>
                function rmeWorkspaceLegacyViewer() {
                    return {
                        open: false,
                        expanded: false,
                        zoom: 1,
                        page: 1,
                        mode: 'image',
                        doc: {
                            id: null,
                            label: '',
                            dateLabel: '',
                            detail: '',
                            pageCount: 0,
                            pageUrlTemplate: null,
                            sourceUrl: null,
                            detailUrl: null,
                        },

                        openDocument(document) {
                            this.doc = document;
                            this.page = 1;
                            this.zoom = 1;
                            this.expanded = false;
                            // Rendered pages are preferred; an archive without
                            // them falls straight through to the inline PDF.
                            this.mode = (document.pageUrlTemplate && document.pageCount > 0) ? 'image' : 'pdf';
                            this.open = true;
                            this.$nextTick(() => this.$refs.closeButton?.focus());
                        },

                        closeDocument() {
                            this.open = false;
                            this.expanded = false;
                        },

                        currentPageUrl() {
                            if (!this.doc.pageUrlTemplate) {
                                return '';
                            }

                            return this.doc.pageUrlTemplate.replace('__PAGE__', String(this.page));
                        },

                        // A missing rendered page must not leave a broken image
                        // in a clinical viewer — degrade to the source PDF.
                        fallBackToPdf() {
                            if (this.doc.sourceUrl) {
                                this.mode = 'pdf';
                            }
                        },

                        zoomIn() {
                            this.zoom = Math.min(4, Math.round((this.zoom + 0.25) * 100) / 100);
                        },

                        zoomOut() {
                            this.zoom = Math.max(0.5, Math.round((this.zoom - 0.25) * 100) / 100);
                        },

                        fitWidth() {
                            this.zoom = 1;
                        },

                        toggleExpanded() {
                            this.expanded = !this.expanded;
                        },

                        prevPage() {
                            if (this.page > 1) {
                                this.page -= 1;
                            }
                        },

                        nextPage() {
                            if (this.page < this.doc.pageCount) {
                                this.page += 1;
                            }
                        },
                    };
                }
            </script>
        @endpush
    @endonce
@endif
