{{-- LEGACY-RME-DOCTOR-WORKSPACE-1A — THE page navigator for the doctor's RME
     workspace. Extracted so every surface that shows the patient's RME book
     drives the SAME navigation system: the same `?rm_page=` sequence index, the
     same previous/next semantics and the same numbered buttons.

     It walks the UNIFIED sequence — native handwriting pages AND read-only
     legacy archive pages — so a doctor reaches archived evidence with exactly
     the controls they already use for a handwritten page. A legacy page is
     marked by an "L" prefix and a spoken aria-label, never by colour alone.

     Expected: $workspacePages, $activePageNumber, $totalRmPages, $rmPageUrl,
     $prevPage, $nextNavPage, $navBase.
     Optional: $canEditHandwriting (+ $nextRmPageNumber,
     $canonicalHandwritingFormAction) for "+ Tambah Halaman RM", which ALWAYS
     creates a native page and never touches archive evidence. --}}
@php
    $navBase = $navBase ?? 'inline-flex items-center justify-center rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors';
    $canEditHandwriting = $canEditHandwriting ?? false;
@endphp
        <div id="rm-page-nav" class="flex flex-wrap items-center gap-2" data-rm-page-nav-container>
            @if ($activePageNumber > 1)
                <a href="{{ $rmPageUrl($prevPage) }}"
                   data-rm-page-nav
                   class="{{ $navBase }} border-gray-200 bg-white text-gray-700 hover:bg-gray-50">&larr; Sebelumnya</a>
            @else
                <span class="{{ $navBase }} cursor-not-allowed border-gray-100 bg-gray-50 text-gray-300">&larr; Sebelumnya</span>
            @endif

            <span class="text-sm font-medium text-gray-700">Halaman {{ $activePageNumber }} dari {{ $totalRmPages }}</span>

            {{-- LEGACY-RME-DOCTOR-WORKSPACE-1A — the numbered buttons walk
                 the UNIFIED sequence. A legacy page is marked with an "L"
                 prefix and a spoken label, never by colour alone. --}}
            <div class="flex flex-wrap items-center gap-1">
                @foreach ($workspacePages as $workspacePage)
                    @php $pageNo = $workspacePage->workspaceIndex; @endphp
                    <a href="{{ $rmPageUrl($pageNo) }}"
                       data-rm-page-nav
                       data-page-number="{{ $pageNo }}"
                       data-page-type="{{ $workspacePage->type }}"
                       aria-label="{{ $workspacePage->isLegacy()
                           ? 'Halaman '.$pageNo.' — arsip RME lama, hanya baca'
                           : 'Halaman '.$pageNo.' — rekam medis tulisan tangan' }}"
                       @class([
                           $navBase,
                           'border-brand-600 bg-brand-700 text-white' => $pageNo === $activePageNumber,
                           'border-warning-300 bg-warning-50 text-warning-700 hover:bg-warning-100' => $pageNo !== $activePageNumber && $workspacePage->isLegacy(),
                           'border-gray-200 bg-white text-gray-700 hover:bg-gray-50' => $pageNo !== $activePageNumber && $workspacePage->isNative(),
                       ])
                       @if ($pageNo === $activePageNumber) aria-current="page" @endif
                    >{{ $workspacePage->buttonLabel() }}</a>
                @endforeach
            </div>

            @if ($activePageNumber < $totalRmPages)
                <a href="{{ $rmPageUrl($nextNavPage) }}"
                   data-rm-page-nav
                   class="{{ $navBase }} border-gray-200 bg-white text-gray-700 hover:bg-gray-50">Berikutnya &rarr;</a>
            @else
                <span class="{{ $navBase }} cursor-not-allowed border-gray-100 bg-gray-50 text-gray-300">Berikutnya &rarr;</span>
            @endif

            @if ($canEditHandwriting)
                <x-ui.button type="button" variant="secondary" id="add-rm-page-btn"
                             data-next-page="{{ $nextRmPageNumber }}"
                             data-form-action="{{ $canonicalHandwritingFormAction }}"
                             class="!px-3 !py-1.5 !text-xs">
                    + Tambah Halaman RM
                </x-ui.button>
            @endif
        </div>
