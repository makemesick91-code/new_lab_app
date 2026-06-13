<x-settings-shell title="Detail Kunjungan">
    @php
        $statusLabels = [
            'registered'  => 'Terdaftar',
            'waiting'     => 'Menunggu',
            'in_progress' => 'Dalam Pemeriksaan',
            'completed'   => 'Selesai',
            'cancelled'   => 'Dibatalkan',
        ];
        $statusTone = [
            'registered'  => 'info',
            'waiting'     => 'warning',
            'in_progress' => 'primary',
            'completed'   => 'success',
            'cancelled'   => 'danger',
        ];
        $transitionLabels = [
            'waiting'     => 'Check-in',
            'in_progress' => 'Mulai Pemeriksaan',
            'completed'   => 'Selesaikan',
            'cancelled'   => 'Batalkan',
        ];
        $transitionStyle = [
            'waiting'     => 'bg-amber-600 hover:bg-amber-500 focus:ring-amber-500',
            'in_progress' => 'bg-teal-700 hover:bg-teal-600 focus:ring-teal-500',
            'completed'   => 'bg-emerald-600 hover:bg-emerald-500 focus:ring-emerald-500',
            'cancelled'   => 'bg-rose-600 hover:bg-rose-500 focus:ring-rose-500',
        ];
        $validNextStatuses = \App\Modules\ClinicVisit\Models\ClinicVisit::VALID_TRANSITIONS[$visit->status] ?? [];
    @endphp

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Rekam Medis Elektronik</p>
                <div class="mt-1 flex flex-wrap items-center gap-3">
                    <h2 class="text-xl font-semibold text-gray-900">{{ $visit->visit_number }}</h2>
                    <x-ui.badge :tone="$statusTone[$visit->status] ?? 'neutral'">
                        {{ $statusLabels[$visit->status] ?? $visit->status }}
                    </x-ui.badge>
                </div>
                <p class="mt-1 text-sm text-gray-500">Antrian #{{ $visit->queue_number }} &mdash; {{ $visit->visit_date?->format('d/m/Y') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @can('transition', $visit)
                    @foreach ($validNextStatuses as $nextStatus)
                        <form method="POST" action="{{ route('rme.visits.transition', $visit) }}">
                            @csrf
                            <input type="hidden" name="status" value="{{ $nextStatus }}">
                            <button type="submit"
                                    class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold text-white transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 {{ $transitionStyle[$nextStatus] ?? 'bg-gray-600 hover:bg-gray-500 focus:ring-gray-500' }}">
                                {{ $transitionLabels[$nextStatus] ?? $nextStatus }}
                            </button>
                        </form>
                    @endforeach
                @endcan
                @if ($visit->status !== \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_COMPLETED && $visit->status !== \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_CANCELLED)
                    @can('update', $visit)
                        <x-ui.button variant="secondary" :href="route('rme.visits.edit', $visit)">Ubah</x-ui.button>
                    @endcan
                @endif
                @can('print', $visit)
                    <x-ui.button variant="neutral" :href="route('rme.visits.print', $visit)" target="_blank">Cetak RME</x-ui.button>
                @endcan
            </div>
        </div>

        @if ($visit->status === \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_COMPLETED)
            <div class="rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                Kunjungan telah selesai, tidak ada aksi perubahan status tersedia.
            </div>
        @elseif ($visit->status === \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_CANCELLED)
            <div class="rounded-lg border border-rose-100 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                Kunjungan telah dibatalkan, tidak ada aksi perubahan status tersedia.
            </div>
        @endif

        <x-ui.card title="Informasi Kunjungan">
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Pasien</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $visit->patient?->name ?? '—' }}</dd>
                    @if ($visit->patient?->medical_record_number)
                        <dd class="mt-0.5 font-mono text-xs text-gray-500">{{ $visit->patient->medical_record_number }}</dd>
                    @endif
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Dokter</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $visit->doctor?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Klinik/Cabang</dt>
                    <dd class="mt-1 text-sm text-gray-900">
                        @if ($visit->branch)
                            {{ $visit->branch->code }} — {{ $visit->branch->name }}
                        @else
                            {{ $visit->clinic?->name ?? '—' }}
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Ruangan</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $visit->clinicRoom?->name ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Keluhan Utama</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $visit->chief_complaint ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Tindakan Awal</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $visit->initialTreatment?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Catatan Layanan Awal</dt>
                    <dd class="mt-1 text-sm text-gray-900 whitespace-pre-wrap">{{ $visit->initial_service_note ?? '—' }}</dd>
                </div>
            </dl>
        </x-ui.card>

        @if ($visit->check_in_at || $visit->started_at || $visit->completed_at || $visit->cancelled_at)
            <x-ui.card title="Linimasa Kunjungan">
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Check-in</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $visit->check_in_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Mulai Pemeriksaan</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $visit->started_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Selesai</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $visit->completed_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                    </div>
                    @if ($visit->status === \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_CANCELLED && $visit->cancelled_at)
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Dibatalkan</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $visit->cancelled_at->format('d/m/Y H:i') }}</dd>
                        </div>
                    @endif
                </dl>
            </x-ui.card>
        @endif

        {{-- Rekam Medis --}}
        @php $medicalRecord = $visit->medicalRecord; @endphp
        <x-ui.card title="Rekam Medis">
            @if ($medicalRecord)
                <div class="flex flex-wrap items-center gap-3">
                    <x-ui.badge :tone="$medicalRecord->status === \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_FINAL ? 'success' : 'warning'">
                        {{ $medicalRecord->status === \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_FINAL ? 'Final' : 'Draft' }}
                    </x-ui.badge>
                    <x-ui.button variant="primary" :href="route('rme.visits.medical-record.show', $visit)">
                        Lihat Rekam Medis
                    </x-ui.button>
                </div>
            @else
                <p class="text-sm text-gray-500 mb-3">Rekam medis belum dibuat.</p>
                @if ($visit->status !== \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_CANCELLED)
                    @can('create', [\App\Modules\MedicalRecord\Models\MedicalRecord::class, $visit])
                        <form method="POST" action="{{ route('rme.visits.medical-record.store', $visit) }}">
                            @csrf
                            <x-ui.button type="submit" variant="primary">Buat Rekam Medis</x-ui.button>
                        </form>
                    @endcan
                @endif
            @endif
        </x-ui.card>

        {{-- Odontogram --}}
        @can('create', [\App\Modules\Odontogram\Models\Odontogram::class, $visit])
            <x-ui.card title="Odontogram">
                <x-ui.button variant="primary" :href="route('rme.visits.odontogram.show', $visit)">
                    Buka Odontogram
                </x-ui.button>
                <p class="mt-2 text-xs text-gray-500">Placeholder — Odontogram interaktif akan tersedia di Sprint berikutnya.</p>
            </x-ui.card>
        @endcan

        <div>
            <a href="{{ route('rme.visits.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali ke daftar</a>
        </div>
    </div>
</x-settings-shell>
