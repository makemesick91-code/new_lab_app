{{--
    Sprint 64.0 — Patient-Centric RM Workspace sheet navigation.

    One patient = one RM workspace (buku RM); one workspace = many sheets (one per
    visit). This partial renders the swipe/tab/prev-next navigation across the
    patient's RM sheets. Each link points at the canonical workspace URL with
    ?sheet= (active sheet) and carries source_visit_id so "Tambah Lembar RM" keeps
    defaulting to the visit the doctor came from. No KTP/NIK is rendered.

    Expects: $sheets, $activeSheet, $workspaceVisit, $sourceVisit, $addSheetVisit,
             $canEdit (bool).
--}}
@php
    $sheetBase = array_filter(['source_visit_id' => $sourceVisit?->id], fn ($v) => $v !== null);
    $sheetUrl = fn ($sheet) => route('rme.visits.medical-record.show', array_merge([$workspaceVisit, 'sheet' => $sheet->id], $sheetBase));

    $activeIndex = $sheets->search(fn ($s) => $s->id === $activeSheet->id);
    $activeIndex = $activeIndex === false ? 0 : $activeIndex;
    $prevSheet = $activeIndex > 0 ? $sheets[$activeIndex - 1] : null;
    $nextSheet = $activeIndex < $sheets->count() - 1 ? $sheets[$activeIndex + 1] : null;

    $navBtn = 'inline-flex items-center justify-center rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors';
    $patientKey = $workspaceVisit->patient_id;
@endphp

<div
    id="rm-sheet-workspace"
    data-patient="{{ $patientKey }}"
    data-active-sheet="{{ $activeSheet->id }}"
    @if ($prevSheet) data-prev-url="{{ $sheetUrl($prevSheet) }}" @endif
    @if ($nextSheet) data-next-url="{{ $sheetUrl($nextSheet) }}" @endif
>
    <x-ui.card title="Buku RM Pasien">
        <p class="mb-3 text-xs text-gray-500">
            Satu pasien = satu buku RM. Geser ke kiri/kanan (swipe) atau gunakan tombol untuk berpindah lembar.
            Total {{ $sheets->count() }} lembar.
        </p>

        {{-- Sheet tabs / dropdown --}}
        <div class="mb-3 flex flex-wrap items-center gap-1">
            @foreach ($sheets as $i => $sheet)
                @php $isCancelled = $sheet->clinicVisit?->status === \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_CANCELLED; @endphp
                <a href="{{ $sheetUrl($sheet) }}"
                   data-sheet-tab="{{ $sheet->id }}"
                   @class([
                       $navBtn,
                       'border-teal-600 bg-teal-700 text-white' => $sheet->id === $activeSheet->id,
                       'border-gray-200 bg-white text-gray-700 hover:bg-gray-50' => $sheet->id !== $activeSheet->id,
                   ])
                   @if ($sheet->id === $activeSheet->id) aria-current="true" @endif
                   title="{{ $sheet->clinicVisit?->visit_number }} — {{ $sheet->clinicVisit?->visit_date?->format('d/m/Y') }}">
                    Lembar {{ $i + 1 }}@if ($isCancelled) <span class="ml-1 text-[10px]">(batal)</span>@endif
                </a>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                @if ($prevSheet)
                    <a href="{{ $sheetUrl($prevSheet) }}" class="{{ $navBtn }} border-gray-200 bg-white text-gray-700 hover:bg-gray-50">&larr; Lembar Sebelumnya</a>
                @else
                    <span class="{{ $navBtn }} cursor-not-allowed border-gray-100 bg-gray-50 text-gray-300" aria-disabled="true">&larr; Lembar Sebelumnya</span>
                @endif

                <span class="text-sm font-medium text-gray-700">Lembar {{ $activeIndex + 1 }} dari {{ $sheets->count() }}</span>

                @if ($nextSheet)
                    <a href="{{ $sheetUrl($nextSheet) }}" class="{{ $navBtn }} border-gray-200 bg-white text-gray-700 hover:bg-gray-50">Lembar Berikutnya &rarr;</a>
                @else
                    <span class="{{ $navBtn }} cursor-not-allowed border-gray-100 bg-gray-50 text-gray-300" aria-disabled="true">Lembar Berikutnya &rarr;</span>
                @endif
            </div>

            @if ($canEdit && $addSheetVisit)
                <form method="POST" action="{{ route('rme.visits.medical-record.store', $addSheetVisit) }}">
                    @csrf
                    <x-ui.button type="submit" variant="secondary" class="!px-3 !py-1.5 !text-xs">
                        + Tambah Lembar RM (Kunjungan #{{ $addSheetVisit->visit_number }})
                    </x-ui.button>
                </form>
            @endif
        </div>
    </x-ui.card>
</div>

<script>
(function () {
    const ws = document.getElementById('rm-sheet-workspace');
    if (!ws) return;

    // Refresh persistence — remember the active sheet + scroll per patient so a
    // reload returns the doctor to where they were. URL ?sheet= remains the
    // primary, shareable state; this is a best-effort enhancer only.
    const patientKey = 'rmws:' + ws.dataset.patient;
    try {
        sessionStorage.setItem(patientKey + ':sheet', ws.dataset.activeSheet || '');
        const savedScroll = sessionStorage.getItem(patientKey + ':scroll');
        if (savedScroll !== null) {
            window.scrollTo(0, parseInt(savedScroll, 10) || 0);
        }
        window.addEventListener('beforeunload', function () {
            sessionStorage.setItem(patientKey + ':scroll', String(window.scrollY || 0));
        });
    } catch (e) { /* storage unavailable — ignore */ }

    // Swipe left/right to move between sheets (tablet/mobile). Threshold-based,
    // horizontal-dominant gesture only, so vertical scroll is never hijacked.
    const prevUrl = ws.dataset.prevUrl || '';
    const nextUrl = ws.dataset.nextUrl || '';
    let startX = 0, startY = 0, tracking = false;

    ws.addEventListener('touchstart', function (e) {
        const t = e.touches[0];
        startX = t.clientX; startY = t.clientY; tracking = true;
    }, { passive: true });

    ws.addEventListener('touchend', function (e) {
        if (!tracking) return;
        tracking = false;
        const t = e.changedTouches[0];
        const dx = t.clientX - startX;
        const dy = t.clientY - startY;
        if (Math.abs(dx) < 60 || Math.abs(dx) < Math.abs(dy)) return;
        if (dx < 0 && nextUrl) { window.location.href = nextUrl; }
        else if (dx > 0 && prevUrl) { window.location.href = prevUrl; }
    }, { passive: true });
})();
</script>
