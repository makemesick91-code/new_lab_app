<x-settings-shell title="Detail Kunjungan">
    @php
        $statusLabels = [
            'registered'  => 'Terdaftar',
            'waiting'     => 'Menunggu',
            'in_progress' => 'Dalam Pemeriksaan',
            'completed'   => 'Selesai',
            'cancelled'   => 'Dibatalkan',
        ];
        $statusBadge = [
            'registered'  => 'bg-blue-50 text-blue-700',
            'waiting'     => 'bg-amber-50 text-amber-700',
            'in_progress' => 'bg-indigo-50 text-indigo-700',
            'completed'   => 'bg-green-50 text-green-700',
            'cancelled'   => 'bg-red-50 text-red-700',
        ];
        $transitionLabels = [
            'waiting'     => 'Check-in',
            'in_progress' => 'Mulai Pemeriksaan',
            'completed'   => 'Selesaikan',
            'cancelled'   => 'Batalkan',
        ];
        $transitionStyle = [
            'waiting'     => 'bg-amber-600 hover:bg-amber-500',
            'in_progress' => 'bg-indigo-600 hover:bg-indigo-500',
            'completed'   => 'bg-green-600 hover:bg-green-500',
            'cancelled'   => 'bg-red-600 hover:bg-red-500',
        ];
        $validNextStatuses = \App\Modules\ClinicVisit\Models\ClinicVisit::VALID_TRANSITIONS[$visit->status] ?? [];
    @endphp

    <div class="bg-white shadow-sm sm:rounded-lg">
        <div class="p-6 space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">{{ $visit->visit_number }}</h3>
                    <p class="text-sm text-gray-500">Antrian #{{ $visit->queue_number }} &mdash; {{ $visit->visit_date?->format('d/m/Y') }}</p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium {{ $statusBadge[$visit->status] ?? 'bg-gray-100 text-gray-600' }}">
                        {{ $statusLabels[$visit->status] ?? $visit->status }}
                    </span>
                    @can('transition', $visit)
                        @foreach ($validNextStatuses as $nextStatus)
                            <form method="POST" action="{{ route('rme.visits.transition', $visit) }}">
                                @csrf
                                <input type="hidden" name="status" value="{{ $nextStatus }}">
                                <button type="submit"
                                        class="inline-flex items-center rounded-md px-3 py-2 text-sm font-medium text-white {{ $transitionStyle[$nextStatus] ?? 'bg-gray-600 hover:bg-gray-500' }}">
                                    {{ $transitionLabels[$nextStatus] ?? $nextStatus }}
                                </button>
                            </form>
                        @endforeach
                    @endcan
                    @if ($visit->status !== \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_COMPLETED && $visit->status !== \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_CANCELLED)
                        @can('update', $visit)
                            <a href="{{ route('rme.visits.edit', $visit) }}" class="inline-flex items-center rounded-md bg-amber-600 px-3 py-2 text-sm font-medium text-white hover:bg-amber-500">Ubah</a>
                        @endcan
                    @endif
                </div>
            </div>

            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Pasien</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $visit->patient?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Dokter</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $visit->doctor?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Klinik</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $visit->clinic?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Ruangan</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $visit->clinicRoom?->name ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Keluhan Utama</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $visit->chief_complaint ?? '—' }}</dd>
                </div>
                @if ($visit->check_in_at || $visit->started_at || $visit->completed_at || $visit->cancelled_at)
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
                @endif
            </dl>

            @if ($visit->status === \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_COMPLETED)
                <div class="rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">
                    Kunjungan telah selesai, tidak ada aksi perubahan status tersedia.
                </div>
            @elseif ($visit->status === \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_CANCELLED)
                <div class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
                    Kunjungan telah dibatalkan, tidak ada aksi perubahan status tersedia.
                </div>
            @endif

            {{-- Rekam Medis --}}
            @php $medicalRecord = $visit->medicalRecord; @endphp
            <div class="border-t pt-4">
                <h4 class="text-sm font-semibold text-gray-700 mb-3">Rekam Medis</h4>
                @if ($medicalRecord)
                    <div class="flex items-center gap-3">
                        <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium
                            {{ $medicalRecord->status === \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_FINAL ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700' }}">
                            {{ $medicalRecord->status === \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_FINAL ? 'Final' : 'Draft' }}
                        </span>
                        <a href="{{ route('rme.visits.medical-record.show', $visit) }}"
                           class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                            Lihat Rekam Medis
                        </a>
                    </div>
                @else
                    <p class="text-sm text-gray-500 mb-3">Rekam medis belum dibuat.</p>
                    @if ($visit->status !== \App\Modules\ClinicVisit\Models\ClinicVisit::STATUS_CANCELLED)
                        @can('create', [\App\Modules\MedicalRecord\Models\MedicalRecord::class, $visit])
                            <form method="POST" action="{{ route('rme.visits.medical-record.store', $visit) }}">
                                @csrf
                                <button type="submit"
                                        class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                                    Buat Rekam Medis
                                </button>
                            </form>
                        @endcan
                    @endif
                @endif
            </div>

            <div class="border-t pt-4">
                <a href="{{ route('rme.visits.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Kembali ke daftar</a>
            </div>
        </div>
    </div>
</x-settings-shell>
