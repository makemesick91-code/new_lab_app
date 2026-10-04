{{--
    PR2 §12 counters. Shared by both batch publish views so the markup exists
    once. "Sudah terbit" is split out of "Dipublikasikan" deliberately: a run of
    50 that finds 3 already filed must not read as 53 fresh publications.
--}}
<div class="grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-7">
    <x-ui.kpi-card label="Sudah ditinjau" :value="$counters['reviewed']" />
    <x-ui.kpi-card label="Dapat dipilih (halaman ini)" :value="$counters['selectable_on_page']" />
    <x-ui.kpi-card label="Ditahan peninjau" :value="$counters['blocked_on_page']" />
    <x-ui.kpi-card label="Terpilih" :value="$counters['selected']" />
    <x-ui.kpi-card label="Siap dipublikasikan" :value="$counters['pending']" />
    <x-ui.kpi-card label="Dipublikasikan" :value="$counters['published']" />
    <x-ui.kpi-card label="Ditolak" :value="$counters['refused']" />
</div>

@if (($counters['already_published'] ?? 0) > 0)
    <x-ui.alert variant="info">
        {{ $counters['already_published'] }} dokumen sudah terbit sebelumnya dan tidak
        dipublikasikan ulang. Arsip hanya dibuat satu kali per dokumen.
    </x-ui.alert>
@endif
