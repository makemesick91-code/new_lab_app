{{--
    RME visit workflow navigation — Rekam Medis, Odontogram, Resep Dokter.

    Props:
      $clinicVisit  ClinicVisit
      $active       string  one of: medical-record | odontogram | prescription
--}}
@php
    $active = $active ?? '';
    $tabClass = fn (string $key) => $active === $key
        ? 'border-brand-600 bg-brand-50 text-brand-800'
        : 'border-hairline bg-surface text-ink hover:bg-navy-50';
    $roomLocked = $clinicVisit->requiresRoomBeforeExam();
@endphp
<nav class="flex flex-wrap gap-2" aria-label="Navigasi RME kunjungan">
    @if ($roomLocked)
        <span class="inline-flex items-center rounded-lg border px-4 py-2.5 text-sm font-semibold border-hairline bg-navy-50 text-ink-muted cursor-not-allowed select-none"
              title="Pasien belum ditempatkan ke ruangan perawatan">
            Rekam Medis
        </span>
        <span class="inline-flex items-center rounded-lg border px-4 py-2.5 text-sm font-semibold border-hairline bg-navy-50 text-ink-muted cursor-not-allowed select-none">
            Odontogram
        </span>
        <span class="inline-flex items-center rounded-lg border px-4 py-2.5 text-sm font-semibold border-hairline bg-navy-50 text-ink-muted cursor-not-allowed select-none">
            Resep Dokter
        </span>
    @else
        <a href="{{ route('rme.visits.medical-record.show', $clinicVisit) }}"
           class="inline-flex items-center rounded-lg border px-4 py-2.5 text-sm font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-1 {{ $tabClass('medical-record') }}">
            Rekam Medis
        </a>
        @can('create', [\App\Modules\Odontogram\Models\Odontogram::class, $clinicVisit])
            <a href="{{ route('rme.visits.odontogram.show', $clinicVisit) }}"
               class="inline-flex items-center rounded-lg border px-4 py-2.5 text-sm font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-1 {{ $tabClass('odontogram') }}">
                Odontogram
            </a>
        @endcan
        @can('viewForVisit', [\App\Modules\Prescription\Models\RmePrescription::class, $clinicVisit])
            <a href="{{ route('rme.visits.prescription.show', $clinicVisit) }}"
               class="inline-flex items-center rounded-lg border px-4 py-2.5 text-sm font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-1 {{ $tabClass('prescription') }}">
                Resep Dokter
            </a>
        @endcan
    @endif
</nav>
