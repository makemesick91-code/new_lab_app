{{--
    Prev/next visit arrow navigation (Sprint 59).

    Props:
      $prev      ?ClinicVisit  previous visit for the same patient (older)
      $next      ?ClinicVisit  next visit for the same patient (newer)
      $routeName string        route to open the same page type for a visit
                               (e.g. 'rme.visits.medical-record.show' or
                               'rme.visits.odontogram.show'); the visit is passed
                               as the single route parameter.

    Disabled state is rendered when there is no previous/next visit so the doctor
    sees the boundary instead of a dead link.
--}}
@php
    $prev = $prev ?? null;
    $next = $next ?? null;
@endphp
<div class="flex items-center gap-2" aria-label="Navigasi kunjungan pasien">
    @if ($prev)
        <a href="{{ route($routeName, $prev) }}"
           class="inline-flex items-center gap-1.5 rounded-lg border border-hairline bg-surface px-3 py-2 text-sm font-medium text-ink transition-colors hover:bg-navy-50 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-1"
           title="Kunjungan sebelumnya: {{ $prev->visit_number }} ({{ $prev->visit_date?->format('d/m/Y') }})">
            <span aria-hidden="true">&larr;</span>
            <span>Kunjungan Sebelumnya</span>
        </a>
    @else
        <span class="inline-flex items-center gap-1.5 rounded-lg border border-hairline bg-navy-50 px-3 py-2 text-sm font-medium text-ink-muted cursor-not-allowed select-none"
              aria-disabled="true">
            <span aria-hidden="true">&larr;</span>
            <span>Kunjungan Sebelumnya</span>
        </span>
    @endif

    @if ($next)
        <a href="{{ route($routeName, $next) }}"
           class="inline-flex items-center gap-1.5 rounded-lg border border-hairline bg-surface px-3 py-2 text-sm font-medium text-ink transition-colors hover:bg-navy-50 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-1"
           title="Kunjungan berikutnya: {{ $next->visit_number }} ({{ $next->visit_date?->format('d/m/Y') }})">
            <span>Kunjungan Berikutnya</span>
            <span aria-hidden="true">&rarr;</span>
        </a>
    @else
        <span class="inline-flex items-center gap-1.5 rounded-lg border border-hairline bg-navy-50 px-3 py-2 text-sm font-medium text-ink-muted cursor-not-allowed select-none"
              aria-disabled="true">
            <span>Kunjungan Berikutnya</span>
            <span aria-hidden="true">&rarr;</span>
        </span>
    @endif
</div>
