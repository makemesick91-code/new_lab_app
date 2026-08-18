{{-- LEGACY-RME-DOCTOR-WORKSPACE-1A — the ACTIVE page of the unified RME page
     sequence when that page comes from a published legacy archive.

     It is rendered in exactly the slot the native handwriting figure occupies,
     so the doctor stays inside the same previous/next/swipe experience and
     never has to open a separate document section.

     READ ONLY BY CONSTRUCTION: there is no canvas, no drawing surface, no
     writable form and no mutating route on this page. The bytes are streamed
     from the policy-gated private route, which re-authorizes every request —
     being rendered here is not an authorization decision.

     No KTP/NIK, no patient name, no storage path, no checksum is rendered. --}}
@php
    $legacyPage = $activeWorkspacePage;
    $legacyDate = $legacyPage->clinicalDate?->translatedFormat('d F Y');
@endphp

<div
    class="mx-auto max-w-3xl"
    x-data="{
        zoom: 1,
        expanded: false,
        failed: false,
        zoomIn() { this.zoom = Math.min(4, Math.round((this.zoom + 0.25) * 100) / 100); },
        zoomOut() { this.zoom = Math.max(0.5, Math.round((this.zoom - 0.25) * 100) / 100); },
        resetZoom() { this.zoom = 1; },
        toggleExpanded() { this.expanded = !this.expanded; if (!this.expanded) { this.resetZoom(); } },
    }"
    x-on:keydown.escape.window="expanded && toggleExpanded()"
>
    <div
        data-rme-legacy-inline-page
        data-legacy-record-id="{{ $legacyPage->legacyRecordId }}"
        data-legacy-pdf-page="{{ $legacyPage->legacyPdfPage }}"
        x-bind:class="expanded
            ? 'fixed inset-0 z-50 flex flex-col rounded-none bg-white p-4'
            : 'rounded-lg border border-warning-200 bg-warning-50/40 p-3'"
    >
        {{-- Identity + read-only status. Stated in TEXT, never colour alone. --}}
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <div class="min-w-0">
                <p class="flex flex-wrap items-center gap-2 text-xs font-semibold uppercase tracking-wide text-warning-700">
                    <span>RME Legacy</span>
                    <span class="rounded border border-warning-300 bg-white px-1.5 py-0.5 text-[11px] font-bold text-warning-700"
                          data-legacy-readonly-badge>Hanya Baca</span>
                </p>
                <p class="mt-1 text-sm font-medium text-gray-800">
                    {{ $legacyDate ?? 'Tanggal arsip tidak tercatat' }}
                </p>
                <p class="text-xs text-gray-600" data-legacy-page-context>
                    Dokumen {{ $legacyPage->legacyDocumentIndex }} &middot; PDF {{ $legacyPage->legacyPageContext() }}
                </p>
            </div>

            {{-- Zoom + fullscreen. Read-only tools only: no draw, no erase, no
                 save, no delete, no replace, no annotation. --}}
            <div class="flex flex-wrap items-center gap-1">
                <div class="flex items-center gap-1" role="group" aria-label="Perbesar dokumen arsip">
                    <button type="button" x-on:click="zoomOut()" data-legacy-zoom-out
                            aria-label="Perkecil tampilan"
                            class="rounded border border-gray-300 bg-white px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-500">
                        &minus; Perkecil
                    </button>
                    <span class="px-1 text-xs tabular-nums text-gray-600"
                          aria-live="polite" data-legacy-zoom-level
                          x-text="Math.round(zoom * 100) + '%'">100%</span>
                    <button type="button" x-on:click="zoomIn()" data-legacy-zoom-in
                            aria-label="Perbesar tampilan"
                            class="rounded border border-gray-300 bg-white px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-500">
                        + Perbesar
                    </button>
                    <button type="button" x-on:click="resetZoom()" data-legacy-zoom-reset
                            aria-label="Kembalikan ukuran asli"
                            class="rounded border border-gray-300 bg-white px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-500">
                        Sesuaikan
                    </button>
                </div>
                <button type="button" x-on:click="toggleExpanded()" data-legacy-fullscreen
                        class="rounded border border-gray-300 bg-white px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <span x-text="expanded ? 'Tutup Layar Penuh' : 'Perbesar Layar Penuh'">Perbesar Layar Penuh</span>
                </button>
            </div>
        </div>

        {{-- The page surface. While zoomed the doctor is PANNING, so the swipe
             navigator stands down (data-ignore-swipe); at fit-width a
             horizontal swipe moves to the next workspace page as usual. --}}
        <div class="overflow-auto rounded border border-gray-300 bg-navy-50 p-2"
             x-bind:class="expanded ? 'flex-1' : ''"
             x-bind:data-ignore-swipe="zoom > 1 ? '' : null"
             data-legacy-page-surface>
            @if ($legacyPage->hasRenderedPage())
                <img src="{{ $legacyPage->legacyPageImageUrl }}"
                     alt="Arsip RME lama, {{ $legacyPage->legacyPageContext() }} (hanya baca)"
                     data-legacy-page-image
                     draggable="false"
                     x-show="!failed"
                     x-on:error="failed = true"
                     x-bind:style="'width:' + (zoom * 100) + '%'"
                     class="mx-auto block h-auto max-w-none select-none rounded bg-white shadow-sm" />
            @endif

            {{-- Fallback: the archive is readable but this page has no rendered
                 image (never rasterised, or the render is missing on disk). The
                 workspace must NOT break — the doctor still gets the evidence
                 through the same private route, and every native page stays
                 usable. --}}
            <div @if ($legacyPage->hasRenderedPage()) x-show="failed" x-cloak @endif
                 data-legacy-page-fallback>
                @if ($legacyPage->legacySourceUrl)
                    <div class="rounded border border-dashed border-gray-300 bg-white p-4 text-center">
                        <p class="text-sm text-gray-700">Gambar halaman arsip ini belum tersedia.</p>
                        <p class="mt-1 text-xs text-gray-500">Dokumen sumber tetap dapat dibuka (hanya baca).</p>
                        <a href="{{ $legacyPage->legacySourceUrl }}" target="_blank" rel="noopener"
                           class="mt-3 inline-flex items-center rounded border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-500">
                            Buka Dokumen Arsip (PDF)
                        </a>
                    </div>
                @else
                    <div class="rounded border border-dashed border-danger-200 bg-white p-4 text-center text-sm text-danger-700">
                        Halaman arsip tidak dapat ditampilkan saat ini.
                    </div>
                @endif
            </div>
        </div>

        <p class="mt-2 text-xs text-gray-500">
            Halaman arsip bersifat permanen dan tidak dapat diubah, ditulisi, atau dihapus dari sini.
            Geser kiri/kanan untuk berpindah halaman.
        </p>
    </div>
</div>
